<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Zusaetzlich berechtigte Postfaecher der Mail-App Orvanta (Tabelle
 * `orvanta_shared_mailboxes`).
 *
 * Der Adminbereich ordnet einem Benutzer (Schluessel: Office-Kennung aus der
 * Anmeldung) weitere Postfaecher zu, die er auf dem Exchange-Server per
 * Vollzugriff bzw. "Senden als" nutzen darf. Ob ein Postfach tatsaechlich
 * erreichbar ist, stellt Orvanta ueber EWS fest und haelt das Ergebnis in
 * `verified_at`/`verify_error` fest.
 *
 * @phpstan-type SharedMailboxRow array{id:int,uid:string,email:string,display_name:string,source:string,send_as:bool,active:bool,sort_order:int,verified_at:string,verify_error:string,checked_at:string,calendar_visible:bool,verified:bool}
 */
final class OrvantaSharedMailboxRepository extends Repository
{
    /** Herkunft: im Adminbereich gepflegt. */
    public const SOURCE_ADMIN = 'admin';

    /** Herkunft: aus dem AD uebernommen (Auto-Mapping, msExchDelegateListBL). */
    public const SOURCE_EXCHANGE = 'exchange';

    private const COLUMNS = 'id, user_uid, email, display_name, source, send_as, active, sort_order, verified_at, verify_error, checked_at, calendar_visible';

    /**
     * Zuordnungen eines Benutzers; die Office-Kennung wird ohne Beachtung der
     * Gross-/Kleinschreibung verglichen (Anmeldung und Administration
     * schreiben sie nicht immer gleich).
     *
     * @return list<SharedMailboxRow>
     */
    public function forUser(string $uid, bool $activeOnly = true): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM orvanta_shared_mailboxes
              WHERE LOWER(user_uid) = LOWER(:uid)' . ($activeOnly ? ' AND active = 1' : '')
            . ' ORDER BY sort_order ASC, email ASC, id ASC'
        );
        $statement->execute(['uid' => $uid]);

        return array_map([self::class, 'hydrate'], $statement->fetchAll());
    }

    /**
     * Alle Zuordnungen (Adminbereich), wahlweise auf einen Benutzer begrenzt.
     *
     * @return list<SharedMailboxRow>
     */
    public function all(string $uid = ''): array
    {
        if ($uid === '') {
            $statement = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM orvanta_shared_mailboxes ORDER BY user_uid ASC, sort_order ASC, email ASC, id ASC');

            return array_map([self::class, 'hydrate'], $statement === false ? [] : $statement->fetchAll());
        }

        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM orvanta_shared_mailboxes WHERE LOWER(user_uid) = LOWER(:uid) ORDER BY sort_order ASC, email ASC, id ASC'
        );
        $statement->execute(['uid' => $uid]);

        return array_map([self::class, 'hydrate'], $statement->fetchAll());
    }

    /**
     * @return SharedMailboxRow|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM orvanta_shared_mailboxes WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Benutzer fuer die Auswahl im Adminbereich: durchsucht den synchronisierten
     * AD-Bestand (Telefonliste) und bildet die Office-Kennung wie die Anmeldung
     * (SamAccountName der Hauptquelle, sonst "name@KENNUNG").
     *
     * @return list<array{uid:string,username:string,display_name:string,email:string,source:string}>
     */
    public function searchUsers(string $term, int $limit = 20): array
    {
        // Getrennte Platzhalter je Vergleich: native Prepares erlauben keine
        // Wiederverwendung benannter Platzhalter.
        $statement = $this->pdo->prepare(
            "SELECT p.samaccount_name, p.display_name, p.email, s.source_key, s.label
               FROM phonebook p
               LEFT JOIN identity_sources s ON s.id = p.identity_source_id
              WHERE p.active = 1 AND p.samaccount_name IS NOT NULL AND p.samaccount_name <> ''
                AND (LOWER(p.display_name) LIKE :name ESCAPE '!' OR LOWER(p.samaccount_name) LIKE :account ESCAPE '!' OR LOWER(p.email) LIKE :mail ESCAPE '!')
              ORDER BY p.display_name ASC, p.id ASC LIMIT " . max(1, min(50, $limit))
        );
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term))) . '%';
        $statement->execute(['name' => $like, 'account' => $like, 'mail' => $like]);

        $users = [];
        foreach ($statement->fetchAll() as $row) {
            $username = (string) $row['samaccount_name'];
            $key = strtoupper(trim((string) ($row['source_key'] ?? '')));

            $users[] = [
                'uid' => $key === '' ? $username : $username . '@' . $key,
                'username' => $username,
                'display_name' => (string) ($row['display_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'source' => trim((string) ($row['label'] ?? '')) !== '' ? (string) $row['label'] : 'Hauptquelle',
            ];
        }

        return $users;
    }

    /**
     * Zuordnung anlegen oder aktualisieren; der Schluessel ist die Kombination
     * aus Office-Kennung und Postfachadresse.
     *
     * @param array{uid:string,email:string,display_name:string,send_as:bool,active:bool,sort_order:int} $data
     */
    public function save(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO orvanta_shared_mailboxes (user_uid, email, display_name, send_as, active, sort_order)
             VALUES (:uid, :email, :display_name, :send_as, :active, :sort_order)
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), send_as = VALUES(send_as),
                 active = VALUES(active), sort_order = VALUES(sort_order)'
        );
        $statement->execute([
            'uid' => $data['uid'],
            'email' => $data['email'],
            'display_name' => $data['display_name'],
            'send_as' => $data['send_as'] ? 1 : 0,
            'active' => $data['active'] ? 1 : 0,
            'sort_order' => $data['sort_order'],
        ]);

        $id = (int) $this->pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $existing = $this->findByKey($data['uid'], $data['email']);

        return $existing['id'] ?? 0;
    }

    /**
     * Aus dem AD gelesene Postfaecher eines Benutzers abgleichen: fehlende
     * anlegen, vorhandene (auch manuell angelegte) als Exchange-Zuordnung
     * fuehren und aktivieren, nicht mehr gelistete Exchange-Zuordnungen
     * entfernen. Pruefstand und Kalender-Auswahl vorhandener Zeilen bleiben
     * erhalten; ein manuell gesetzter Anzeigename gilt weiter, solange das AD
     * keinen liefert.
     *
     * @param list<array{email:string,name:string}> $mailboxes
     * @return bool true, wenn sich der Bestand geaendert hat
     */
    public function syncDiscovered(string $uid, array $mailboxes): bool
    {
        $changed = false;
        $seen = [];
        $existing = [];
        foreach ($this->forUser($uid, false) as $row) {
            $existing[strtolower($row['email'])] = $row;
        }
        $position = 0;
        foreach ($mailboxes as $mailbox) {
            $email = strtolower(trim($mailbox['email']));
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $position++;
            $name = mb_substr(trim($mailbox['name']), 0, 190);
            $row = $existing[$email] ?? null;
            if ($row === null) {
                $this->save([
                    'uid' => $uid,
                    'email' => $email,
                    'display_name' => $name,
                    'send_as' => true,
                    'active' => true,
                    'sort_order' => $position,
                ]);
                $this->setSource($uid, $email, self::SOURCE_EXCHANGE);
                $changed = true;
                continue;
            }
            if ($name === '') {
                $name = $row['display_name'];
            }
            if ($row['source'] !== self::SOURCE_EXCHANGE || !$row['active'] || $row['display_name'] !== $name) {
                $statement = $this->pdo->prepare(
                    'UPDATE orvanta_shared_mailboxes SET source = :source, active = 1, display_name = :display_name WHERE id = :id'
                );
                $statement->execute(['source' => self::SOURCE_EXCHANGE, 'display_name' => $name, 'id' => $row['id']]);
                $changed = true;
            }
        }
        foreach ($existing as $email => $row) {
            if ($row['source'] === self::SOURCE_EXCHANGE && !isset($seen[$email])) {
                $this->delete($row['id']);
                $changed = true;
            }
        }

        return $changed;
    }

    private function setSource(string $uid, string $email, string $source): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE orvanta_shared_mailboxes SET source = :source WHERE LOWER(user_uid) = LOWER(:uid) AND LOWER(email) = LOWER(:email)'
        );
        $statement->execute(['source' => $source, 'uid' => $uid, 'email' => $email]);
    }

    /**
     * @return SharedMailboxRow|null
     */
    public function findByKey(string $uid, string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM orvanta_shared_mailboxes WHERE LOWER(user_uid) = LOWER(:uid) AND LOWER(email) = LOWER(:email) LIMIT 1'
        );
        $statement->execute(['uid' => $uid, 'email' => $email]);
        $row = $statement->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_shared_mailboxes WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Ergebnis der EWS-Pruefung festhalten (leerer Fehler = erreichbar).
     */
    public function markVerified(int $id, string $error = ''): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE orvanta_shared_mailboxes SET verify_error = :error, checked_at = NOW(), verified_at = '
            . ($error === '' ? 'NOW()' : 'NULL') . ' WHERE id = :id'
        );
        $statement->execute(['id' => $id, 'error' => mb_substr($error, 0, 500)]);
    }

    /**
     * Pruefung zuruecksetzen: Die Zuordnung gilt als nicht bestaetigt und
     * wird bei der naechsten Anmeldung des Benutzers erneut geprueft.
     */
    public function markPending(int $id, string $note): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_shared_mailboxes SET verify_error = :note, checked_at = NULL, verified_at = NULL WHERE id = :id');
        $statement->execute(['id' => $id, 'note' => mb_substr($note, 0, 500)]);
    }

    /**
     * Postfachadresse eines Benutzers aus dem Telefonbuch (AD-Bestand); die
     * Office-Kennung ist "name" (Hauptquelle) oder "name@KENNUNG" (weitere
     * Identitaetsquelle, wie in searchUsers()). Leer = nicht bekannt.
     */
    public function userAddress(string $uid): string
    {
        $uid = trim($uid);
        $position = strrpos($uid, '@');
        $username = $position === false ? $uid : substr($uid, 0, $position);
        $source = $position === false ? '' : substr($uid, $position + 1);
        if ($username === '') {
            return '';
        }
        $statement = $this->pdo->prepare(
            "SELECT p.email
               FROM phonebook p
               LEFT JOIN identity_sources s ON s.id = p.identity_source_id
              WHERE p.active = 1 AND LOWER(p.samaccount_name) = :username
                AND LOWER(COALESCE(s.source_key, '')) = :source
                AND p.email IS NOT NULL AND p.email <> ''
              ORDER BY p.id ASC LIMIT 1"
        );
        $statement->execute(['username' => mb_strtolower($username), 'source' => mb_strtolower($source)]);

        return trim((string) ($statement->fetchColumn() ?: ''));
    }

    /**
     * Checkbox im Kalender des Benutzers speichern.
     */
    public function setCalendarVisible(int $id, bool $visible): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_shared_mailboxes SET calendar_visible = :visible WHERE id = :id');
        $statement->execute(['id' => $id, 'visible' => $visible ? 1 : 0]);
    }

    /**
     * @param array<string,mixed> $row
     * @return SharedMailboxRow
     */
    private static function hydrate(array $row): array
    {
        $error = (string) ($row['verify_error'] ?? '');
        $verifiedAt = (string) ($row['verified_at'] ?? '');

        return [
            'id' => (int) $row['id'],
            'uid' => (string) $row['user_uid'],
            'email' => (string) $row['email'],
            'display_name' => (string) ($row['display_name'] ?? ''),
            'source' => (string) ($row['source'] ?? self::SOURCE_ADMIN),
            'send_as' => (int) ($row['send_as'] ?? 1) === 1,
            'active' => (int) ($row['active'] ?? 1) === 1,
            'sort_order' => (int) ($row['sort_order'] ?? 1),
            'verified_at' => $verifiedAt,
            'verify_error' => $error,
            'checked_at' => (string) ($row['checked_at'] ?? ''),
            'calendar_visible' => (int) ($row['calendar_visible'] ?? 0) === 1,
            'verified' => $error === '' && $verifiedAt !== '',
        ];
    }
}
