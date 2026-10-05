<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\ArchiveStorageInterface;
use App\Repositories\OrvantaArchiveRepository;

/**
 * Orvanta-Langzeitarchiv: verschiebt alte E-Mails (Mindestalter konfigurierbar,
 * Standard 60 Tage) richtliniengesteuert aus Exchange in komprimierte,
 * integritaetsgesicherte Archivcontainer im Nextcloud-Bereich des Benutzers.
 *
 * Sicherheitsmodell (niemals Datenverlust):
 *   1. Kopieren   MIME-Inhalt aus Exchange laden, Datensatz 'pending' mit
 *                 Fundstelle (Chunk, Offset, Laenge, SHA-256) anlegen.
 *   2. Verifizieren  Chunk hochladen, zurcklesen, Chunk-Pruefsumme und jede
 *                 Datensatz-Pruefsumme gegen die Originalbytes pruefen.
 *   3. Commit     Datensaetze transaktional auf 'committed' setzen - erst
 *                 dieser dauerhafte Zustand erlaubt eine Loeschung.
 *   4. Loeschen   Identitaet (InternetMessageId) erneut pruefen, dann
 *                 HardDelete; Erfolg => 'deleted'.
 * Bricht der Prozess an beliebiger Stelle ab, bleibt die Nachricht entweder
 * unveraendert in Exchange ('pending' wird neu eingeplant) oder sie ist
 * nachweislich im Archiv ('committed' => Loeschung wird nachgeholt). Eine
 * Loeschung ohne verifizierten Commit gibt es nicht.
 *
 * Containerformat (format_version 1): Datei "chunk-<16 hex>.ova" =
 * Magic "OVA1" + aneinandergereihte Datensaetze, jeder Datensatz ist der
 * gzip-komprimierte Original-MIME-Inhalt (bzw. JSON-Fallback). Der Index
 * (Fundstellen, Pruefsummen, Metadaten) liegt in der Datenbank; manifest.json
 * im Archivordner dient nur der Transparenz.
 */
final class OrvantaArchiveService
{
    public const FORMAT_VERSION = 1;
    public const MAGIC = 'OVA1';
    /** Maximale Chunk-Groesse (Nextcloud-Upload-Limit 16 MiB, mit Reserve). */
    public const MAX_CHUNK_BYTES = 15000000;
    public const INTEGRITY_ERROR = 'Archivdaten beschädigt: Die Integritätsprüfung ist fehlgeschlagen.';

    /** @var callable():int */
    private $now;

    public function __construct(
        private readonly OrvantaArchiveRepository $repository,
        private readonly OrvantaConfigService $config,
        private readonly OrvantaExchangeService $exchange,
        private readonly ArchiveStorageInterface $storage,
        private readonly MimeMessageParser $parser = new MimeMessageParser(),
        ?callable $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    // ------------------------------------------------------------------ Registrierung und Status

    /**
     * Registriert das Postfach fuer die Hintergrund-Archivierung (wird beim
     * Oeffnen von Orvanta aufgerufen; danach arbeitet der Worker unabhaengig
     * von einer geoeffneten Oberflaeche).
     *
     * @return array<string,mixed> Archivdatensatz
     */
    public function registerMailbox(string $uid, string $mailbox): array
    {
        return $this->repository->ensureArchive($uid, $mailbox, $this->config->archiveFolder(), self::FORMAT_VERSION);
    }

    /**
     * Archivstatus fuer die Oberflaeche.
     *
     * @return array<string,mixed>
     */
    public function status(string $uid): array
    {
        $archive = $this->repository->findArchive($uid);
        if ($archive === null) {
            return ['enabled' => $this->config->archiveEnabled(), 'exists' => false];
        }
        $job = $this->repository->lastFinishedJob((int) $archive['id']);

        return [
            'enabled' => $this->config->archiveEnabled(),
            'exists' => true,
            'status' => (string) $archive['status'],
            'notice' => $archive['last_notice'] !== null ? (string) $archive['last_notice'] : '',
            'message_count' => (int) $archive['message_count'],
            'size_bytes' => (int) $archive['size_bytes'],
            'chunk_count' => (int) $archive['chunk_count'],
            'last_successful_run' => $archive['last_successful_run'] !== null ? (string) $archive['last_successful_run'] : '',
            'running' => $this->repository->hasRunningJob((int) $archive['id'], $this->timestamp()),
            'last_job' => $job === null ? null : [
                'id' => (int) $job['id'],
                'status' => (string) $job['status'],
                'finished_at' => $job['finished_at'] !== null ? (string) $job['finished_at'] : '',
                'deleted_count' => (int) $job['deleted_count'],
                'error' => $job['last_error'] !== null ? (string) $job['last_error'] : '',
            ],
        ];
    }

    // ------------------------------------------------------------------ Archivierungslauf

    /**
     * Prueft die Archivierungsrichtlinie fuer ein registriertes Postfach und
     * startet bei Ueberschreiten der Schwelle einen Lauf. $force uebergeht
     * die Schwellenpruefung (nicht die Aktivierung und nicht das Mindestalter).
     *
     * @return array{ran:bool,reason:string,processed:int,deleted:int,failed:int}
     */
    public function maybeRun(string $uid, bool $force = false): array
    {
        $none = static fn (string $reason): array => ['ran' => false, 'reason' => $reason, 'processed' => 0, 'deleted' => 0, 'failed' => 0];
        if (!$this->config->archiveEnabled()) {
            return $none('Archivierung ist deaktiviert.');
        }
        $archive = $this->repository->findArchive($uid);
        if ($archive === null) {
            return $none('Postfach ist nicht registriert.');
        }
        $mailbox = (string) $archive['mailbox'];
        if (!$force) {
            $usage = $this->exchange->mailboxUsage($mailbox);
            $threshold = $this->config->archiveThresholdBytes((int) ($usage['limit'] ?? 0));
            if ($threshold <= 0) {
                return $none('Schwelle nicht bestimmbar (keine Postfachgrenze bekannt).');
            }
            if ((int) ($usage['used'] ?? 0) < $threshold) {
                return $none('Schwelle nicht erreicht.');
            }
        }

        return $this->run($archive);
    }

    /**
     * Fuehrt einen Archivierungslauf aus (mit Sperre, Wiederaufnahme und
     * Batch-Verarbeitung). Oeffentlich fuer den Hintergrund-Worker.
     *
     * @param array<string,mixed> $archive
     * @return array{ran:bool,reason:string,processed:int,deleted:int,failed:int}
     */
    public function run(array $archive): array
    {
        $archiveId = (int) $archive['id'];
        $uid = (string) $archive['user_uid'];
        $mailbox = (string) $archive['mailbox'];
        $now = $this->timestamp();
        $jobId = $this->repository->acquireJob($archiveId, $now, $this->timestamp(600));
        if ($jobId === null) {
            return ['ran' => false, 'reason' => 'Es läuft bereits eine Archivierung für dieses Postfach.', 'processed' => 0, 'deleted' => 0, 'failed' => 0];
        }

        $processed = 0;
        $deleted = 0;
        $failed = 0;
        try {
            // Wiederaufnahme: haengen gebliebene Zustaende zuerst abarbeiten.
            $this->recoverPending($archive);
            $deleted += $this->deleteCommitted($archive);

            $cutoff = ($this->now)() - $this->config->archiveAgeDays() * 86400;
            $batchSize = $this->config->archiveBatchSize();
            $folders = $this->exchange->folders($mailbox);
            $folderIds = $this->ensureFolderTree($archiveId, $folders);
            foreach ($folders as $folder) {
                $folderId = $folderIds[(string) $folder['id']] ?? null;
                if ($folderId === null) {
                    continue;
                }
                $offset = 0;
                $seen = [];
                do {
                    $this->repository->heartbeatJob($jobId, $this->timestamp(600));
                    $page = $this->exchange->archiveCandidates($mailbox, (string) $folder['id'], $cutoff, $offset, $batchSize);
                    if ($page['items'] === []) {
                        break;
                    }
                    $batch = [];
                    $remaining = 0; // Elemente dieser Seite, die in Exchange verbleiben.
                    foreach ($page['items'] as $candidate) {
                        $hash = $this->itemHash((string) ($candidate['internet_message_id'] ?? ''), (string) $candidate['id']);
                        if (isset($seen[$hash])) {
                            $remaining++;
                            continue;
                        }
                        $seen[$hash] = true;
                        $existing = $this->repository->findItemByHash($archiveId, $hash);
                        if ($existing !== null && in_array((string) $existing['status'], ['committed', 'deleted'], true)) {
                            // Bereits archiviert (committed, aber noch in Exchange):
                            // nicht erneut ablegen - nur die Loeschung nachholen.
                            if ((string) $existing['status'] === 'committed') {
                                $removed = $this->deleteItem($archive, $existing);
                                $deleted += $removed;
                                $remaining += $removed === 1 ? 0 : 1;
                            }
                            continue;
                        }
                        $batch[] = ['candidate' => $candidate, 'hash' => $hash, 'existing' => $existing];
                    }
                    if ($batch !== []) {
                        [$batchProcessed, $batchDeleted, $batchFailed] = $this->archiveBatch($archive, $folderId, $batch);
                        $processed += $batchProcessed;
                        $deleted += $batchDeleted;
                        $failed += $batchFailed;
                        $remaining += count($batch) - $batchDeleted;
                        $this->repository->updateJobCounters($jobId, $processed, $processed, $deleted);
                    }
                    // Aelteste zuerst: geloeschte Nachrichten ruecken nach; der
                    // Offset ueberspringt nur Elemente, die in Exchange bleiben.
                    $offset += $remaining;
                } while ($page['has_more']);
            }

            $this->repository->updateArchiveTotals($archiveId, $this->timestamp());
            $this->writeManifest($archive);
            $this->repository->setArchiveNotice($archiveId, 'active', null);
            $this->repository->finishJob($jobId, 'completed', $this->timestamp());

            return ['ran' => true, 'reason' => 'Lauf abgeschlossen.', 'processed' => $processed, 'deleted' => $deleted, 'failed' => $failed];
        } catch (\Throwable $exception) {
            $this->repository->finishJob($jobId, 'failed', $this->timestamp(), mb_substr($exception->getMessage(), 0, 500));
            $this->repository->setArchiveNotice($archiveId, 'error', mb_substr($exception->getMessage(), 0, 500));

            return ['ran' => true, 'reason' => 'Lauf fehlgeschlagen: ' . $exception->getMessage(), 'processed' => $processed, 'deleted' => $deleted, 'failed' => $failed + 1];
        }
    }

    /**
     * Archiviert einen Batch: Chunk bauen, hochladen, zurcklesen,
     * verifizieren, committen, dann loeschen.
     *
     * @param array<string,mixed> $archive
     * @param list<array{candidate:array<string,mixed>,hash:string,existing:array<string,mixed>|null}> $batch
     * @return array{0:int,1:int,2:int} verarbeitet, geloescht, fehlgeschlagen
     */
    private function archiveBatch(array $archive, int $folderId, array $batch): array
    {
        $archiveId = (int) $archive['id'];
        $mailbox = (string) $archive['mailbox'];
        $chunkName = 'chunk-' . bin2hex(random_bytes(8)) . '.ova';
        $chunk = self::MAGIC;
        $staged = [];
        $failed = 0;

        foreach ($batch as $entry) {
            $candidate = $entry['candidate'];
            $itemId = (string) $candidate['id'];
            try {
                $full = $this->exchange->messageMime($mailbox, $itemId);
            } catch (OrvantaException) {
                // Nachricht zwischenzeitlich verschoben/geloescht: ueberspringen.
                continue;
            }
            $payload = $full['mime'];
            $kind = 'mime';
            if ($payload === '') {
                // Ohne vollstaendigen MIME-Quelltext (Body + Anhaenge) wird nicht
                // archiviert und erst recht nicht geloescht: Nachricht bleibt in
                // Exchange und wird als fehlgeschlagen vermerkt (siehe Prompt §33).
                $failed++;
                $this->markFailed($archiveId, $folderId, $entry);
                continue;
            }
            $record = gzencode($payload, 6);
            if ($record === false) {
                $failed++;
                $this->markFailed($archiveId, $folderId, $entry);
                continue;
            }
            if (strlen($record) + strlen($chunk) > self::MAX_CHUNK_BYTES) {
                if (strlen($record) + strlen(self::MAGIC) > self::MAX_CHUNK_BYTES) {
                    // Einzelnachricht sprengt das Containerlimit: bleibt in Exchange.
                    $failed++;
                    $this->markFailed($archiveId, $folderId, $entry);
                    continue;
                }
                break; // Rest des Batches im naechsten Lauf (Chunk voll).
            }
            $offset = strlen($chunk);
            $chunk .= $record;
            $row = $this->itemRow($archiveId, $folderId, $entry, $kind, $payload, $chunkName, $offset, strlen($record));
            if ($entry['existing'] !== null) {
                $id = (int) $entry['existing']['id'];
                $this->repository->restageItem($id, (string) $row['change_key'], (string) $row['content_hash'], (int) $row['size_bytes'], $chunkName, $offset, strlen($record));
            } else {
                $id = $this->repository->insertItem($row);
            }
            $staged[] = ['id' => $id, 'exchange_item_id' => $itemId, 'internet_message_id' => (string) ($candidate['internet_message_id'] ?? ''), 'offset' => $offset, 'length' => strlen($record), 'content_hash' => (string) $row['content_hash']];
        }

        if ($staged === []) {
            return [0, 0, $failed];
        }

        // 2. Verifizieren: hochladen, zurcklesen, jede Fundstelle pruefen.
        $this->storage->put((string) $archive['user_uid'], $this->userFolder($archive), $chunkName, $chunk);
        $readBack = $this->storage->get((string) $archive['user_uid'], $this->userFolder($archive), $chunkName);
        if (hash('sha256', $readBack) !== hash('sha256', $chunk)) {
            throw new \RuntimeException('Archivcontainer nach dem Hochladen nicht identisch (Prüfsumme abweichend) - es wird nichts gelöscht.');
        }
        foreach ($staged as $entry) {
            $payload = $this->decodeRecord($readBack, $entry['offset'], $entry['length']);
            if ($payload === null || hash('sha256', $payload) !== $entry['content_hash']) {
                throw new \RuntimeException('Archivcontainer nach dem Hochladen nicht lesbar (Datensatz-Prüfsumme abweichend) - es wird nichts gelöscht.');
            }
        }

        // 3. Commit (transaktional) - ab jetzt gilt die Nachricht als archiviert.
        $this->repository->commitItems(array_map(static fn (array $entry): int => $entry['id'], $staged), $this->timestamp());

        // 4. Loeschen - je Nachricht, nach erneuter Identitaetspruefung.
        $deleted = 0;
        foreach ($staged as $entry) {
            $item = $this->repository->findItem($archiveId, $entry['id']);
            if ($item !== null) {
                $deleted += $this->deleteItem($archive, $item);
            }
        }

        return [count($staged), $deleted, $failed];
    }

    /**
     * Loescht eine committete Nachricht aus Exchange - nur wenn die dauerhafte
     * Identitaet (InternetMessageId) unveraendert ist. Existiert die Nachricht
     * nicht mehr, gilt sie als geloescht (sichere Richtung: das Archiv hat sie).
     *
     * @param array<string,mixed> $archive
     * @param array<string,mixed> $item
     */
    private function deleteItem(array $archive, array $item): int
    {
        $mailbox = (string) $archive['mailbox'];
        $itemId = (string) $item['exchange_item_id'];
        $expected = (string) $item['internet_message_id'];
        try {
            $identity = $this->exchange->messageIdentity($mailbox, $itemId);
            if ($identity !== null) {
                if ($expected !== '' && $identity['internet_message_id'] !== '' && $identity['internet_message_id'] !== $expected) {
                    // Identitaet weicht ab: niemals loeschen.
                    return 0;
                }
                $this->exchange->delete($mailbox, [$itemId], true);
            }
        } catch (OrvantaException $exception) {
            if (!str_contains($exception->getMessage(), 'nicht gefunden')) {
                // Loeschung fehlgeschlagen: Nachricht bleibt in Exchange,
                // Zustand 'committed' sorgt fuer einen spaeteren neuen Versuch.
                return 0;
            }
        }
        $this->repository->markItemStatus((int) $item['id'], 'deleted');

        return 1;
    }

    /**
     * Wiederaufnahme: 'pending'-Eintraege stammen aus einem abgebrochenen Lauf
     * vor der Verifikation - ihre Fundstellen sind ungueltig und werden beim
     * naechsten Durchlauf neu vergeben (die Nachricht ist noch in Exchange).
     *
     * @param array<string,mixed> $archive
     */
    private function recoverPending(array $archive): void
    {
        foreach ($this->repository->pendingItems((int) $archive['id']) as $item) {
            // Fundstelle verwerfen; der regulaere Lauf archiviert die Nachricht
            // erneut (Dedupe ueber item_hash nutzt denselben Datensatz).
            $this->repository->restageItem((int) $item['id'], (string) $item['change_key'], (string) $item['content_hash'], (int) $item['size_bytes'], '', 0, 0);
        }
    }

    /**
     * Wiederaufnahme: committete, aber nicht geloeschte Nachrichten loeschen.
     *
     * @param array<string,mixed> $archive
     */
    private function deleteCommitted(array $archive): int
    {
        $deleted = 0;
        foreach ($this->repository->committedUndeleted((int) $archive['id']) as $item) {
            if ((string) $item['chunk_name'] === '') {
                continue;
            }
            $deleted += $this->deleteItem($archive, $item);
        }

        return $deleted;
    }

    // ------------------------------------------------------------------ Lesezugriff (Oberflaeche)

    /**
     * Archivierte Ordner des Benutzers.
     *
     * @return list<array<string,mixed>>
     */
    public function folders(string $uid): array
    {
        $archive = $this->repository->findArchive($uid);
        if ($archive === null) {
            return [];
        }

        return array_map(static fn (array $folder): array => [
            'id' => (int) $folder['id'],
            'parent_id' => $folder['parent_id'] !== null ? (int) $folder['parent_id'] : null,
            'name' => (string) $folder['name'],
            'path' => (string) $folder['path'],
            'total' => (int) $folder['total'],
        ], array_values(array_filter($this->repository->folders((int) $archive['id']), static fn (array $folder): bool => (int) $folder['total'] > 0)));
    }

    /**
     * Archivierte Nachrichten eines Ordners (Index, ohne Containerzugriff).
     *
     * @return array{items:list<array<string,mixed>>,total:int,offset:int}
     */
    public function messages(string $uid, int $folderId, int $offset = 0, int $limit = 50): array
    {
        $archive = $this->requireArchive($uid);
        if ($this->repository->findFolder((int) $archive['id'], $folderId) === null) {
            throw new OrvantaException('Der Archivordner wurde nicht gefunden.', 404);
        }
        $result = $this->repository->items((int) $archive['id'], $folderId, max(0, $offset), max(1, min(200, $limit)));

        return [
            'items' => array_map(self::indexEntry(...), $result['items']),
            'total' => $result['total'],
            'offset' => max(0, $offset),
        ];
    }

    /**
     * Suche im Archivindex.
     *
     * @return list<array<string,mixed>>
     */
    public function search(string $uid, string $query, int $limit = 50): array
    {
        $archive = $this->repository->findArchive($uid);
        if ($archive === null || trim($query) === '') {
            return [];
        }

        return array_map(static function (array $row): array {
            $entry = self::indexEntry($row);
            $entry['folder_path'] = (string) ($row['folder_path'] ?? '');

            return $entry;
        }, $this->repository->search((int) $archive['id'], trim($query), max(1, min(100, $limit))));
    }

    /**
     * Vollstaendige archivierte Nachricht: liest den Datensatz aus dem
     * Archivcontainer, prueft die Integritaet und bereitet HTML-Inhalt
     * saniert fuer die Anzeige auf.
     *
     * @return array<string,mixed>
     */
    public function message(string $uid, int $itemId): array
    {
        $archive = $this->requireArchive($uid);
        $item = $this->repository->findItem((int) $archive['id'], $itemId);
        if ($item === null || !in_array((string) $item['status'], ['committed', 'deleted'], true)) {
            throw new OrvantaException('Die archivierte Nachricht wurde nicht gefunden.', 404);
        }
        $payload = $this->readPayload($archive, $item);
        $entry = self::indexEntry($item);
        $entry['archived'] = true;
        if ((string) $item['kind'] === 'json') {
            $data = json_decode($payload, true);
            $entry['body_html'] = nl2br(htmlspecialchars((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'));
            $entry['attachments'] = [];

            return $entry;
        }
        $parsed = $this->parser->parse($payload);
        if ($parsed['html'] !== '') {
            $clean = MailHtmlSanitizer::clean($parsed['html']);
            $entry['body_html'] = $clean['html'];
            $entry['blocked_images'] = $clean['blocked_images'];
        } else {
            $entry['body_html'] = nl2br(htmlspecialchars($parsed['text'], ENT_QUOTES, 'UTF-8'));
            $entry['blocked_images'] = 0;
        }
        $entry['attachments'] = array_values(array_map(static fn (int $index, array $attachment): array => [
            'id' => 'orvanta-archive:' . $itemId . ':' . $index,
            'name' => $attachment['name'],
            'content_type' => $attachment['content_type'],
            'size' => strlen($attachment['content']),
            'inline' => $attachment['inline'],
        ], array_keys($parsed['attachments']), $parsed['attachments']));

        return $entry;
    }

    /**
     * Anhang einer archivierten Nachricht (fuer den Token-Abruf).
     *
     * @return array{name:string,content_type:string,content:string}
     */
    public function attachment(string $uid, int $itemId, int $index): array
    {
        $archive = $this->requireArchive($uid);
        $item = $this->repository->findItem((int) $archive['id'], $itemId);
        if ($item === null || (string) $item['kind'] !== 'mime' || !in_array((string) $item['status'], ['committed', 'deleted'], true)) {
            throw new OrvantaException('Der Anhang wurde nicht gefunden.', 404);
        }
        $parsed = $this->parser->parse($this->readPayload($archive, $item));
        $attachment = $parsed['attachments'][$index] ?? null;
        if ($attachment === null) {
            throw new OrvantaException('Der Anhang wurde nicht gefunden.', 404);
        }

        return ['name' => $attachment['name'], 'content_type' => $attachment['content_type'], 'content' => $attachment['content']];
    }

    /**
     * Validiert alle Archivcontainer eines Benutzers gegen die gespeicherten
     * Pruefsummen.
     *
     * @return array{ok:bool,chunks:int,items:int,errors:list<string>}
     */
    public function verify(string $uid): array
    {
        $archive = $this->requireArchive($uid);
        $errors = [];
        $items = 0;
        $chunks = $this->repository->chunkNames((int) $archive['id']);
        foreach ($chunks as $chunkName) {
            try {
                $bytes = $this->storage->get($uid, $this->userFolder($archive), $chunkName);
            } catch (\RuntimeException $exception) {
                $errors[] = $chunkName . ': ' . $exception->getMessage();
                continue;
            }
            if (!str_starts_with($bytes, self::MAGIC)) {
                $errors[] = $chunkName . ': Ungültiges Containerformat.';
                continue;
            }
            foreach ($this->repository->itemsByChunk((int) $archive['id'], $chunkName) as $item) {
                $items++;
                $payload = $this->decodeRecord($bytes, (int) $item['chunk_offset'], (int) $item['chunk_length']);
                if ($payload === null || hash('sha256', $payload) !== (string) $item['content_hash']) {
                    $errors[] = $chunkName . ' #' . $item['id'] . ': Prüfsumme abweichend.';
                }
            }
        }

        return ['ok' => $errors === [], 'chunks' => count($chunks), 'items' => $items, 'errors' => $errors];
    }

    // ------------------------------------------------------------------ Intern

    /**
     * Liest und prueft den Datensatz einer Nachricht aus ihrem Container.
     *
     * @param array<string,mixed> $archive
     * @param array<string,mixed> $item
     */
    private function readPayload(array $archive, array $item): string
    {
        try {
            $bytes = $this->storage->get((string) $archive['user_uid'], $this->userFolder($archive), (string) $item['chunk_name']);
        } catch (\RuntimeException $exception) {
            throw new OrvantaException('Archivcontainer nicht lesbar: ' . $exception->getMessage(), 502);
        }
        if (!str_starts_with($bytes, self::MAGIC)) {
            throw new OrvantaException(self::INTEGRITY_ERROR, 502);
        }
        $payload = $this->decodeRecord($bytes, (int) $item['chunk_offset'], (int) $item['chunk_length']);
        if ($payload === null || hash('sha256', $payload) !== (string) $item['content_hash']) {
            throw new OrvantaException(self::INTEGRITY_ERROR, 502);
        }

        return $payload;
    }

    /** Entpackt einen Datensatz (gzip) aus einem Containerinhalt. */
    private function decodeRecord(string $bytes, int $offset, int $length): ?string
    {
        if ($offset < strlen(self::MAGIC) || $length <= 0 || $offset + $length > strlen($bytes)) {
            return null;
        }
        $payload = @gzdecode(substr($bytes, $offset, $length));

        return $payload === false ? null : $payload;
    }

    /**
     * Fehlgeschlagenes Element vermerken (bleibt in Exchange); ohne Nutzlast,
     * damit kein unvollstaendiger Datensatz als archiviert gelten kann.
     *
     * @param array{candidate:array<string,mixed>,hash:string,existing:array<string,mixed>|null} $entry
     */
    private function markFailed(int $archiveId, int $folderId, array $entry): void
    {
        $id = $entry['existing'] !== null
            ? (int) $entry['existing']['id']
            : $this->repository->insertItem($this->itemRow($archiveId, $folderId, $entry, 'mime', '', '', 0, 0));
        $this->repository->markItemStatus($id, 'failed');
    }

    /**
     * Ordner des Postfachs in Baumreihenfolge (Eltern vor Kindern) als Archiv-
     * ordner anlegen; liefert je Exchange-Ordner die Archiv-Ordner-ID.
     *
     * @param list<array{id:string,name:string,parent:string}> $folders
     * @return array<string,int> Exchange-Ordner-ID => Archiv-Ordner-ID
     */
    private function ensureFolderTree(int $archiveId, array $folders): array
    {
        $byId = [];
        foreach ($folders as $folder) {
            $byId[(string) $folder['id']] = $folder;
        }
        $ids = [];
        $paths = [];
        $resolve = function (string $exchangeId, array $trail) use (&$resolve, &$ids, &$paths, $byId, $archiveId): ?int {
            if (isset($ids[$exchangeId])) {
                return $ids[$exchangeId];
            }
            $folder = $byId[$exchangeId] ?? null;
            if ($folder === null || isset($trail[$exchangeId])) {
                return null; // unbekannter Elternordner (z. B. Postfachwurzel) oder Zyklus
            }
            $trail[$exchangeId] = true;
            $parentExchangeId = (string) ($folder['parent'] ?? '');
            $parentId = $parentExchangeId !== '' ? $resolve($parentExchangeId, $trail) : null;
            $name = (string) $folder['name'];
            $path = $parentId !== null ? $paths[$parentExchangeId] . '/' . $name : $name;
            $paths[$exchangeId] = $path;

            return $ids[$exchangeId] = $this->repository->ensureFolder($archiveId, $exchangeId, $parentId, $name, $path);
        };
        foreach ($byId as $exchangeId => $folder) {
            $resolve((string) $exchangeId, []);
        }

        return $ids;
    }

    /**
     * @param array{candidate:array<string,mixed>,hash:string,existing:array<string,mixed>|null} $entry
     * @return array<string,mixed>
     */
    private function itemRow(int $archiveId, int $folderId, array $entry, string $kind, string $payload, string $chunkName, int $offset, int $length): array
    {
        $candidate = $entry['candidate'];
        $from = is_array($candidate['from'] ?? null) ? $candidate['from'] : [];
        $to = is_array($candidate['to'] ?? null) ? $candidate['to'] : [];
        $recipients = implode(', ', array_filter(array_map(
            static fn (array $mailbox): string => trim((string) ($mailbox['name'] ?? '') . ' <' . (string) ($mailbox['email'] ?? '') . '>'),
            $to
        )));
        $searchText = $kind === 'mime' ? $this->parser->searchText($payload) : '';
        $parsedNames = $kind === 'mime' ? $this->parser->parse($payload)['attachments'] : [];

        return [
            'archive_id' => $archiveId,
            'folder_id' => $folderId,
            'exchange_item_id' => (string) $candidate['id'],
            'item_hash' => $entry['hash'],
            'change_key' => (string) ($candidate['change_key'] ?? ''),
            'internet_message_id' => (string) ($candidate['internet_message_id'] ?? ''),
            'subject' => (string) ($candidate['subject'] ?? ''),
            'from_name' => (string) ($from['name'] ?? ''),
            'from_email' => (string) ($from['email'] ?? ''),
            'recipients' => mb_substr($recipients, 0, 2000),
            'item_date' => gmdate('Y-m-d H:i:s', (int) ($candidate['received'] ?? 0)),
            'kind' => $kind,
            'size_bytes' => strlen($payload),
            'content_hash' => hash('sha256', $payload),
            'chunk_name' => $chunkName,
            'chunk_offset' => $offset,
            'chunk_length' => $length,
            'has_attachments' => !empty($candidate['has_attachments']) || $parsedNames !== [],
            'attachment_names' => mb_substr(implode(', ', array_map(static fn (array $attachment): string => (string) $attachment['name'], $parsedNames)), 0, 2000),
            'search_text' => $searchText,
        ];
    }

    /**
     * Dauerhafte Identitaet einer Nachricht: bevorzugt die InternetMessageId,
     * sonst die EWS-ItemId.
     */
    private function itemHash(string $internetMessageId, string $exchangeItemId): string
    {
        return $internetMessageId !== '' ? sha1('imid:' . $internetMessageId) : sha1('ewsid:' . $exchangeItemId);
    }

    /**
     * @param array<string,mixed> $archive
     */
    private function userFolder(array $archive): string
    {
        $folder = trim((string) $archive['storage_folder']);

        return $folder === '' ? $this->config->archiveFolder() : $folder;
    }

    /**
     * Schreibt das Transparenz-Manifest (best effort - der massgebliche Index
     * ist die Datenbank).
     *
     * @param array<string,mixed> $archive
     */
    private function writeManifest(array $archive): void
    {
        $archiveId = (int) $archive['id'];
        $manifest = [
            'format' => self::MAGIC,
            'format_version' => self::FORMAT_VERSION,
            'compression' => 'gzip',
            'generated_at' => $this->timestamp(),
            'chunks' => $this->repository->chunkNames($archiveId),
            'note' => 'Maßgeblicher Index ist die Intranet-Datenbank (orvanta_archive_items).',
        ];
        try {
            $this->storage->put((string) $archive['user_uid'], $this->userFolder($archive), 'manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\RuntimeException) {
            // Best effort: Manifestfehler gefaehrden keine Archivdaten.
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function indexEntry(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'folder_id' => (int) $row['folder_id'],
            'subject' => (string) $row['subject'],
            'from' => ['name' => (string) $row['from_name'], 'email' => (string) $row['from_email']],
            'recipients' => (string) ($row['recipients'] ?? ''),
            'date' => (string) ($row['item_date'] ?? ''),
            'size' => (int) $row['size_bytes'],
            'has_attachments' => (bool) $row['has_attachments'],
            'archived' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function requireArchive(string $uid): array
    {
        $archive = $this->repository->findArchive($uid);
        if ($archive === null) {
            throw new OrvantaException('Für dieses Konto existiert kein Archiv.', 404);
        }

        return $archive;
    }

    private function timestamp(int $offsetSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', ($this->now)() + $offsetSeconds);
    }
}
