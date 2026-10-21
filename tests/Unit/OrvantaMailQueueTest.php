<?php

declare(strict_types=1);

use App\Contracts\OrvantaMailBackendInterface;
use App\Repositories\OrvantaMailOutboxRepository;
use App\Services\Orvanta\OrvantaException;
use App\Services\Orvanta\OrvantaMailQueueService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Warteschlange fuer Orvanta-Mails an Exchange-Postfaecher.
 *
 * Spiegelt database/migrations/060_orvanta_mail_outbox.sql (manuell, SQLite)
 * und prueft: Ein kurzer Exchange-Ausfall verhindert das Senden nicht, der
 * Auftrag wird nachgeholt, fachliche Fehler werden nicht eingereiht und ein
 * abgebrochener Versuch wird wieder aufgenommen.
 */

/**
 * Mail-Backend ohne Netz: merkt sich die Versandauftraege und kann auf Wunsch
 * jeden Versand mit einem vorgegebenen Fehler scheitern lassen.
 */
final class FakeOrvantaMailQueueBackend implements OrvantaMailBackendInterface
{
    /** @var list<array{user:string,mail:array<string,mixed>,draft_id:string,change_key:string}> */
    public array $sent = [];

    /** Fehler, der jeden Versand scheitern laesst (null = Versand klappt). */
    public ?OrvantaException $failure = null;

    public function __construct(private readonly string $name = 'exchange')
    {
    }

    public function backendName(): string
    {
        return $this->name;
    }

    /** @return array<string,bool> */
    public function capabilities(): array
    {
        return [self::CAPABILITY_MAIL => true];
    }

    /** @return list<array<string,mixed>> */
    public function folders(string $user): array
    {
        return [];
    }

    /** @return array{id:string,name:string} */
    public function createFolder(string $user, string $parent, string $name): array
    {
        return ['id' => '', 'name' => ''];
    }

    public function markFolderRead(string $user, string $folder): void
    {
    }

    /** @return array<string,mixed> */
    public function folderProperties(string $user, string $folder): array
    {
        return [];
    }

    /** @return array{items:list<array<string,mixed>>,total:int,offset:int,has_more:bool} */
    public function messages(string $user, string $folder, int $offset = 0, int $limit = 50, string $search = ''): array
    {
        return ['items' => [], 'total' => 0, 'offset' => 0, 'has_more' => false];
    }

    /** @return array<string,mixed> */
    public function message(string $user, string $id): array
    {
        return [];
    }

    /** @return array{id:string,subject:string,headers:string,source:string} */
    public function messageHeaders(string $user, string $id): array
    {
        return ['id' => $id, 'subject' => '', 'headers' => '', 'source' => ''];
    }

    /** @param array<string,mixed> $mail */
    public function send(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->sent[] = ['user' => $user, 'mail' => $mail, 'draft_id' => $draftId, 'change_key' => $changeKey];

        return ['id' => 'ex.gesendet.' . count($this->sent)];
    }

    /** @param array<string,mixed> $mail */
    public function saveDraft(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        return ['id' => '', 'change_key' => ''];
    }

    /** @param array<string,mixed> $mail */
    public function respond(string $user, string $id, string $mode, array $mail): array
    {
        return $this->send($user, ['reference' => ['id' => $id, 'mode' => $mode]] + $mail);
    }

    /** @param list<string> $ids */
    public function markRead(string $user, array $ids, bool $read): void
    {
    }

    /** @param list<string> $ids */
    public function flag(string $user, array $ids, bool $flagged): void
    {
    }

    /** @param list<string> $ids */
    public function move(string $user, array $ids, string $folder): void
    {
    }

    /** @param list<string> $ids */
    public function delete(string $user, array $ids, bool $permanent = false): void
    {
    }

    /** @return array{name:string,content_type:string,content:string,size:int} */
    public function attachment(string $user, string $attachmentId): array
    {
        return ['name' => '', 'content_type' => '', 'content' => '', 'size' => 0];
    }

    /** @param array{warning:int,send:int,receive:int}|null $directory */
    public function mailboxUsage(string $user, ?array $directory = null): array
    {
        return [];
    }
}

/**
 * Orvanta-Datenbank mit der Warteschlange
 * (Spiegel von database/migrations/060_orvanta_mail_outbox.sql).
 */
function orvantaMailQueuePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE orvanta_mail_outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_uid VARCHAR(100) NOT NULL,
        backend VARCHAR(20) NOT NULL DEFAULT \'exchange\',
        mailbox VARCHAR(190) NOT NULL DEFAULT \'\',
        from_email VARCHAR(190) NOT NULL DEFAULT \'\',
        kind VARCHAR(20) NOT NULL DEFAULT \'new\',
        subject VARCHAR(255) NOT NULL DEFAULT \'\',
        recipients TEXT NULL,
        payload TEXT NOT NULL,
        draft_id VARCHAR(512) NOT NULL DEFAULT \'\',
        change_key VARCHAR(512) NOT NULL DEFAULT \'\',
        status VARCHAR(20) NOT NULL DEFAULT \'pending\',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NOT NULL DEFAULT \'\',
        next_attempt_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    return $pdo;
}

/**
 * @return array{service:OrvantaMailQueueService,repository:OrvantaMailOutboxRepository,backend:FakeOrvantaMailQueueBackend,pdo:PDO}
 */
function orvantaMailQueueEnv(string $backendName = 'exchange'): array
{
    $pdo = orvantaMailQueuePdo();
    $repository = new OrvantaMailOutboxRepository($pdo);
    $backend = new FakeOrvantaMailQueueBackend($backendName);
    $service = new OrvantaMailQueueService(
        $repository,
        static fn (string $uid, string $email): OrvantaMailBackendInterface => $backend
    );

    return ['service' => $service, 'repository' => $repository, 'backend' => $backend, 'pdo' => $pdo];
}

/** @return array<string,mixed> */
function orvantaMailQueueMail(array $overrides = []): array
{
    return $overrides + [
        'to' => ['eva@hh.example'],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Kurze Störung',
        'body' => '<p>Hallo Eva</p>',
        'html' => true,
        'importance' => 'Normal',
        'attachments' => [],
        'from' => 'jan@hh.example',
        'from_name' => 'Jan Müller',
    ];
}

/** Kurzfristiger Exchange-Ausfall (darf eingereiht werden). */
function orvantaMailQueueOutage(): OrvantaException
{
    return new OrvantaException('Exchange ist nicht erreichbar: Connection refused', 502, null, OrvantaException::EXCHANGE_UNAVAILABLE);
}

/** @return list<array<string,mixed>> */
function orvantaMailQueueRows(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM orvanta_mail_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed> */
function orvantaMailQueueRow(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM orvanta_mail_outbox ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

/**
 * Legt einen fertigen Auftrag direkt in die Warteschlange, ohne dass vorher
 * ein Versand scheitern muss.
 *
 * @param array{repository:OrvantaMailOutboxRepository} $env
 */
function orvantaMailQueueSeed(array $env): void
{
    $env['repository']->enqueue(
        'jan',
        'exchange',
        'jan@hh.example',
        'jan@hh.example',
        'new',
        'Betreff',
        ['eva@hh.example'],
        (string) json_encode(orvantaMailQueueMail()),
        '',
        '',
        '',
        date('Y-m-d H:i:s')
    );
}

Runner::test('Orvanta-Warteschlange: erfolgreicher Versand wird nicht eingereiht', static function (): void {
    $env = orvantaMailQueueEnv();
    $result = $env['service']->send($env['backend'], 'jan', 'jan@hh.example', orvantaMailQueueMail());

    Assert::false($result['queued'], 'Ein erfolgreicher Versand ist nicht in der Warteschlange.');
    Assert::same(1, count($env['backend']->sent));
    Assert::same([], orvantaMailQueueRows($env['pdo']));
});

Runner::test('Orvanta-Warteschlange: Exchange-Ausfall reiht die Nachricht ein und holt sie nach', static function (): void {
    $env = orvantaMailQueueEnv();
    $pdo = $env['pdo'];
    $env['backend']->failure = orvantaMailQueueOutage();

    $result = $env['service']->send($env['backend'], 'jan', 'jan@hh.example', orvantaMailQueueMail([
        'attachments' => [['name' => 'notiz.bin', 'content_type' => 'application/octet-stream', 'content' => "\x00\x01\xFE\xFF"]],
    ]));

    Assert::true($result['queued'], 'Ein Ausfall verhindert das Senden nicht.');
    Assert::same('', $result['id'], 'Ohne Zustellung gibt es keine Exchange-Kennung.');
    Assert::contains('Warteschlange', (string) $result['message']);
    Assert::same(1, count(orvantaMailQueueRows($pdo)));
    $row = orvantaMailQueueRow($pdo);
    Assert::same('pending', $row['status']);
    Assert::same('exchange', $row['backend']);
    Assert::same('jan@hh.example', $row['mailbox']);
    Assert::same('new', $row['kind']);
    Assert::contains('Connection refused', (string) $row['last_error']);
    $stored = json_decode((string) $row['payload'], true);
    Assert::same('base64', $stored['attachments'][0]['encoding'], 'Anhaenge liegen base64-kodiert im Auftrag.');
    Assert::same(['eva@hh.example'], json_decode((string) $row['recipients'], true));

    // Exchange antwortet weiterhin nicht: Der Auftrag bleibt mit Wartezeit liegen.
    Assert::same(0, $env['service']->dispatch());
    Assert::same([], $env['backend']->sent);
    $pending = orvantaMailQueueRow($pdo);
    Assert::same('pending', $pending['status']);
    Assert::same(1, (int) $pending['attempts']);
    Assert::true((int) strtotime((string) $pending['next_attempt_at']) > time(), 'Der nächste Versuch liegt in der Zukunft.');

    // Nach Ablauf der Wartezeit und mit erreichbarem Exchange geht die Mail raus.
    $pdo->exec('UPDATE orvanta_mail_outbox SET next_attempt_at = \'' . date('Y-m-d H:i:s', time() - 10) . '\'');
    $env['backend']->failure = null;
    Assert::same(1, $env['service']->dispatch());

    $sent = orvantaMailQueueRow($pdo);
    Assert::same('sent', $sent['status']);
    Assert::same(2, (int) $sent['attempts']);
    Assert::true($sent['sent_at'] !== null);
    Assert::same(1, count($env['backend']->sent));
    Assert::same("\x00\x01\xFE\xFF", $env['backend']->sent[0]['mail']['attachments'][0]['content'], 'Der Anhang kommt unverändert an.');
    Assert::same('jan@hh.example', $env['backend']->sent[0]['user']);

    // Ein zugestellter Auftrag wird nicht erneut gesendet.
    Assert::same(0, $env['service']->dispatch());
    Assert::same(1, count($env['backend']->sent));
});

Runner::test('Orvanta-Warteschlange: fachliche Fehler werden nicht eingereiht', static function (): void {
    $env = orvantaMailQueueEnv();
    $env['backend']->failure = new OrvantaException('Der Empfänger wurde abgelehnt.', 502);
    $thrown = null;
    try {
        $env['service']->send($env['backend'], 'jan', 'jan@hh.example', orvantaMailQueueMail());
    } catch (OrvantaException $exception) {
        $thrown = $exception;
    }

    Assert::true($thrown instanceof OrvantaException, 'Der Fehler erreicht weiterhin die Oberfläche.');
    Assert::same('Der Empfänger wurde abgelehnt.', $thrown->getMessage());
    Assert::same([], orvantaMailQueueRows($env['pdo']), 'Ein fachlicher Fehler wird nicht wiederholt.');
});

Runner::test('Orvanta-Warteschlange: Proxy-Postfächer nutzen die Warteschlange nicht', static function (): void {
    $env = orvantaMailQueueEnv('proxy');
    $env['backend']->failure = orvantaMailQueueOutage();
    $thrown = null;
    try {
        $env['service']->send($env['backend'], 'jan', 'jan@hh.example', orvantaMailQueueMail());
    } catch (OrvantaException $exception) {
        $thrown = $exception;
    }

    Assert::true($thrown instanceof OrvantaException, 'Die Warteschlange gilt nur für Exchange.');
    Assert::same([], orvantaMailQueueRows($env['pdo']));
});

Runner::test('Orvanta-Warteschlange: Antwort und Weiterleitung behalten den Bezug', static function (): void {
    $env = orvantaMailQueueEnv();
    $env['backend']->failure = orvantaMailQueueOutage();

    $result = $env['service']->respond($env['backend'], 'jan', 'jan@hh.example', 'AAMkAD-1', 'replyall', orvantaMailQueueMail());
    Assert::true($result['queued']);
    $row = orvantaMailQueueRow($env['pdo']);
    Assert::same('replyall', $row['kind']);
    $stored = json_decode((string) $row['payload'], true);
    Assert::same('AAMkAD-1', $stored['reference']['id']);
    Assert::same('replyall', $stored['reference']['mode']);

    $env['backend']->failure = null;
    Assert::same(1, $env['service']->dispatch());
    Assert::same('AAMkAD-1', $env['backend']->sent[0]['mail']['reference']['id']);
    Assert::same('replyall', $env['backend']->sent[0]['mail']['reference']['mode']);
});

Runner::test('Orvanta-Warteschlange: endgültig gescheiterte Aufträge werden nicht wiederholt', static function (): void {
    $env = orvantaMailQueueEnv();
    $pdo = $env['pdo'];
    orvantaMailQueueSeed($env);

    $env['backend']->failure = new OrvantaException('Der Empfänger wurde abgelehnt.', 502);
    Assert::same(0, $env['service']->dispatch());
    $row = orvantaMailQueueRow($pdo);
    Assert::same('failed', $row['status'], 'Ein fachlicher Fehler beendet den Auftrag.');
    Assert::same(1, (int) $row['attempts']);
    Assert::null($row['sent_at']);

    $env['backend']->failure = null;
    Assert::same(0, $env['service']->dispatch());
    Assert::same([], $env['backend']->sent);
});

Runner::test('Orvanta-Warteschlange: nach der Höchstzahl der Versuche ist Schluss', static function (): void {
    $env = orvantaMailQueueEnv();
    $pdo = $env['pdo'];
    orvantaMailQueueSeed($env);
    $pdo->exec('UPDATE orvanta_mail_outbox SET attempts = ' . (OrvantaMailQueueService::MAX_ATTEMPTS - 1));

    $env['backend']->failure = orvantaMailQueueOutage();
    $env['service']->dispatch();
    $row = orvantaMailQueueRow($pdo);
    Assert::same('failed', $row['status']);
    Assert::same(OrvantaMailQueueService::MAX_ATTEMPTS, (int) $row['attempts']);

    $pdo->exec('UPDATE orvanta_mail_outbox SET next_attempt_at = \'' . date('Y-m-d H:i:s', time() - 10) . '\'');
    $env['backend']->failure = null;
    Assert::same(0, $env['service']->dispatch(), 'Ein gescheiterter Auftrag wird nicht mehr versucht.');
});

Runner::test('Orvanta-Warteschlange: abgebrochener Versuch wird wieder aufgenommen', static function (): void {
    $env = orvantaMailQueueEnv();
    $pdo = $env['pdo'];
    orvantaMailQueueSeed($env);
    $pdo->exec('UPDATE orvanta_mail_outbox SET status = \'sending\', attempts = 1, updated_at = \'' . date('Y-m-d H:i:s', time() - 1000) . '\'');

    Assert::same(1, $env['service']->dispatch(), 'Ein abgebrochener Auftrag wird erneut versucht.');
    $row = orvantaMailQueueRow($pdo);
    Assert::same('sent', $row['status']);
    Assert::same(2, (int) $row['attempts']);
});
