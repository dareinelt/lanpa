<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Gemeldete Netzlaufwerke der Windows-Clients (je Benutzer und Buchstabe).
 * Bewusst ohne MySQL-spezifisches Upsert (auch mit SQLite testbar).
 *
 * Benutzerkennungen werden klein geschrieben gespeichert und gesucht. Windows
 * meldet den Anmeldenamen in gemischter Schreibweise; die Nextcloud-App
 * normalisiert Kennungen ebenfalls auf Kleinbuchstaben
 * (intranet_integration, NetworkDriveService::normalizePayload()). Zugleich
 * bleibt der Index uniq_network_drives_user_letter nutzbar - ein LOWER() auf
 * der Spalte wuerde ihn ausschliessen.
 *
 * @phpstan-type DriveRow array{user_uid:string,display_name:string,drive_letter:string,unc_path:string,domain:string,computer_name:string,reported_at:string}
 */
final class NetworkDriveRepository extends Repository
{
    /**
     * @return list<DriveRow>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT user_uid, display_name, drive_letter, unc_path, domain, computer_name, reported_at
               FROM network_drives ORDER BY user_uid ASC, drive_letter ASC'
        );

        return array_map(self::row(...), $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Gemeldeter Stand eines Benutzers (fuer den Vergleich vor dem Schreiben).
     *
     * @return list<DriveRow>
     */
    public function forUser(string $uid): array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_uid, display_name, drive_letter, unc_path, domain, computer_name, reported_at
               FROM network_drives WHERE user_uid = :uid ORDER BY drive_letter ASC'
        );
        $statement->execute(['uid' => self::normalizeUid($uid)]);

        return array_map(self::row(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Ersetzt den gemeldeten Stand eines Benutzers vollstaendig.
     *
     * @param list<array{letter:string,unc:string}> $drives
     */
    public function replaceForUser(string $uid, string $displayName, array $drives, string $domain, string $computer): void
    {
        $uid = self::normalizeUid($uid);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM network_drives WHERE user_uid = :uid')->execute(['uid' => $uid]);
            $insert = $this->pdo->prepare(
                'INSERT INTO network_drives (user_uid, display_name, drive_letter, unc_path, domain, computer_name)
                 VALUES (:uid, :name, :letter, :unc, :domain, :computer)'
            );
            foreach ($drives as $drive) {
                $insert->execute([
                    'uid' => $uid,
                    'name' => $displayName,
                    'letter' => $drive['letter'],
                    'unc' => $drive['unc'],
                    'domain' => $domain,
                    'computer' => $computer,
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Fuehrt eine unveraenderte Meldung nach: Meldezeitpunkt und die
     * Anzeigedaten der Uebersicht (die Liste "letzte Meldung" darf nicht
     * veralten). Die Laufwerke selbst bleiben unberuehrt.
     */
    public function touchForUser(string $uid, string $displayName, string $domain, string $computer): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE network_drives
                SET display_name = :name, domain = :domain, computer_name = :computer, reported_at = CURRENT_TIMESTAMP
              WHERE user_uid = :uid'
        );
        $statement->execute([
            'uid' => self::normalizeUid($uid),
            'name' => $displayName,
            'domain' => $domain,
            'computer' => $computer,
        ]);

        return $statement->rowCount();
    }

    public function deleteForUser(string $uid): int
    {
        $statement = $this->pdo->prepare('DELETE FROM network_drives WHERE user_uid = :uid');
        $statement->execute(['uid' => self::normalizeUid($uid)]);

        return $statement->rowCount();
    }

    private static function normalizeUid(string $uid): string
    {
        return strtolower($uid);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return DriveRow
     */
    private static function row(array $row): array
    {
        return [
            'user_uid' => (string) $row['user_uid'],
            'display_name' => (string) $row['display_name'],
            'drive_letter' => strtoupper((string) $row['drive_letter']),
            'unc_path' => (string) $row['unc_path'],
            'domain' => (string) $row['domain'],
            'computer_name' => (string) $row['computer_name'],
            'reported_at' => (string) ($row['reported_at'] ?? ''),
        ];
    }
}
