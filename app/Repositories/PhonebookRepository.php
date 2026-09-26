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
     * Es werden bewusst Praefix-Suchen (`term%`) verwendet, damit die Indizes
     * greifen; nur die Telefonnummer wird als Teilstring gesucht.
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

        $sql = 'SELECT id, identity_source_id, display_name, first_name, last_name, phone, mobile, email, department, ad_modified, synced_at
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
            $prefix = $this->escapeLike($token) . '%';
            $digits = Validator::normalizePhone($token);

            $parts = [];
            // Jeder Platzhalter darf nur einmal vorkommen (native Prepared Statements).
            foreach (['display_name', 'first_name', 'last_name', 'department'] as $column => $field) {
                $name = 't' . $index . '_' . $column;
                $parts[] = $field . ' LIKE :' . $name;
                $params[$name] = $prefix;
            }

            if ($digits !== '') {
                $parts[] = 'phone_digits LIKE :d' . $index;
                $params['d' . $index] = '%' . $this->escapeLike($digits) . '%';
            }

            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
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
            'SELECT id, identity_source_id, external_id, samaccount_name, display_name, first_name, last_name, email, department
               FROM phonebook
              WHERE active = 1 AND identity_source_id = :source AND samaccount_name IS NOT NULL AND LOWER(samaccount_name) = LOWER(:name)
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
        $statement = $this->pdo->prepare(
            'INSERT INTO phonebook
                (external_id, identity_source_id, samaccount_name, display_name, first_name, last_name, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active)
             VALUES
                (:external_id, :identity_source_id, :samaccount_name, :display_name, :first_name, :last_name, :phone, :phone_digits, :mobile, :email, :department, :ad_modified, :synced_at, 1)
             ON DUPLICATE KEY UPDATE
                identity_source_id = VALUES(identity_source_id),
                samaccount_name = VALUES(samaccount_name),
                display_name = VALUES(display_name),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                phone = VALUES(phone),
                phone_digits = VALUES(phone_digits),
                mobile = VALUES(mobile),
                email = VALUES(email),
                department = VALUES(department),
                ad_modified = VALUES(ad_modified),
                synced_at = VALUES(synced_at),
                active = 1'
        );

        $statement->execute([
            'external_id' => (string) $user['external_id'],
            'identity_source_id' => (int) ($user['identity_source_id'] ?? 0),
            'samaccount_name' => $this->value($user['samaccount_name'] ?? null),
            'display_name' => (string) ($user['display_name'] ?? ''),
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'phone' => $user['phone'] ?? null,
            'phone_digits' => Validator::normalizePhone($user['phone'] ?? null),
            'mobile' => $user['mobile'] ?? null,
            'email' => $user['email'] ?? null,
            'department' => $user['department'] ?? null,
            'ad_modified' => $user['ad_modified'] ?? null,
            'synced_at' => $syncedAt,
        ]);
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
            'SELECT id, external_id, identity_source_id, samaccount_name, display_name, first_name, last_name, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active, visible FROM phonebook ORDER BY id ASC'
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
                (external_id, identity_source_id, samaccount_name, display_name, first_name, last_name, phone, phone_digits, mobile, email, department, ad_modified, synced_at, active, visible)
             VALUES
                (:external_id, :identity_source_id, :samaccount_name, :display_name, :first_name, :last_name, :phone, :phone_digits, :mobile, :email, :department, :ad_modified, :synced_at, :active, :visible)'
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
