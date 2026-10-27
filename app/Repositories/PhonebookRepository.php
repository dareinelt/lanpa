<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhonebookStoreInterface;
use App\Support\Validator;

final class PhonebookRepository extends Repository implements PhonebookStoreInterface
{
    public const MAX_LIMIT = 100;

    /**
     * Eintraege ohne E-Mail-Adresse sind nur fuer angemeldete
     * Administratoren sichtbar.
     */
    private const EMAIL_CONDITION = "(email IS NOT NULL AND email <> '')";

    private const PHONE_CONDITION = "((phone IS NOT NULL AND phone <> '') OR (mobile IS NOT NULL AND mobile <> ''))";

    /**
     * Sucht im lokalen Datenbestand (nicht im AD).
     *
     * Ein Suchbegriff muss am Anfang eines Namensbestandteils stehen: direkt am
     * Anfang des Feldwerts (Praefix `term%`, damit die Indizes greifen) oder
     * nach einem Leerzeichen. Telefonnummer und Personalnummer werden als
     * Praefix gesucht.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function search(string $term, int $limit = 50, int $offset = 0, bool $includeWithoutEmail = false): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildSearchCondition($term, $includeWithoutEmail);

        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM phonebook WHERE ' . $where);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $sql = 'SELECT id, identity_source_id, external_id, samaccount_name, display_name, first_name, last_name, title,
                       personnel_number, phone, mobile, email, department, ad_modified, synced_at
                  FROM phonebook
                 WHERE ' . $where . '
                 ORDER BY last_name ASC, first_name ASC, display_name ASC
                 LIMIT ' . $limit . ' OFFSET ' . $offset;

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string,mixed>> $items */
        $items = $statement->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    public function buildSearchCondition(string $term, bool $includeWithoutEmail = false): array
    {
        $conditions = ['active = 1', 'visible = 1'];
        $params = [];

        if (!$includeWithoutEmail) {
            $conditions[] = self::EMAIL_CONDITION;
        }

        [$termCondition, $termParams] = $this->buildTermCondition($term);
        if ($termCondition !== '') {
            $conditions[] = $termCondition;
            $params = $termParams;
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Baut aus den eingegebenen Suchbegriffen eine LIKE-Bedingung ohne die
     * Aktiv-/Sichtbarkeitsfilter.
     *
     * Je Begriff und Feld greifen zwei Muster: der Praefix (Index bleibt
     * nutzbar) und der Wortanfang nach einem Leerzeichen. Das zweite Muster ist
     * noetig, weil der Vorname nur im Anzeigenamen stehen kann (Format
     * "Nachname, Vorname") – ohne es waere "Ulf" in "Harding, Ulf" nicht
     * auffindbar, solange das Feld `first_name` leer ist.
     *
     * Der Anmeldename wird nur als Praefix gesucht: Dienstkonten wie
     * "Station1.1" stehen in keinem Namensfeld, der Anmeldename enthaelt keine
     * Leerzeichen. Die Telefonnummer wird nur durchsucht, wenn der Begriff
     * ueberhaupt wie eine Telefonnummer aussieht – sonst wuerde aus
     * "Station1.1" die Ziffernfolge "11" und die Trefferliste fuellte sich mit
     * beliebigen Nummern, die diese Ziffern enthalten.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private function buildTermCondition(string $term): array
    {
        $conditions = [];
        $params = [];

        $tokens = preg_split('/\s+/u', trim($term)) ?: [];
        $tokens = array_values(array_filter(array_slice($tokens, 0, 5), static fn (string $t): bool => $t !== ''));

        foreach ($tokens as $index => $token) {
            $token = mb_substr($token, 0, 64);
            $escaped = $this->escapeLike($token);
            $prefix = $escaped . '%';

            $parts = [];
            // Jeder Platzhalter darf nur einmal vorkommen (native Prepared Statements).
            foreach (['display_name', 'first_name', 'last_name', 'department'] as $column => $field) {
                $name = 't' . $index . '_' . $column;
                $parts[] = $field . ' LIKE :' . $name;
                $params[$name] = $prefix;

                $wordName = 'w' . $index . '_' . $column;
                $parts[] = $field . ' LIKE :' . $wordName;
                $params[$wordName] = '% ' . $escaped . '%';
            }

            $parts[] = 'samaccount_name LIKE :s' . $index;
            $params['s' . $index] = $prefix;

            $digits = $this->phoneDigitsFor($token);
            if ($digits !== '') {
                $parts[] = 'phone_digits LIKE :d' . $index;
                $params['d' . $index] = '%' . $this->escapeLike($digits) . '%';
            }

            // Personalnummer: Praefix genuegt und haelt den Index nutzbar.
            $parts[] = 'personnel_number LIKE :p' . $index;
            $params['p' . $index] = $prefix;

            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * Ziffernfolge fuer die Telefonsuche – aber nur, wenn der Begriff
     * ueberhaupt wie eine Telefonnummer aussieht. Buchstaben schliessen die
     * Telefonsuche aus: sonst wuerde aus "Station1.1" die Ziffernfolge "11"
     * und die Trefferliste fuellte sich mit allen Nummern, die diese Ziffern
     * enthalten.
     */
    private function phoneDigitsFor(string $token): string
    {
        if (preg_match('/^[0-9+*#()\/.\-]+$/', $token) !== 1) {
            return '';
        }

        return Validator::normalizePhone($token);
    }

    public function countActive(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM phonebook WHERE active = 1')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Aktive Eintraege je Identitaetsquelle.
     *
     * @return array<int,int> Quell-ID => Anzahl
     */
    public function countActiveBySource(): array
    {
        $counts = [];
        $statement = $this->pdo->query('SELECT identity_source_id, COUNT(*) AS total FROM phonebook WHERE active = 1 GROUP BY identity_source_id');
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $counts[(int) $row['identity_source_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Aktiver Eintrag mit den fuer Signaturen relevanten AD-Feldern.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, identity_source_id, samaccount_name, display_name, first_name, last_name, title, phone, mobile, email, department
               FROM phonebook
              WHERE active = 1 AND id = :id
              LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Aktiver und sichtbarer Eintrag mit den Kennungen fuer die Uebernahme in
     * eine andere Anwendung (z. B. KLINQ): external_id und samaccount_name.
     *
     * @return array<string,mixed>|null
     */
    public function findVisibleById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT id, identity_source_id, external_id, samaccount_name, display_name, first_name, last_name,
                    title, personnel_number, phone, mobile, email, department
               FROM phonebook
              WHERE active = 1 AND visible = 1 AND id = :id
              LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Findet einen aktiven Telefonbucheintrag anhand seines SamAccountNames
     * innerhalb einer Identitaetsquelle (0 = Hauptquelle); SamAccountNames
     * sind nur je Verzeichnis eindeutig. Der Vergleich ist case-insensitiv
     * (SamAccountNames sind im AD nicht case-sensitiv).
     *
     * @return array<string,mixed>|null
     */
    public function findBySamAccountName(string $name, int $sourceId = 0): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, identity_source_id, external_id, samaccount_name, display_name, first_name, last_name, title, phone, email, department
               FROM phonebook
              WHERE active = 1 AND identity_source_id = :source AND samaccount_name IS NOT NULL AND LOWER(samaccount_name) = LOWER(:name)
              LIMIT 1'
        );
        $statement->execute(['name' => $name, 'source' => $sourceId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Wie {@see findBySamAccountName()}, aber ohne Bindung an eine
     * Identitaetsquelle. Die Hauptquelle hat Vorrang, danach gilt die
     * kleinste ID. Wird verwendet, wenn eine Anwendung nur den
     * SamAccountName kennt (z. B. KLINQ fuer die Agentensignatur).
     *
     * @return array<string,mixed>|null
     */
    public function findActiveBySamAccountNameAnySource(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, identity_source_id, external_id, samaccount_name, display_name, first_name, last_name, title, phone, email, department
               FROM phonebook
              WHERE active = 1 AND samaccount_name IS NOT NULL AND LOWER(samaccount_name) = LOWER(:name)
              ORDER BY CASE WHEN identity_source_id = 0 THEN 0 ELSE 1 END, id ASC
              LIMIT 1'
        );
        $statement->execute(['name' => $name]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Kontaktdaten fuer die Uebergabe an KLINQ (Help-Desk-Schnellbericht).
     * Enthaelt zusaetzlich die Mobilnummer.
     *
     * @return array<string,mixed>|null
     */
    public function contactBySamAccountName(string $name, int $sourceId = 0): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, identity_source_id, samaccount_name, display_name, first_name, last_name,
                    title, phone, mobile, email, department
               FROM phonebook
              WHERE active = 1 AND identity_source_id = :source AND samaccount_name IS NOT NULL
                AND LOWER(samaccount_name) = LOWER(:name)
              LIMIT 1'
        );
        $statement->execute(['name' => $name, 'source' => $sourceId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Alle aktiven Eintraege mit hinterlegtem SamAccountName – die Grundlage
     * fuer die benutzerbasierte Kachel-Berechtigung im Adminbereich.
     *
     * @return list<array<string,mixed>>
     */
    public function assignableUsers(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, identity_source_id, samaccount_name, display_name, first_name, last_name, department
               FROM phonebook
              WHERE active = 1 AND samaccount_name IS NOT NULL AND samaccount_name <> ''
              ORDER BY display_name ASC, id ASC"
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * Zaehlt die fuer die Suche sichtbaren Eintraege.
     */
    public function countVisible(bool $includeWithoutEmail = false): int
    {
        $sql = 'SELECT COUNT(*) FROM phonebook WHERE active = 1 AND visible = 1';
        if (!$includeWithoutEmail) {
            $sql .= ' AND ' . self::EMAIL_CONDITION;
        }

        $value = $this->pdo->query($sql)?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    public function lastSyncedAt(): ?string
    {
        $value = $this->pdo->query('SELECT MAX(synced_at) FROM phonebook WHERE active = 1')?->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Die Identitaetsquelle steht in `identity_source_id` des Datensatzes
     * (fehlt sie, gilt die Hauptquelle 0).
     *
     * `visible` wird hier bewusst nicht angefasst: neue Eintraege erhalten
     * ueber den Spalten-Standard `1` (eingeblendet), und die Entscheidung des
     * Administrators bleibt bei erneuten Synchronisationen erhalten.
     *
     * @param array<string,string|null> $user
     */
    public function upsert(array $user, string $syncedAt): void
    {
        $this->upsertMany([$user], $syncedAt);
    }

    /**
     * Sammel-Upsert: eine Anweisung je Block statt einer je Benutzer.
     *
     * @param list<array<string,string|null>> $users
     */
    public function upsertMany(array $users, string $syncedAt): void
    {
        foreach (array_chunk(array_values($users), self::UPSERT_BATCH) as $chunk) {
            $statement = $this->pdo->prepare(self::upsertSql(count($chunk)));
            $statement->execute($this->upsertBindings($chunk, $syncedAt));
        }
    }

    /**
     * Spaltenliste des Upserts - an einer Stelle, damit Einzel- und
     * Sammelschreibweise nicht auseinanderlaufen.
     */
    private const UPSERT_COLUMNS = 'external_id, identity_source_id, samaccount_name, display_name, first_name, last_name, title, personnel_number, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active';

    /**
     * Zeilen je Anweisung: 200 x 16 Platzhalter liegen weit unter dem Limit
     * von MySQL (65535) und SQLite, halten den Speicherbedarf klein und
     * begrenzen die Zahl der Rundlaeufe auf einstellige Werte.
     */
    private const UPSERT_BATCH = 200;

    private static function upsertSql(int $rows): string
    {
        $values = [];
        for ($index = 0; $index < $rows; $index++) {
            $values[] = '(:external_id' . $index . ', :identity_source_id' . $index . ', :samaccount_name' . $index
                . ', :display_name' . $index . ', :first_name' . $index . ', :last_name' . $index
                . ', :title' . $index . ', :personnel_number' . $index
                . ', :phone' . $index . ', :phone_digits' . $index . ', :mobile' . $index
                . ', :email' . $index . ', :department' . $index . ', :ad_modified' . $index
                . ', :synced_at' . $index . ', 1)';
        }

        return 'INSERT INTO phonebook (' . self::UPSERT_COLUMNS . ') VALUES ' . implode(', ', $values) . '
             ON DUPLICATE KEY UPDATE
                identity_source_id = VALUES(identity_source_id),
                samaccount_name = VALUES(samaccount_name),
                display_name = VALUES(display_name),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                title = VALUES(title),
                personnel_number = VALUES(personnel_number),
                phone = VALUES(phone),
                phone_digits = VALUES(phone_digits),
                mobile = VALUES(mobile),
                email = VALUES(email),
                department = VALUES(department),
                ad_modified = VALUES(ad_modified),
                synced_at = VALUES(synced_at),
                active = 1';
    }

    /**
     * @param list<array<string,string|null>> $users
     *
     * @return array<string,string|int|null>
     */
    private function upsertBindings(array $users, string $syncedAt): array
    {
        $bindings = [];
        foreach ($users as $index => $user) {
            $bindings['external_id' . $index] = (string) $user['external_id'];
            $bindings['identity_source_id' . $index] = (int) ($user['identity_source_id'] ?? 0);
            $bindings['samaccount_name' . $index] = $this->value($user['samaccount_name'] ?? null);
            $bindings['display_name' . $index] = (string) ($user['display_name'] ?? '');
            $bindings['first_name' . $index] = $this->value($user['first_name'] ?? null);
            $bindings['last_name' . $index] = $this->value($user['last_name'] ?? null);
            $bindings['title' . $index] = $this->value($user['title'] ?? null);
            $bindings['personnel_number' . $index] = $this->value($user['personnel_number'] ?? null);
            $bindings['phone' . $index] = $this->value($user['phone'] ?? null);
            $bindings['phone_digits' . $index] = Validator::normalizePhone($user['phone'] ?? null);
            $bindings['mobile' . $index] = $this->value($user['mobile'] ?? null);
            $bindings['email' . $index] = $this->value($user['email'] ?? null);
            $bindings['department' . $index] = $this->value($user['department'] ?? null);
            $bindings['ad_modified' . $index] = $this->value($user['ad_modified'] ?? null);
            $bindings['synced_at' . $index] = $syncedAt;
        }

        return $bindings;
    }

    public function deactivateStale(string $syncedAt, int $sourceId = 0): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE phonebook SET active = 0
              WHERE active = 1 AND identity_source_id = :source AND (synced_at IS NULL OR synced_at < :synced_at)'
        );
        $statement->execute(['synced_at' => $syncedAt, 'source' => $sourceId]);

        return $statement->rowCount();
    }

    /**
     * Alle Eintraege inklusive inaktiver – fuer den Export der Sicherung.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, external_id, identity_source_id, samaccount_name, display_name, first_name, last_name, title, personnel_number, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active, visible FROM phonebook ORDER BY id ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * Alle Eintraege (aktiv und inaktiv, ein- und ausgeblendet) fuer den
     * Adminbereich, optional gefiltert nach einem Suchbegriff und
     * Bool-Filtern (has_email, is_active, has_phone, is_visible).
     *
     * @param array{has_email?:bool,is_active?:bool,has_phone?:bool,is_visible?:bool} $filters
     *
     * @return list<array<string,mixed>>
     */
    public function allForAdmin(string $term = '', array $filters = []): array
    {
        $sql = 'SELECT id, identity_source_id, display_name, first_name, last_name, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active, visible
                  FROM phonebook';
        $conditions = [];
        $params = [];

        [$termCondition, $termParams] = $this->buildTermCondition($term);
        if ($termCondition !== '') {
            $conditions[] = $termCondition;
            $params = $termParams;
        }

        if (!empty($filters['has_email'])) {
            $conditions[] = self::EMAIL_CONDITION;
        }

        if (!empty($filters['is_active'])) {
            $conditions[] = 'active = 1';
        }

        if (!empty($filters['has_phone'])) {
            $conditions[] = self::PHONE_CONDITION;
        }

        if (!empty($filters['is_visible'])) {
            $conditions[] = 'visible = 1';
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY last_name ASC, first_name ASC, display_name ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * Eintraege, die in der Telefonliste auch fuer nicht angemeldete Besucher
     * sichtbar sind (aktiv, eingeblendet und mit E-Mail-Adresse) – die
     * Grundlage fuer den CSV-Export im Adminbereich.
     *
     * @return list<array<string,mixed>>
     */
    public function visibleForExport(): array
    {
        $sql = 'SELECT display_name, department, phone, email
                  FROM phonebook
                 WHERE active = 1 AND visible = 1 AND ' . self::EMAIL_CONDITION . '
                 ORDER BY last_name ASC, first_name ASC, display_name ASC';

        $statement = $this->pdo->query($sql);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * Setzt die Sichtbarkeit eines Eintrags. Liefert false, wenn der Eintrag
     * nicht existiert.
     */
    public function setVisible(int $id, bool $visible): bool
    {
        $statement = $this->pdo->prepare('UPDATE phonebook SET visible = :visible WHERE id = :id');
        $statement->execute(['visible' => $visible ? 1 : 0, 'id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Einfaches Einfuegen (ohne Upsert) – fuer den Import, der die Tabelle
     * vorher leert.
     *
     * @param array<string,mixed> $data
     */
    public function insert(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO phonebook
                (external_id, identity_source_id, samaccount_name, display_name, first_name, last_name, title, personnel_number, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active, visible)
             VALUES
                (:external_id, :identity_source_id, :samaccount_name, :display_name, :first_name, :last_name, :title, :personnel_number, :phone, :phone_digits, :mobile, :email, :department, :ad_modified, :synced_at, :active, :visible)'
        );
        $statement->execute($this->bindings($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM phonebook');
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function bindings(array $data): array
    {
        return [
            'external_id' => (string) ($data['external_id'] ?? ''),
            'identity_source_id' => max(0, (int) ($data['identity_source_id'] ?? 0)),
            'samaccount_name' => $this->value($data['samaccount_name'] ?? null),
            'display_name' => (string) ($data['display_name'] ?? ''),
            'first_name' => $this->value($data['first_name'] ?? null),
            'last_name' => $this->value($data['last_name'] ?? null),
            'title' => $this->value($data['title'] ?? null),
            'personnel_number' => $this->value($data['personnel_number'] ?? null),
            'phone' => $this->value($data['phone'] ?? null),
            'phone_digits' => $this->value($data['phone_digits'] ?? null),
            'mobile' => $this->value($data['mobile'] ?? null),
            'email' => $this->value($data['email'] ?? null),
            'department' => $this->value($data['department'] ?? null),
            'ad_modified' => $this->value($data['ad_modified'] ?? null),
            'synced_at' => $this->value($data['synced_at'] ?? null),
            'active' => !empty($data['active']) ? 1 : 0,
            // Beim Import fehlendes/unklares `visible` gilt als eingeblendet (Standard).
            'visible' => ((int) ($data['visible'] ?? 1)) > 0 ? 1 : 0,
        ];
    }

    private function value(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
