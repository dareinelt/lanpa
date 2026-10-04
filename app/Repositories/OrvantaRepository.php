<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Orvanta: lokale Einstellungen (Tabelle orvanta_settings), zwischengespeicherte
 * Terminerinnerungen (orvanta_reminders) und der Bestand des Zwischenspeichers im
 * Nextcloud-Bereich der Benutzer (orvanta_cache_items). SQL ist so gehalten, dass
 * es sowohl auf MySQL als auch auf SQLite (Tests) laeuft.
 */
final class OrvantaRepository extends Repository
{
    /**
     * @return array<string,string>
     */
    public function settings(): array
    {
        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM orvanta_settings')?->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $settings = [];
        foreach ($rows as $key => $value) {
            $settings[(string) $key] = (string) $value;
        }

        return $settings;
    }

    /**
     * @param array<string,string> $values
     */
    public function saveSettings(array $values): void
    {
        $select = $this->pdo->prepare('SELECT id FROM orvanta_settings WHERE setting_key = :key');
        $update = $this->pdo->prepare('UPDATE orvanta_settings SET setting_value = :value WHERE setting_key = :key');
        $insert = $this->pdo->prepare('INSERT INTO orvanta_settings (setting_key, setting_value) VALUES (:key, :value)');

        $this->pdo->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                $select->execute(['key' => $key]);
                if ($select->fetchColumn() !== false) {
                    $update->execute(['key' => $key, 'value' => $value]);
                } else {
                    $insert->execute(['key' => $key, 'value' => $value]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    // ------------------------------------------------------------------ Erinnerungen

    /**
     * Gleicht die anstehenden Erinnerungen eines Benutzers mit dem Stand aus
     * Exchange ab: neue Termine werden angelegt, veraenderte Zeiten uebernommen,
     * nicht mehr vorhandene (noch nicht ausgelieferte) Eintraege entfernt.
     *
     * @param list<array{item_id:string,subject:string,location:string,starts_at:string,remind_at:string}> $items
     */
    public function syncReminders(string $uid, array $items): void
    {
        $hashes = [];
        $select = $this->pdo->prepare('SELECT id, state, starts_at, remind_at FROM orvanta_reminders WHERE user_uid = :uid AND item_hash = :hash');
        $insert = $this->pdo->prepare('INSERT INTO orvanta_reminders (user_uid, item_id, item_hash, subject, location, starts_at, remind_at, state)
            VALUES (:uid, :item_id, :hash, :subject, :location, :starts_at, :remind_at, \'pending\')');
        $update = $this->pdo->prepare('UPDATE orvanta_reminders SET subject = :subject, location = :location, starts_at = :starts_at, remind_at = :remind_at, state = :state
            WHERE id = :id');

        $this->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $hash = sha1($item['item_id']);
                $hashes[] = $hash;
                $select->execute(['uid' => $uid, 'hash' => $hash]);
                $existing = $select->fetch(PDO::FETCH_ASSOC);
                if ($existing === false) {
                    $insert->execute([
                        'uid' => $uid,
                        'item_id' => $item['item_id'],
                        'hash' => $hash,
                        'subject' => $item['subject'],
                        'location' => $item['location'],
                        'starts_at' => $item['starts_at'],
                        'remind_at' => $item['remind_at'],
                    ]);
                    continue;
                }
                // Verschobener Termin: Erinnerung erneut faellig stellen.
                $moved = (string) $existing['starts_at'] !== $item['starts_at'];
                $state = $moved && in_array((string) $existing['state'], ['delivered', 'dismissed'], true) ? 'pending' : (string) $existing['state'];
                $update->execute([
                    'id' => (int) $existing['id'],
                    'subject' => $item['subject'],
                    'location' => $item['location'],
                    'starts_at' => $item['starts_at'],
                    'remind_at' => $state === 'snoozed' ? (string) $existing['remind_at'] : $item['remind_at'],
                    'state' => $state,
                ]);
            }

            // Entfernte oder ausserhalb des Fensters liegende Termine (nicht ausgeliefert) loeschen.
            $delete = 'DELETE FROM orvanta_reminders WHERE user_uid = :uid AND state IN (\'pending\', \'snoozed\')';
            $params = ['uid' => $uid];
            if ($hashes !== []) {
                $placeholders = [];
                foreach ($hashes as $index => $hash) {
                    $placeholders[] = ':h' . $index;
                    $params['h' . $index] = $hash;
                }
                $delete .= ' AND item_hash NOT IN (' . implode(', ', $placeholders) . ')';
            }
            $this->pdo->prepare($delete)->execute($params);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Faellige, noch nicht ausgelieferte Erinnerungen (remind_at <= now).
     *
     * @return list<array<string,mixed>>
     */
    public function dueReminders(string $uid, string $now): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_reminders WHERE user_uid = :uid AND state IN (\'pending\', \'snoozed\') AND remind_at <= :now ORDER BY starts_at ASC LIMIT 20');
        $statement->execute(['uid' => $uid, 'now' => $now]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ausgelieferte, noch nicht verworfene Erinnerungen (fuer die Mitteilungen im Kopfbereich).
     *
     * @return list<array<string,mixed>>
     */
    public function activeReminders(string $uid): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_reminders WHERE user_uid = :uid AND state = \'delivered\' ORDER BY starts_at ASC LIMIT 20');
        $statement->execute(['uid' => $uid]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<int> $ids
     */
    public function markDelivered(string $uid, array $ids, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_reminders SET state = \'delivered\', delivered_at = :now WHERE user_uid = :uid AND id = :id');
        foreach ($ids as $id) {
            $statement->execute(['uid' => $uid, 'id' => $id, 'now' => $now]);
        }
    }

    public function dismissReminder(string $uid, int $id, string $now): bool
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_reminders SET state = \'dismissed\', dismissed_at = :now WHERE user_uid = :uid AND id = :id');
        $statement->execute(['uid' => $uid, 'id' => $id, 'now' => $now]);

        return $statement->rowCount() > 0;
    }

    public function snoozeReminder(string $uid, int $id, string $remindAt): bool
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_reminders SET state = \'snoozed\', remind_at = :remind_at WHERE user_uid = :uid AND id = :id');
        $statement->execute(['uid' => $uid, 'id' => $id, 'remind_at' => $remindAt]);

        return $statement->rowCount() > 0;
    }

    /**
     * Entfernt verworfene bzw. laengst vergangene Eintraege.
     */
    public function purgeReminders(string $before): int
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_reminders WHERE starts_at < :before');
        $statement->execute(['before' => $before]);

        return $statement->rowCount();
    }

    // ------------------------------------------------------------------ Zwischenspeicher (Nextcloud)

    public function cacheUsage(string $uid): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM orvanta_cache_items WHERE user_uid = :uid');
        $statement->execute(['uid' => $uid]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findCacheItem(string $uid, string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_cache_items WHERE user_uid = :uid AND item_hash = :hash');
        $statement->execute(['uid' => $uid, 'hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function addCacheItem(string $uid, string $kind, string $hash, string $name, string $path, int $size, string $contentType = 'application/octet-stream'): void
    {
        $statement = $this->pdo->prepare('INSERT INTO orvanta_cache_items (user_uid, kind, item_hash, name, path, content_type, size_bytes) VALUES (:uid, :kind, :hash, :name, :path, :type, :size)');
        $statement->execute(['uid' => $uid, 'kind' => $kind, 'hash' => $hash, 'name' => $name, 'path' => $path, 'type' => $contentType, 'size' => $size]);
    }

    /**
     * Aelteste Eintraege (fuer die Verdraengung bei erreichtem Quota).
     *
     * @return list<array<string,mixed>>
     */
    public function oldestCacheItems(string $uid, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_cache_items WHERE user_uid = :uid ORDER BY created_at ASC, id ASC LIMIT ' . max(1, $limit));
        $statement->execute(['uid' => $uid]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function deleteCacheItem(int $id): void
    {
        $this->pdo->prepare('DELETE FROM orvanta_cache_items WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function cacheItems(string $uid, int $limit = 200): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_cache_items WHERE user_uid = :uid ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit));
        $statement->execute(['uid' => $uid]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Belegung je Benutzer (Adminuebersicht).
     *
     * @return list<array{user_uid:string,items:int,bytes:int}>
     */
    public function cacheUsagePerUser(int $limit = 100): array
    {
        $rows = $this->pdo->query('SELECT user_uid, COUNT(*) AS items, COALESCE(SUM(size_bytes), 0) AS bytes FROM orvanta_cache_items GROUP BY user_uid ORDER BY bytes DESC LIMIT ' . max(1, $limit))?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'user_uid' => (string) $row['user_uid'],
            'items' => (int) $row['items'],
            'bytes' => (int) $row['bytes'],
        ], $rows);
    }
}
