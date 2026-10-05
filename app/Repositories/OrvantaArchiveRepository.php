<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Orvanta-Langzeitarchiv: Archivstammdaten (orvanta_archives), Abbild der
 * Exchange-Ordnerstruktur (orvanta_archive_folders), Index und Journal der
 * archivierten Nachrichten (orvanta_archive_items) sowie Archivierungslaeufe
 * mit Sperre (orvanta_archive_jobs). SQL laeuft auf MySQL und SQLite (Tests).
 *
 * Journal-Zustaende einer Nachricht (orvanta_archive_items.status):
 *   pending   Chunk-Fundstelle vergeben, Container noch nicht verifiziert
 *   committed Container hochgeladen, zurueckgelesen und Pruefsumme bestaetigt
 *   deleted   Original wurde nach dem Commit aus Exchange entfernt
 *   failed    Nachricht konnte nicht archiviert werden (bleibt in Exchange)
 */
final class OrvantaArchiveRepository extends Repository
{
    // ------------------------------------------------------------------ Archive

    /**
     * @return array<string,mixed>|null
     */
    public function findArchive(string $uid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archives WHERE user_uid = :uid');
        $statement->execute(['uid' => $uid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Legt das dauerhafte Archiv eines Benutzers an bzw. liefert das
     * vorhandene (ein Archiv je Benutzer, wird bei spaeteren Laeufen
     * fortgeschrieben).
     *
     * @return array<string,mixed>
     */
    public function ensureArchive(string $uid, string $mailbox, string $storageFolder, int $formatVersion): array
    {
        $existing = $this->findArchive($uid);
        if ($existing !== null) {
            if ((string) $existing['mailbox'] !== $mailbox) {
                $this->pdo->prepare('UPDATE orvanta_archives SET mailbox = :mailbox WHERE id = :id')
                    ->execute(['mailbox' => $mailbox, 'id' => (int) $existing['id']]);
                $existing['mailbox'] = $mailbox;
            }

            return $existing;
        }
        $this->pdo->prepare('INSERT INTO orvanta_archives (user_uid, mailbox, storage_folder, format_version) VALUES (:uid, :mailbox, :folder, :version)')
            ->execute(['uid' => $uid, 'mailbox' => $mailbox, 'folder' => $storageFolder, 'version' => $formatVersion]);

        return $this->findArchive($uid) ?? [];
    }

    public function updateArchiveTotals(int $archiveId, string $now): void
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) AS messages, COALESCE(SUM(size_bytes), 0) AS bytes, COUNT(DISTINCT chunk_name) AS chunks
            FROM orvanta_archive_items WHERE archive_id = :id AND status IN (\'committed\', \'deleted\')');
        $statement->execute(['id' => $archiveId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->pdo->prepare('UPDATE orvanta_archives SET size_bytes = :bytes, message_count = :messages, chunk_count = :chunks, last_successful_run = :now WHERE id = :id')
            ->execute([
                'bytes' => (int) ($row['bytes'] ?? 0),
                'messages' => (int) ($row['messages'] ?? 0),
                'chunks' => (int) ($row['chunks'] ?? 0),
                'now' => $now,
                'id' => $archiveId,
            ]);
    }

    public function setArchiveNotice(int $archiveId, string $status, ?string $notice): void
    {
        $this->pdo->prepare('UPDATE orvanta_archives SET status = :status, last_notice = :notice WHERE id = :id')
            ->execute(['status' => $status, 'notice' => $notice, 'id' => $archiveId]);
    }

    /**
     * Alle Archive (fuer den Hintergrund-Worker).
     *
     * @return list<array<string,mixed>>
     */
    public function archives(): array
    {
        return $this->pdo->query('SELECT * FROM orvanta_archives ORDER BY id ASC')?->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ------------------------------------------------------------------ Ordner

    /**
     * Legt einen Archivordner an bzw. aktualisiert Name und Pfad; liefert die
     * lokale Ordner-ID. Identifikation ueber sha1 der EWS-FolderId.
     */
    public function ensureFolder(int $archiveId, string $exchangeFolderId, ?int $parentId, string $name, string $path): int
    {
        $hash = sha1($exchangeFolderId);
        $select = $this->pdo->prepare('SELECT id FROM orvanta_archive_folders WHERE archive_id = :archive AND folder_hash = :hash');
        $select->execute(['archive' => $archiveId, 'hash' => $hash]);
        $id = $select->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare('UPDATE orvanta_archive_folders SET parent_id = :parent, name = :name, path = :path WHERE id = :id')
                ->execute(['parent' => $parentId, 'name' => $name, 'path' => $path, 'id' => (int) $id]);

            return (int) $id;
        }
        $this->pdo->prepare('INSERT INTO orvanta_archive_folders (archive_id, exchange_folder_id, folder_hash, parent_id, name, path) VALUES (:archive, :folder, :hash, :parent, :name, :path)')
            ->execute(['archive' => $archiveId, 'folder' => $exchangeFolderId, 'hash' => $hash, 'parent' => $parentId, 'name' => $name, 'path' => $path]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ordner des Archivs mit Anzahl archivierter Nachrichten.
     *
     * @return list<array<string,mixed>>
     */
    public function folders(int $archiveId): array
    {
        $statement = $this->pdo->prepare('SELECT f.id, f.parent_id, f.name, f.path, f.exchange_folder_id,
                (SELECT COUNT(*) FROM orvanta_archive_items i WHERE i.folder_id = f.id AND i.status IN (\'committed\', \'deleted\')) AS total
            FROM orvanta_archive_folders f WHERE f.archive_id = :archive ORDER BY f.path ASC');
        $statement->execute(['archive' => $archiveId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findFolder(int $archiveId, int $folderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archive_folders WHERE archive_id = :archive AND id = :id');
        $statement->execute(['archive' => $archiveId, 'id' => $folderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // ------------------------------------------------------------------ Nachrichten (Index und Journal)

    /**
     * @return array<string,mixed>|null
     */
    public function findItemByHash(int $archiveId, string $itemHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archive_items WHERE archive_id = :archive AND item_hash = :hash');
        $statement->execute(['archive' => $archiveId, 'hash' => $itemHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findItem(int $archiveId, int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archive_items WHERE archive_id = :archive AND id = :id');
        $statement->execute(['archive' => $archiveId, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Legt den Journal-Eintrag einer Nachricht an (status 'pending').
     *
     * @param array<string,mixed> $item
     */
    public function insertItem(array $item): int
    {
        $this->pdo->prepare('INSERT INTO orvanta_archive_items
                (archive_id, folder_id, exchange_item_id, item_hash, change_key, internet_message_id, subject, from_name, from_email, recipients, item_date, kind, size_bytes, content_hash, chunk_name, chunk_offset, chunk_length, has_attachments, attachment_names, search_text, status)
            VALUES (:archive_id, :folder_id, :exchange_item_id, :item_hash, :change_key, :internet_message_id, :subject, :from_name, :from_email, :recipients, :item_date, :kind, :size_bytes, :content_hash, :chunk_name, :chunk_offset, :chunk_length, :has_attachments, :attachment_names, :search_text, \'pending\')')
            ->execute([
                'archive_id' => (int) $item['archive_id'],
                'folder_id' => (int) $item['folder_id'],
                'exchange_item_id' => (string) $item['exchange_item_id'],
                'item_hash' => (string) $item['item_hash'],
                'change_key' => (string) ($item['change_key'] ?? ''),
                'internet_message_id' => mb_substr((string) ($item['internet_message_id'] ?? ''), 0, 512),
                'subject' => mb_substr((string) ($item['subject'] ?? ''), 0, 512),
                'from_name' => mb_substr((string) ($item['from_name'] ?? ''), 0, 255),
                'from_email' => mb_substr((string) ($item['from_email'] ?? ''), 0, 255),
                'recipients' => (string) ($item['recipients'] ?? ''),
                'item_date' => $item['item_date'] ?? null,
                'kind' => (string) ($item['kind'] ?? 'mime'),
                'size_bytes' => (int) ($item['size_bytes'] ?? 0),
                'content_hash' => (string) ($item['content_hash'] ?? ''),
                'chunk_name' => (string) ($item['chunk_name'] ?? ''),
                'chunk_offset' => (int) ($item['chunk_offset'] ?? 0),
                'chunk_length' => (int) ($item['chunk_length'] ?? 0),
                'has_attachments' => !empty($item['has_attachments']) ? 1 : 0,
                'attachment_names' => (string) ($item['attachment_names'] ?? ''),
                'search_text' => (string) ($item['search_text'] ?? ''),
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Fundstelle eines wiederaufgenommenen 'pending'-Eintrags neu vergeben
     * (der alte Chunk wurde nie bestaetigt und ist damit ungueltig).
     */
    public function restageItem(int $id, string $changeKey, string $contentHash, int $size, string $chunkName, int $offset, int $length): void
    {
        $this->pdo->prepare('UPDATE orvanta_archive_items SET change_key = :key, content_hash = :hash, size_bytes = :size, chunk_name = :chunk, chunk_offset = :off, chunk_length = :len, status = \'pending\' WHERE id = :id')
            ->execute(['key' => $changeKey, 'hash' => $contentHash, 'size' => $size, 'chunk' => $chunkName, 'off' => $offset, 'len' => $length, 'id' => $id]);
    }

    /**
     * Markiert die Eintraege eines verifizierten Chunks in einer Transaktion
     * als 'committed' - erst dieser dauerhafte Zustand erlaubt die Loeschung
     * aus Exchange.
     *
     * @param list<int> $ids
     */
    public function commitItems(array $ids, string $now): void
    {
        if ($ids === []) {
            return;
        }
        $statement = $this->pdo->prepare('UPDATE orvanta_archive_items SET status = \'committed\', committed_at = :now WHERE id = :id AND status = \'pending\'');
        $this->pdo->beginTransaction();
        try {
            foreach ($ids as $id) {
                $statement->execute(['now' => $now, 'id' => $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function markItemStatus(int $id, string $status): void
    {
        $this->pdo->prepare('UPDATE orvanta_archive_items SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Committete, aber noch nicht aus Exchange geloeschte Nachrichten
     * (Wiederaufnahme nach Absturz zwischen Commit und Loeschung).
     *
     * @return list<array<string,mixed>>
     */
    public function committedUndeleted(int $archiveId, int $limit = 500): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archive_items WHERE archive_id = :archive AND status = \'committed\' ORDER BY id ASC LIMIT ' . max(1, $limit));
        $statement->execute(['archive' => $archiveId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Haengen gebliebene 'pending'-Eintraege (Absturz vor der Verifikation).
     *
     * @return list<array<string,mixed>>
     */
    public function pendingItems(int $archiveId, int $limit = 500): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archive_items WHERE archive_id = :archive AND status = \'pending\' ORDER BY id ASC LIMIT ' . max(1, $limit));
        $statement->execute(['archive' => $archiveId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Archivierte Nachrichten eines Ordners, neueste zuerst.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function items(int $archiveId, int $folderId, int $offset, int $limit): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM orvanta_archive_items WHERE archive_id = :archive AND folder_id = :folder AND status IN (\'committed\', \'deleted\')');
        $count->execute(['archive' => $archiveId, 'folder' => $folderId]);
        $statement = $this->pdo->prepare('SELECT id, folder_id, subject, from_name, from_email, recipients, item_date, size_bytes, has_attachments, attachment_names, internet_message_id
            FROM orvanta_archive_items WHERE archive_id = :archive AND folder_id = :folder AND status IN (\'committed\', \'deleted\')
            ORDER BY item_date DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $statement->execute(['archive' => $archiveId, 'folder' => $folderId]);

        return ['items' => $statement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => (int) $count->fetchColumn()];
    }

    /**
     * Suche im Archivindex (Betreff, Absender, Empfaenger, Volltextauszug,
     * Internet Message ID, Anhangsnamen) - der eigentliche Mailinhalt bleibt
     * im Archivcontainer.
     *
     * @return list<array<string,mixed>>
     */
    public function search(int $archiveId, string $query, int $limit = 50): array
    {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)) . '%';
        $statement = $this->pdo->prepare('SELECT i.id, i.folder_id, i.subject, i.from_name, i.from_email, i.item_date, i.size_bytes, i.has_attachments, f.path AS folder_path
            FROM orvanta_archive_items i JOIN orvanta_archive_folders f ON f.id = i.folder_id
            WHERE i.archive_id = :archive AND i.status IN (\'committed\', \'deleted\') AND (
                LOWER(i.subject) LIKE :q1 ESCAPE \'!\' OR LOWER(i.from_name) LIKE :q2 ESCAPE \'!\' OR LOWER(i.from_email) LIKE :q3 ESCAPE \'!\'
                OR LOWER(i.recipients) LIKE :q4 ESCAPE \'!\' OR LOWER(i.search_text) LIKE :q5 ESCAPE \'!\'
                OR LOWER(i.internet_message_id) LIKE :q6 ESCAPE \'!\' OR LOWER(i.attachment_names) LIKE :q7 ESCAPE \'!\'
            ) ORDER BY i.item_date DESC, i.id DESC LIMIT ' . max(1, $limit));
        $statement->execute(['archive' => $archiveId, 'q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like, 'q7' => $like]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Alle Fundstellen je Chunk (Archivvalidierung).
     *
     * @return list<array<string,mixed>>
     */
    public function itemsByChunk(int $archiveId, string $chunkName): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archive_items WHERE archive_id = :archive AND chunk_name = :chunk AND status IN (\'committed\', \'deleted\') ORDER BY chunk_offset ASC');
        $statement->execute(['archive' => $archiveId, 'chunk' => $chunkName]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Namen aller Chunks mit committeten Nachrichten.
     *
     * @return list<string>
     */
    public function chunkNames(int $archiveId): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT chunk_name FROM orvanta_archive_items WHERE archive_id = :archive AND status IN (\'committed\', \'deleted\') AND chunk_name <> \'\' ORDER BY chunk_name ASC');
        $statement->execute(['archive' => $archiveId]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    // ------------------------------------------------------------------ Laeufe und Sperre

    /**
     * Versucht, die Archivierungssperre zu uebernehmen: Es darf kein weiterer
     * Lauf mit status 'running' und gueltiger Sperrzeit existieren. Abgelaufene
     * Sperren (Prozessabbruch) werden als 'failed' geschlossen, damit das
     * Postfach nicht dauerhaft blockiert bleibt.
     *
     * @return int|null Job-ID oder null, wenn bereits ein Lauf aktiv ist
     */
    public function acquireJob(int $archiveId, string $now, string $lockedUntil): ?int
    {
        // Verwaiste Laeufe schliessen (Sperre abgelaufen).
        $this->pdo->prepare('UPDATE orvanta_archive_jobs SET status = \'failed\', finished_at = :now, last_error = \'Lauf abgebrochen (Sperre abgelaufen).\'
            WHERE archive_id = :archive AND status = \'running\' AND locked_until < :now2')
            ->execute(['now' => $now, 'archive' => $archiveId, 'now2' => $now]);

        $this->pdo->beginTransaction();
        try {
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM orvanta_archive_jobs WHERE archive_id = :archive AND status = \'running\' AND locked_until >= :now');
            $check->execute(['archive' => $archiveId, 'now' => $now]);
            if ((int) $check->fetchColumn() > 0) {
                $this->pdo->commit();

                return null;
            }
            $this->pdo->prepare('INSERT INTO orvanta_archive_jobs (archive_id, status, locked_until, started_at) VALUES (:archive, \'running\', :until, :now)')
                ->execute(['archive' => $archiveId, 'until' => $lockedUntil, 'now' => $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return $id;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function heartbeatJob(int $jobId, string $lockedUntil): void
    {
        $this->pdo->prepare('UPDATE orvanta_archive_jobs SET locked_until = :until WHERE id = :id AND status = \'running\'')
            ->execute(['until' => $lockedUntil, 'id' => $jobId]);
    }

    public function updateJobCounters(int $jobId, int $processed, int $committed, int $deleted): void
    {
        $this->pdo->prepare('UPDATE orvanta_archive_jobs SET processed_count = :p, committed_count = :c, deleted_count = :d WHERE id = :id')
            ->execute(['p' => $processed, 'c' => $committed, 'd' => $deleted, 'id' => $jobId]);
    }

    public function finishJob(int $jobId, string $status, string $now, ?string $error = null): void
    {
        $this->pdo->prepare('UPDATE orvanta_archive_jobs SET status = :status, finished_at = :now, last_error = :error, locked_until = NULL WHERE id = :id')
            ->execute(['status' => $status, 'now' => $now, 'error' => $error, 'id' => $jobId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lastFinishedJob(int $archiveId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_archive_jobs WHERE archive_id = :archive AND status IN (\'completed\', \'failed\') ORDER BY id DESC LIMIT 1');
        $statement->execute(['archive' => $archiveId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function hasRunningJob(int $archiveId, string $now): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM orvanta_archive_jobs WHERE archive_id = :archive AND status = \'running\' AND locked_until >= :now');
        $statement->execute(['archive' => $archiveId, 'now' => $now]);

        return (int) $statement->fetchColumn() > 0;
    }
}
