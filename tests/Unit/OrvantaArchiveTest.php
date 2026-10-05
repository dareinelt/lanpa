<?php

declare(strict_types=1);

use App\Contracts\ArchiveStorageInterface;
use App\Contracts\ExchangeTransportInterface;
use App\Exceptions\ValidationException;
use App\Repositories\OrvantaArchiveRepository;
use App\Services\Orvanta\EwsXml;
use App\Services\Orvanta\MimeMessageParser;
use App\Services\Orvanta\OrvantaArchiveService;
use App\Services\Orvanta\OrvantaException;
use App\Services\Orvanta\OrvantaExchangeService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * In-Memory-Ablage fuer Archivcontainer mit Fehler- und
 * Korruptions-Injektion (siehe ArchiveStorageInterface).
 */
final class MemoryArchiveStorage implements ArchiveStorageInterface
{
    /** @var array<string,string> */
    public array $files = [];

    public bool $failPut = false;

    /** Byte-Flip beim Zurcklesen (simuliert kaputten Transport/Speicher). */
    public bool $corruptOnGet = false;

    public int $puts = 0;

    public function put(string $uid, string $folder, string $name, string $bytes): void
    {
        if ($this->failPut) {
            throw new RuntimeException('Ablage nicht erreichbar (Testfehler).');
        }
        $this->puts++;
        $this->files[$uid . '/' . $folder . '/' . $name] = $bytes;
    }

    public function get(string $uid, string $folder, string $name): string
    {
        $key = $uid . '/' . $folder . '/' . $name;
        if (!array_key_exists($key, $this->files)) {
            throw new RuntimeException('Datei nicht gefunden: ' . $name);
        }
        $bytes = $this->files[$key];
        if ($this->corruptOnGet && str_starts_with($name, 'chunk-')) {
            $bytes = self::flipByte($bytes);
        }

        return $bytes;
    }

    /** @return list<string> Namen aller gespeicherten Chunk-Dateien. */
    public function chunkFiles(): array
    {
        return array_values(array_filter(array_keys($this->files), static fn (string $key): bool => str_contains($key, '/chunk-')));
    }

    /** Beschaedigt eine gespeicherte Chunk-Datei dauerhaft (ein Byte-Flip). */
    public function corruptStored(string $needle): void
    {
        foreach ($this->files as $key => $bytes) {
            if (str_contains($key, $needle)) {
                $this->files[$key] = self::flipByte($bytes);

                return;
            }
        }
        throw new RuntimeException('Keine Datei zum Beschädigen gefunden: ' . $needle);
    }

    private static function flipByte(string $bytes): string
    {
        // Position 14 liegt in den Deflate-Daten des ersten Datensatzes
        // (4 Byte Magic + 10 Byte gzip-Kopf), nicht in ignorierten Kopffeldern.
        $pos = min(14, strlen($bytes) - 1);
        $bytes[$pos] = chr(ord($bytes[$pos]) ^ 0xFF);

        return $bytes;
    }
}

/**
 * EWS-Fake fuer den Massentest: haelt N alte Nachrichten in einem Ordner und
 * entfernt sie bei DeleteItem tatsaechlich (inkl. Paginierung).
 */
final class BulkArchiveTransport implements ExchangeTransportInterface
{
    /** @var array<string,array{id:string,subject:string,received:int,mime:string,imid:string}> */
    public array $messages = [];

    /** @var list<string> Noch in "Exchange" vorhandene Nachrichten. */
    public array $remaining = [];

    public int $deleteCalls = 0;

    public function __construct(int $count)
    {
        $received = time() - 100 * 86400;
        for ($i = 1; $i <= $count; $i++) {
            $id = 'bulk-' . $i;
            $imid = '<bulk-' . $i . '@example.org>';
            $mime = "From: Absender <absender@example.org>\r\n"
                . "To: Ich <ich@example.org>\r\n"
                . 'Subject: Massentest Nachricht ' . $i . "\r\n"
                . 'Message-ID: ' . $imid . "\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=\"utf-8\"\r\n\r\n"
                . 'Eindeutiger Inhalt token-' . $i . ' ' . str_repeat('Lorem ipsum ', 10 + $i % 40) . "\r\n";
            $this->messages[$id] = ['id' => $id, 'subject' => 'Massentest Nachricht ' . $i, 'received' => $received - $i, 'mime' => $mime, 'imid' => $imid];
            $this->remaining[] = $id;
        }
    }

    public function post(string $url, string $xml, array $options): array
    {
        $body = match (true) {
            str_contains($xml, '<m:GetFolder>') => $this->getFolder(),
            str_contains($xml, '<m:FindFolder') => $this->findFolder(),
            str_contains($xml, '<m:FindItem') => $this->findItem($xml),
            str_contains($xml, '<m:GetItem>') => $this->getItem($xml),
            str_contains($xml, '<m:DeleteItem') => $this->deleteItems($xml),
            default => throw new RuntimeException('Unerwartete EWS-Anfrage im Massentest.'),
        };

        return ['status' => 200, 'body' => $body, 'error' => ''];
    }

    private function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Header/>'
            . '<s:Body xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">' . $body . '</s:Body></s:Envelope>';
    }

    private function getFolder(): string
    {
        return $this->envelope('<m:GetFolderResponse><m:ResponseMessages>'
            . '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="bulk-inbox" ChangeKey="CK"/></t:Folder></m:Folders></m:GetFolderResponseMessage>'
            . '</m:ResponseMessages></m:GetFolderResponse>');
    }

    private function findFolder(): string
    {
        return $this->envelope('<m:FindFolderResponse><m:ResponseMessages><m:FindFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>'
            . '<m:RootFolder TotalItemsInView="1" IncludesLastItemInRange="true"><t:Folders>'
            . '<t:Folder><t:FolderId Id="bulk-inbox" ChangeKey="CK"/><t:ParentFolderId Id="root"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Posteingang</t:DisplayName><t:TotalCount>' . count($this->remaining) . '</t:TotalCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
            . '</t:Folders></m:RootFolder></m:FindFolderResponseMessage></m:ResponseMessages></m:FindFolderResponse>');
    }

    private function findItem(string $xml): string
    {
        $offset = preg_match('/Offset="(\d+)"/', $xml, $m) === 1 ? (int) $m[1] : 0;
        $max = preg_match('/MaxEntriesReturned="(\d+)"/', $xml, $m) === 1 ? (int) $m[1] : 50;
        $slice = array_slice($this->remaining, $offset, $max);
        $out = '';
        foreach ($slice as $id) {
            $message = $this->messages[$id];
            $out .= '<t:Message><t:ItemId Id="' . $id . '" ChangeKey="CK1"/><t:ItemClass>IPM.Note</t:ItemClass><t:Subject>' . EwsXml::escape($message['subject']) . '</t:Subject>'
                . '<t:DateTimeReceived>' . EwsXml::dateTime($message['received']) . '</t:DateTimeReceived><t:Size>' . strlen($message['mime']) . '</t:Size><t:HasAttachments>false</t:HasAttachments>'
                . '<t:ToRecipients><t:Mailbox><t:Name>Ich</t:Name><t:EmailAddress>ich@example.org</t:EmailAddress></t:Mailbox></t:ToRecipients>'
                . '<t:From><t:Mailbox><t:Name>Absender</t:Name><t:EmailAddress>absender@example.org</t:EmailAddress></t:Mailbox></t:From>'
                . '<t:IsRead>true</t:IsRead><t:InternetMessageId>' . EwsXml::escape($message['imid']) . '</t:InternetMessageId></t:Message>';
        }
        $last = $offset + $max >= count($this->remaining) ? 'true' : 'false';

        return $this->envelope('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>'
            . '<m:RootFolder TotalItemsInView="' . count($this->remaining) . '" IncludesLastItemInRange="' . $last . '"><t:Items>' . $out . '</t:Items></m:RootFolder>'
            . '</m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }

    private function getItem(string $xml): string
    {
        $id = preg_match('/<t:ItemId Id="([^"]+)"/', $xml, $m) === 1 ? $m[1] : '';
        $message = $this->messages[$id] ?? null;
        if ($message === null || !in_array($id, $this->remaining, true)) {
            return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Error"><m:MessageText>The specified object was not found in the store.</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
        }
        $mime = str_contains($xml, 'IncludeMimeContent') ? '<t:MimeContent CharacterSet="UTF-8">' . base64_encode($message['mime']) . '</t:MimeContent>' : '';

        return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items>'
            . '<t:Message>' . $mime . '<t:ItemId Id="' . $id . '" ChangeKey="CK1"/><t:Subject>' . EwsXml::escape($message['subject']) . '</t:Subject><t:InternetMessageId>' . EwsXml::escape($message['imid']) . '</t:InternetMessageId></t:Message>'
            . '</m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
    }

    private function deleteItems(string $xml): string
    {
        $this->deleteCalls++;
        preg_match_all('/<t:ItemId Id="([^"]+)"/', $xml, $m);
        $this->remaining = array_values(array_diff($this->remaining, $m[1]));

        return $this->envelope('<m:DeleteItemResponse><m:ResponseMessages><m:DeleteItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:DeleteItemResponseMessage></m:ResponseMessages></m:DeleteItemResponse>');
    }
}

/** SQLite-Abbild der Migration 038 (ENUM -> VARCHAR, AUTO_INCREMENT -> AUTOINCREMENT). */
function orvantaArchivePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE orvanta_archives (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL UNIQUE,
        mailbox VARCHAR(190) NOT NULL,
        storage_folder VARCHAR(190) NOT NULL DEFAULT \'Orvanta-Archiv\',
        format_version INTEGER NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT \'active\',
        size_bytes INTEGER NOT NULL DEFAULT 0,
        message_count INTEGER NOT NULL DEFAULT 0,
        chunk_count INTEGER NOT NULL DEFAULT 0,
        last_successful_run DATETIME NULL,
        last_notice TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE orvanta_archive_folders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        archive_id INTEGER NOT NULL,
        exchange_folder_id VARCHAR(512) NOT NULL,
        folder_hash CHAR(40) NOT NULL,
        parent_id INTEGER NULL,
        name VARCHAR(255) NOT NULL,
        path VARCHAR(1024) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (archive_id, folder_hash)
    )');
    $pdo->exec('CREATE TABLE orvanta_archive_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        archive_id INTEGER NOT NULL,
        folder_id INTEGER NOT NULL,
        exchange_item_id VARCHAR(1024) NOT NULL,
        item_hash CHAR(40) NOT NULL,
        change_key VARCHAR(255) NOT NULL DEFAULT \'\',
        internet_message_id VARCHAR(512) NOT NULL DEFAULT \'\',
        subject VARCHAR(512) NOT NULL DEFAULT \'\',
        from_name VARCHAR(255) NOT NULL DEFAULT \'\',
        from_email VARCHAR(255) NOT NULL DEFAULT \'\',
        recipients TEXT NULL,
        item_date DATETIME NULL,
        kind VARCHAR(10) NOT NULL DEFAULT \'mime\',
        size_bytes INTEGER NOT NULL DEFAULT 0,
        content_hash CHAR(64) NOT NULL DEFAULT \'\',
        chunk_name VARCHAR(100) NOT NULL DEFAULT \'\',
        chunk_offset INTEGER NOT NULL DEFAULT 0,
        chunk_length INTEGER NOT NULL DEFAULT 0,
        has_attachments INTEGER NOT NULL DEFAULT 0,
        attachment_names TEXT NULL,
        search_text TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'pending\',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        committed_at DATETIME NULL,
        UNIQUE (archive_id, item_hash)
    )');
    $pdo->exec('CREATE TABLE orvanta_archive_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        archive_id INTEGER NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'running\',
        locked_until DATETIME NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        last_error TEXT NULL,
        processed_count INTEGER NOT NULL DEFAULT 0,
        committed_count INTEGER NOT NULL DEFAULT 0,
        deleted_count INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    return $pdo;
}

/**
 * Baut den kompletten Archivstapel (SQLite-Journal, In-Memory-Ablage,
 * Demo-Exchange mit aufzeichnendem Transport, feste Testuhr).
 *
 * @param array<string,string> $settings
 * @return array{service:OrvantaArchiveService,repository:OrvantaArchiveRepository,storage:MemoryArchiveStorage,transport:RecordingExchangeTransport,exchange:OrvantaExchangeService,config:\App\Services\Orvanta\OrvantaConfigService,pdo:PDO,clock:object}
 */
function orvantaArchiveSetup(array $settings = [], ?ExchangeTransportInterface $transport = null): array
{
    $parts = orvantaConfig($settings + ['archive_enabled' => '1']);
    $recorder = $transport ?? new RecordingExchangeTransport();
    $exchange = new OrvantaExchangeService($recorder, $parts['config']);
    $pdo = orvantaArchivePdo();
    $repository = new OrvantaArchiveRepository($pdo);
    $storage = new MemoryArchiveStorage();
    $clock = new class {
        public int $time = 0;
    };
    $clock->time = time();
    $service = new OrvantaArchiveService($repository, $parts['config'], $exchange, $storage, new MimeMessageParser(), fn (): int => $clock->time);

    return ['service' => $service, 'repository' => $repository, 'storage' => $storage, 'transport' => $recorder, 'exchange' => $exchange, 'config' => $parts['config'], 'pdo' => $pdo, 'clock' => $clock];
}

/** @return list<string> DeleteItem-SOAP-Anfragen eines RecordingExchangeTransport. */
function orvantaArchiveDeletes(RecordingExchangeTransport $transport): array
{
    return array_values(array_filter($transport->xmls(), static fn (string $xml): bool => str_contains($xml, '<m:DeleteItem')));
}

Runner::test('Archiv-Konfiguration: Standardwerte und Schwellenberechnung', function (): void {
    $off = orvantaConfig()['config'];
    Assert::false($off->archiveEnabled(), 'Archiv muss standardmäßig deaktiviert sein.');
    Assert::same(60, $off->archiveAgeDays());
    Assert::same(50, $off->archiveBatchSize());
    Assert::same(3600, $off->archivePollInterval());
    Assert::same('Orvanta-Archiv', $off->archiveFolder());

    $on = orvantaConfig(['archive_enabled' => '1'])['config'];
    Assert::true($on->archiveEnabled());
    // percent: 80 % einer bekannten Grenze; ohne Grenze keine Schwelle.
    Assert::same(80, $on->archiveThresholdBytes(100));
    Assert::same(0, $on->archiveThresholdBytes(0));
    $mb = orvantaConfig(['archive_enabled' => '1', 'archive_threshold_unit' => 'mb', 'archive_threshold' => '2'])['config'];
    Assert::same(2 * 1024 * 1024, $mb->archiveThresholdBytes(0));
});

Runner::test('Archiv-Konfiguration: Validierung weist unsinnige Werte ab', function (): void {
    $config = orvantaConfig()['config'];
    try {
        $config->save([
            'exchange_enabled' => '1',
            'exchange_host' => 'demo',
            'exchange_auth' => 'negotiate',
            'archive_enabled' => '1',
            'archive_threshold_unit' => 'percent',
            'archive_threshold' => '150',
            'archive_age_days' => '0',
            'archive_folder' => 'a/b',
            'archive_batch_size' => '0',
            'archive_poll_interval' => '5',
        ]);
        Assert::true(false, 'Validierung hätte fehlschlagen müssen.');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
        foreach (['archive_threshold', 'archive_age_days', 'archive_folder', 'archive_batch_size', 'archive_poll_interval'] as $key) {
            Assert::true(isset($errors[$key]), 'Fehler erwartet für ' . $key);
        }
    }
});

Runner::test('Archiv: maybeRun respektiert Aktivierung, Registrierung und Schwelle', function (): void {
    $off = orvantaArchiveSetup(['archive_enabled' => '0']);
    Assert::same('Archivierung ist deaktiviert.', $off['service']->maybeRun('dandre')['reason']);

    $parts = orvantaArchiveSetup();
    Assert::same('Postfach ist nicht registriert.', $parts['service']->maybeRun('dandre')['reason']);

    $high = orvantaArchiveSetup(['archive_threshold_unit' => 'mb', 'archive_threshold' => '10485760']);
    $high['service']->registerMailbox('dandre', 'ich@example.org');
    Assert::same('Schwelle nicht erreicht.', $high['service']->maybeRun('dandre')['reason']);
});

Runner::test('Archiv: Demolauf archiviert alte Nachrichten (Copy-Verify-Commit-Delete)', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $result = $parts['service']->run($archive);
    Assert::true($result['ran']);
    Assert::same(5, $result['processed'], 'Fünf alte Demo-Nachrichten erwartet.');
    Assert::same(5, $result['deleted']);
    Assert::same(0, $result['failed']);

    // Container: genau ein Chunk, beginnt mit der Magic-Kennung, Manifest vorhanden.
    $chunks = $parts['storage']->chunkFiles();
    Assert::same(1, count($chunks));
    Assert::true(str_starts_with($parts['storage']->files[$chunks[0]], OrvantaArchiveService::MAGIC));
    Assert::true(isset($parts['storage']->files['dandre/Orvanta-Archiv/manifest.json']), 'Manifest fehlt.');

    // Journal: alle Positionen 'deleted', Löschung tatsächlich per HardDelete angefordert.
    $statuses = $parts['pdo']->query('SELECT status, COUNT(*) AS n FROM orvanta_archive_items GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    Assert::same(5, (int) ($statuses['deleted'] ?? 0));
    $deletes = orvantaArchiveDeletes($parts['transport']);
    Assert::same(5, count($deletes));
    Assert::contains('HardDelete', $deletes[0]);

    // Lauf protokolliert, Ordnerliste zeigt den Posteingang mit 5 Treffern.
    $job = $parts['repository']->lastFinishedJob((int) $archive['id']);
    Assert::same('completed', (string) $job['status']);
    Assert::same(5, (int) $job['deleted_count']);
    $folders = $parts['service']->folders('dandre');
    Assert::same(1, count($folders));
    Assert::same(5, $folders[0]['total']);

    // Status fasst die Zahlen zusammen.
    $status = $parts['service']->status('dandre');
    Assert::same(5, (int) $status['message_count']);
});

Runner::test('Archiv: Stichtag (Mindestalter) steuert die Kandidatenauswahl', function (): void {
    $parts = orvantaArchiveSetup(['archive_age_days' => '100']);
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $result = $parts['service']->run($archive);
    // Nur die Demo-Nachrichten mit 400/250/120 Tagen sind älter als 100 Tage.
    Assert::same(3, $result['processed']);

    // Die EWS-Restriction enthält exakt den berechneten Stichtag.
    $cutoff = EwsXml::dateTime($parts['clock']->time - 100 * 86400);
    $findItem = array_values(array_filter($parts['transport']->xmls(), static fn (string $xml): bool => str_contains($xml, 'IsLessThanOrEqualTo')));
    Assert::true($findItem !== []);
    Assert::contains('<t:Constant Value="' . $cutoff . '"', $findItem[0]);
});

Runner::test('Archiv: zweiter Lauf ist idempotent (Dedupe über InternetMessageId)', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $parts['service']->run($archive);
    $again = $parts['service']->run($archive);
    Assert::same(0, $again['processed'], 'Bereits archivierte Nachrichten dürfen nicht erneut abgelegt werden.');
    Assert::same(5, (int) $parts['pdo']->query('SELECT COUNT(*) FROM orvanta_archive_items')->fetchColumn());
});

Runner::test('Archiv: Upload-Fehler löscht nichts und wird im nächsten Lauf aufgeholt', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $parts['storage']->failPut = true;
    $result = $parts['service']->run($archive);
    Assert::true($result['ran']);
    Assert::true($result['failed'] > 0);
    Assert::same(0, count(orvantaArchiveDeletes($parts['transport'])), 'Ohne verifizierten Upload darf nichts gelöscht werden.');
    Assert::same(0, (int) $parts['pdo']->query('SELECT COUNT(*) FROM orvanta_archive_items WHERE status IN (\'committed\',\'deleted\')')->fetchColumn());

    // Wiederaufnahme: gleicher Bestand, keine Duplikate, alles nachgeholt.
    $parts['storage']->failPut = false;
    $retry = $parts['service']->run($archive);
    Assert::same(5, $retry['processed'] + 0);
    Assert::same(5, $retry['deleted']);
    Assert::same(5, (int) $parts['pdo']->query('SELECT COUNT(*) FROM orvanta_archive_items')->fetchColumn());
});

Runner::test('Archiv: Verifikationsfehler beim Zurücklesen verhindert jede Löschung', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $parts['storage']->corruptOnGet = true;
    $result = $parts['service']->run($archive);
    Assert::true($result['ran']);
    Assert::contains('nichts gelöscht', $result['reason']);
    Assert::same(0, count(orvantaArchiveDeletes($parts['transport'])));
    Assert::same(0, (int) $parts['pdo']->query('SELECT COUNT(*) FROM orvanta_archive_items WHERE status = \'deleted\'')->fetchColumn());
    $job = $parts['repository']->lastFinishedJob((int) $archive['id']);
    Assert::same('failed', (string) $job['status']);
});

Runner::test('Archiv: committete, ungelöschte Nachricht wird im nächsten Lauf gelöscht', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $folderId = $parts['repository']->ensureFolder((int) $archive['id'], 'demo-inbox', null, 'Posteingang', 'Posteingang');
    $imid = '<demo-old-2@example.org>';
    $id = $parts['repository']->insertItem([
        'archive_id' => (int) $archive['id'], 'folder_id' => $folderId, 'exchange_item_id' => 'demo-old-2',
        'item_hash' => sha1('imid:' . $imid), 'change_key' => 'CK1', 'internet_message_id' => $imid,
        'subject' => 'Altes Wartungsprotokoll USV', 'from_name' => 'IT-Service', 'from_email' => 'it-service@example.org',
        'recipients' => '', 'item_date' => gmdate('Y-m-d H:i:s'), 'kind' => 'mime', 'size_bytes' => 10,
        'content_hash' => hash('sha256', 'x'), 'chunk_name' => 'chunk-ff.ova', 'chunk_offset' => 4, 'chunk_length' => 10,
        'has_attachments' => 0, 'attachment_names' => '', 'search_text' => '',
    ]);
    $parts['repository']->commitItems([$id], gmdate('Y-m-d H:i:s'));

    $result = $parts['service']->run($archive);
    $row = $parts['repository']->findItem((int) $archive['id'], $id);
    Assert::same('deleted', (string) $row['status'], 'Committete Nachricht muss nachgelöscht werden.');
    Assert::same(4, $result['processed'], 'Die vorab committete Nachricht darf nicht erneut abgelegt werden.');
});

Runner::test('Archiv: abweichende Identität verhindert die Löschung in Exchange', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $folderId = $parts['repository']->ensureFolder((int) $archive['id'], 'demo-inbox', null, 'Posteingang', 'Posteingang');
    $imid = '<voellig-andere-nachricht@example.org>';
    $id = $parts['repository']->insertItem([
        'archive_id' => (int) $archive['id'], 'folder_id' => $folderId, 'exchange_item_id' => 'demo-old-1',
        'item_hash' => sha1('imid:' . $imid), 'change_key' => 'CK1', 'internet_message_id' => $imid,
        'subject' => 'Fremde Nachricht', 'from_name' => '', 'from_email' => '', 'recipients' => '',
        'item_date' => gmdate('Y-m-d H:i:s'), 'kind' => 'mime', 'size_bytes' => 10,
        'content_hash' => hash('sha256', 'x'), 'chunk_name' => 'chunk-ff.ova', 'chunk_offset' => 4, 'chunk_length' => 10,
        'has_attachments' => 0, 'attachment_names' => '', 'search_text' => '',
    ]);
    $parts['repository']->commitItems([$id], gmdate('Y-m-d H:i:s'));

    $parts['service']->run($archive);
    $row = $parts['repository']->findItem((int) $archive['id'], $id);
    Assert::same('committed', (string) $row['status'], 'Bei Identitätsabweichung darf niemals gelöscht werden.');
});

Runner::test('Archiv: Sperre verhindert parallele Läufe, abgelaufene Sperren werden übernommen', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $insert = $parts['pdo']->prepare('INSERT INTO orvanta_archive_jobs (archive_id, status, locked_until, started_at) VALUES (:a, \'running\', :l, :s)');
    $insert->execute(['a' => (int) $archive['id'], 'l' => gmdate('Y-m-d H:i:s', time() + 600), 's' => gmdate('Y-m-d H:i:s')]);
    $blocked = $parts['service']->run($archive);
    Assert::false($blocked['ran']);
    Assert::contains('bereits eine Archivierung', $blocked['reason']);

    // Abgelaufene Sperre (Absturz): Übernahme, alter Job wird als fehlgeschlagen markiert.
    $parts['pdo']->exec('UPDATE orvanta_archive_jobs SET locked_until = \'' . gmdate('Y-m-d H:i:s', time() - 600) . '\'');
    $taken = $parts['service']->run($archive);
    Assert::true($taken['ran']);
    Assert::same('failed', (string) $parts['pdo']->query('SELECT status FROM orvanta_archive_jobs ORDER BY id LIMIT 1')->fetchColumn());
});

Runner::test('Archiv: Lesepfad liefert Nachricht, Suche und sperrt fremde Konten aus', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $parts['service']->run($archive);
    $itemId = (int) $parts['pdo']->query('SELECT id FROM orvanta_archive_items WHERE subject LIKE \'Jahresabschluss%\'')->fetchColumn();

    $message = $parts['service']->message('dandre', $itemId);
    Assert::true($message['archived']);
    Assert::same('Jahresabschluss Vorjahr – finale Unterlagen', $message['subject']);
    Assert::contains('Jahresabschluss', (string) $message['body_html']);
    Assert::true($message['blocked_images'] >= 1, 'Externe Bilder müssen auch im Archiv blockiert werden.');

    // Suche über den Index (Betreff/Absender), ohne Containerzugriff.
    $hits = $parts['service']->search('dandre', 'Rechnung 2024-1874');
    Assert::same(1, count($hits));
    Assert::same('Posteingang', (string) $hits[0]['folder_path']);
    Assert::same(0, count($parts['service']->search('dandre', 'gibtesnicht-xyzzy')));

    // Fremde Kennung: kein Zugriff.
    try {
        $parts['service']->message('jemand-anderes', $itemId);
        Assert::true(false, 'Fremder Zugriff hätte scheitern müssen.');
    } catch (OrvantaException $exception) {
        Assert::same(404, $exception->status());
    }
});

Runner::test('Archiv: beschädigter Container wird beim Lesen und bei verify() erkannt', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $parts['service']->run($archive);
    Assert::true($parts['service']->verify('dandre')['ok']);

    $parts['storage']->corruptStored('chunk-');
    $verify = $parts['service']->verify('dandre');
    Assert::false($verify['ok'], 'Byte-Flip muss von verify() erkannt werden.');

    $itemId = (int) $parts['pdo']->query('SELECT id FROM orvanta_archive_items ORDER BY id LIMIT 1')->fetchColumn();
    try {
        $parts['service']->message('dandre', $itemId);
        Assert::true(false, 'Integritätsfehler hätte erkannt werden müssen.');
    } catch (OrvantaException $exception) {
        Assert::same(OrvantaArchiveService::INTEGRITY_ERROR, $exception->getMessage());
    }
});

Runner::test('Archiv: kleine Batches erzeugen mehrere Container, alles wird archiviert', function (): void {
    $parts = orvantaArchiveSetup(['archive_batch_size' => '2']);
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $result = $parts['service']->run($archive);
    Assert::same(5, $result['processed']);
    Assert::same(5, $result['deleted']);
    Assert::same(3, count($parts['storage']->chunkFiles()), 'Bei Batchgröße 2 entstehen drei Container für fünf Nachrichten.');
    Assert::true($parts['service']->verify('dandre')['ok']);
});

Runner::test('Archiv: Massentest - 1000 Nachrichten verlustfrei archiviert und verifiziert', function (): void {
    $bulk = new BulkArchiveTransport(1000);
    $parts = orvantaArchiveSetup(['archive_batch_size' => '100'], $bulk);
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $result = $parts['service']->run($archive);
    Assert::same(1000, $result['processed']);
    Assert::same(1000, $result['deleted']);
    Assert::same(0, count($bulk->remaining), 'Alle Nachrichten müssen aus Exchange entfernt sein.');
    Assert::same(1000, (int) $parts['pdo']->query('SELECT COUNT(*) FROM orvanta_archive_items WHERE status = \'deleted\'')->fetchColumn());

    // Jede Prüfsumme im Journal entspricht dem Original aus "Exchange".
    foreach ($bulk->messages as $id => $message) {
        $hash = $parts['pdo']->query('SELECT content_hash FROM orvanta_archive_items WHERE exchange_item_id = ' . $parts['pdo']->quote($id))->fetchColumn();
        Assert::same(hash('sha256', $message['mime']), (string) $hash, 'Prüfsummen-Abweichung bei ' . $id);
    }

    // Vollständige Verifikation aller Container plus Stichprobe über den Lesepfad.
    $verify = $parts['service']->verify('dandre');
    Assert::true($verify['ok']);
    Assert::same(1000, $verify['items']);
    foreach ([1, 250, 777, 1000] as $i) {
        $itemId = (int) $parts['pdo']->query('SELECT id FROM orvanta_archive_items WHERE exchange_item_id = \'bulk-' . $i . '\'')->fetchColumn();
        Assert::contains('token-' . $i . ' ', (string) $parts['service']->message('dandre', $itemId)['body_html']);
    }

    // Ein einziger Byte-Flip in irgendeinem Container fällt bei verify() auf.
    $parts['storage']->corruptStored('chunk-');
    Assert::false($parts['service']->verify('dandre')['ok']);
});

/** Dekorierender Transport: veraendert die Antwort eines inneren Transports. */
final class TamperingArchiveTransport implements ExchangeTransportInterface
{
    /** @param Closure(string, string): string $tamper erhaelt Anfrage-XML und Antwort-Body */
    public function __construct(private ExchangeTransportInterface $inner, private Closure $tamper)
    {
    }

    public function post(string $url, string $xml, array $options): array
    {
        $response = $this->inner->post($url, $xml, $options);
        $response['body'] = ($this->tamper)($xml, (string) $response['body']);

        return $response;
    }
}

Runner::test('Archiv: ohne MIME-Quelltext wird nichts abgelegt und nichts gelöscht', function (): void {
    $bulk = new BulkArchiveTransport(3);
    $transport = new TamperingArchiveTransport($bulk, static fn (string $xml, string $body): string => str_contains($xml, '<m:GetItem>')
        ? (string) preg_replace('#<t:MimeContent[^>]*>.*?</t:MimeContent>#s', '', $body)
        : $body);
    $parts = orvantaArchiveSetup([], $transport);
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $result = $parts['service']->run($archive);
    Assert::true($result['ran']);
    Assert::same(3, $result['failed'], 'Jede Nachricht ohne MIME muss als fehlgeschlagen zählen.');
    Assert::same(0, $result['deleted']);
    Assert::same(0, $bulk->deleteCalls, 'Ohne vollständigen Inhalt darf in Exchange nichts gelöscht werden.');
    Assert::same(3, count($bulk->remaining));
    Assert::same([], $parts['storage']->chunkFiles(), 'Kein Container ohne echte Nutzlast.');
    Assert::same(3, (int) $parts['pdo']->query('SELECT COUNT(*) FROM orvanta_archive_items WHERE status = \'failed\'')->fetchColumn());
    Assert::same(0, (int) $parts['pdo']->query('SELECT COUNT(*) FROM orvanta_archive_items WHERE kind = \'json\'')->fetchColumn(), 'Kein JSON-Ersatz ohne Body und Anhänge.');
    Assert::same(0, (int) $parts['service']->status('dandre')['message_count']);
});

Runner::test('Archiv: Ordnerhierarchie aus Exchange bleibt erhalten (parent_id und Pfad)', function (): void {
    $bulk = new BulkArchiveTransport(2);
    $transport = new TamperingArchiveTransport($bulk, static function (string $xml, string $body): string {
        if (str_contains($xml, '<m:FindFolder')) {
            // Posteingang > Projekte > Rechnungen; die Nachrichten liegen in „Rechnungen“ (bulk-inbox).
            return (string) preg_replace('#<t:Folders>.*</t:Folders>#s', '<t:Folders>'
                . '<t:Folder><t:FolderId Id="bulk-inbox" ChangeKey="CK"/><t:ParentFolderId Id="bulk-projekte"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Rechnungen</t:DisplayName><t:TotalCount>2</t:TotalCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
                . '<t:Folder><t:FolderId Id="bulk-top" ChangeKey="CK"/><t:ParentFolderId Id="root"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Posteingang</t:DisplayName><t:TotalCount>0</t:TotalCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
                . '<t:Folder><t:FolderId Id="bulk-projekte" ChangeKey="CK"/><t:ParentFolderId Id="bulk-top"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>Projekte</t:DisplayName><t:TotalCount>0</t:TotalCount><t:UnreadCount>0</t:UnreadCount></t:Folder>'
                . '</t:Folders>', $body);
        }
        if (str_contains($xml, '<m:FindItem') && !str_contains($xml, 'Id="bulk-inbox"')) {
            return (string) preg_replace('#<t:Items>.*</t:Items>#s', '<t:Items/>', (string) preg_replace('/IncludesLastItemInRange="false"/', 'IncludesLastItemInRange="true"', $body));
        }

        return $body;
    });
    $parts = orvantaArchiveSetup([], $transport);
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $result = $parts['service']->run($archive);
    Assert::same(2, $result['processed']);

    $rows = $parts['pdo']->query('SELECT exchange_folder_id, parent_id, path FROM orvanta_archive_folders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $byExchangeId = array_column($rows, null, 'exchange_folder_id');
    Assert::same('Posteingang', $byExchangeId['bulk-top']['path']);
    Assert::null($byExchangeId['bulk-top']['parent_id']);
    Assert::same('Posteingang/Projekte', $byExchangeId['bulk-projekte']['path']);
    Assert::same('Posteingang/Projekte/Rechnungen', $byExchangeId['bulk-inbox']['path']);
    $ids = $parts['pdo']->query('SELECT exchange_folder_id, id FROM orvanta_archive_folders')->fetchAll(PDO::FETCH_KEY_PAIR);
    Assert::same((int) $ids['bulk-projekte'], (int) $byExchangeId['bulk-inbox']['parent_id']);
    Assert::same((int) $ids['bulk-top'], (int) $byExchangeId['bulk-projekte']['parent_id']);
});

Runner::test('Archiv: Demo-Nachrichten mit Anhängen werden samt Anhängen archiviert', function (): void {
    $parts = orvantaArchiveSetup();
    $archive = $parts['service']->registerMailbox('dandre', 'ich@example.org');
    $parts['service']->run($archive);
    $itemId = (int) $parts['pdo']->query('SELECT id FROM orvanta_archive_items WHERE has_attachments = 1 ORDER BY id LIMIT 1')->fetchColumn();
    Assert::true($itemId > 0, 'Mindestens eine archivierte Demo-Nachricht mit Anhang erwartet.');
    $message = $parts['service']->message('dandre', $itemId);
    $names = array_column($message['attachments'], 'name');
    Assert::contains('Lageplan.pdf', implode(',', $names));
    $pdfIndex = (int) array_search('Lageplan.pdf', $names, true);
    $attachment = $parts['service']->attachment('dandre', $itemId, $pdfIndex);
    Assert::true(str_starts_with($attachment['content'], '%PDF-'), 'Anhang muss byteidentisch aus dem Container kommen.');
    Assert::contains('Lageplan.pdf', (string) $parts['pdo']->query('SELECT attachment_names FROM orvanta_archive_items WHERE id = ' . $itemId)->fetchColumn());
});
