<?php

declare(strict_types=1);

namespace App\Repositories;

final class EmployeeRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT e.*, l.full_path AS location_path, c.number AS cost_center_number, c.description AS cost_center_name
             FROM employees e
             LEFT JOIN locations l ON l.id = e.location_id
             LEFT JOIN cost_centers c ON c.id = e.cost_center_id
             WHERE e.id = :id',
            ['id' => $id]
        );
    }

    /** Mitarbeiter zu einer E-Mail-Adresse; aktive Datensätze zuerst. @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return $this->fetchOne('SELECT * FROM employees WHERE LOWER(email) = :e ORDER BY is_active DESC, id LIMIT 1', ['e' => $email]);
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->fetchOne('SELECT * FROM employees WHERE username = :u LIMIT 1', ['u' => $username]);
    }

    /** @return array<string,mixed>|null */
    public function findByGuid(string $guid): ?array
    {
        return $this->fetchOne('SELECT * FROM employees WHERE ad_object_guid = :g LIMIT 1', ['g' => $guid]);
    }

    /** @return array<string,mixed>|null */
    public function findByPersonnelNumber(string $number): ?array
    {
        return $this->fetchOne('SELECT * FROM employees WHERE personnel_number = :n LIMIT 1', ['n' => $number]);
    }

    /** Mitarbeiter nur bei eindeutigem Anzeigenamen (Import). @return array<string,mixed>|null */
    public function findUniqueByDisplayName(string $name): ?array
    {
        $rows = $this->fetchAll('SELECT * FROM employees WHERE display_name = :n LIMIT 2', ['n' => $name]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * @param array<string,mixed> $filters q, active, department, location_id, cost_center_id
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(
            "SELECT e.*, l.full_path AS location_path, c.number AS cost_center_number
             FROM employees e
             LEFT JOIN locations l ON l.id = e.location_id
             LEFT JOIN cost_centers c ON c.id = e.cost_center_id
             {$where}
             ORDER BY e.last_name, e.first_name
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM employees e {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForSelect(): array
    {
        return $this->fetchAll('SELECT id, display_name, department, personnel_number FROM employees WHERE is_active = 1 ORDER BY last_name, first_name');
    }

    /** Für die phonetische Autovervollständigung (Melder-Suche); enthält zusätzlich Benutzername für die Ergebnisanzeige. @return array<int,array<string,mixed>> */
    public function activeForAutocomplete(): array
    {
        return $this->fetchAll('SELECT id, display_name, username, department, personnel_number FROM employees WHERE is_active = 1 ORDER BY last_name, first_name');
    }

    /** @return array<int,string> */
    public function departments(): array
    {
        return array_column($this->fetchAll("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> '' ORDER BY department"), 'department');
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('employees', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('employees', $id, $data);
    }

    /** @return array<int,array<string,mixed>> Alle AD-Mitarbeiter (für Synchronisation) */
    public function allFromAd(): array
    {
        return $this->fetchAll("SELECT * FROM employees WHERE source = 'ad'");
    }

    public function countActive(): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM employees WHERE is_active = 1');
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(e.display_name LIKE :q OR e.username LIKE :q OR e.email LIKE :q OR e.personnel_number LIKE :q OR e.department LIKE :q)';
            $params['q'] = $this->like((string) $filters['q']);
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $clauses[] = 'e.is_active = :active';
            $params['active'] = (int) $filters['active'];
        }
        if (!empty($filters['department'])) {
            $clauses[] = 'e.department = :department';
            $params['department'] = $filters['department'];
        }
        if (!empty($filters['location_id'])) {
            $clauses[] = 'e.location_id = :location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }
        if (!empty($filters['cost_center_id'])) {
            $clauses[] = 'e.cost_center_id = :cost_center_id';
            $params['cost_center_id'] = (int) $filters['cost_center_id'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
