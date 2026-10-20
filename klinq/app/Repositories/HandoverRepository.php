<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Übergabeprotokolle (versioniert je Mitarbeiter) und Vorlagen (Baukasten).
 * Snapshots (Mitarbeiter, Positionen, Vorlage) liegen als JSON im Protokoll.
 */
final class HandoverRepository extends BaseRepository
{
    private const SELECT = 'SELECT p.*, e.display_name AS employee_name, e.username AS employee_username, e.personnel_number AS employee_personnel_number,
                                   e.department AS employee_department, e.is_active AS employee_active,
                                   u.display_name AS issuer_display,
                                   sd.stored_name AS signature_stored_name, sd.mime_type AS signature_mime,
                                   pd.stored_name AS pdf_stored_name, pd.original_name AS pdf_original_name, pd.size_bytes AS pdf_size
                            FROM handover_protocols p
                            JOIN employees e ON e.id = p.employee_id
                            LEFT JOIN users u ON u.id = p.issuer_user_id
                            LEFT JOIN documents sd ON sd.id = p.signature_document_id
                            LEFT JOIN documents pd ON pd.id = p.pdf_document_id';

    // ------------------------------------------------------------------ Protokolle

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->decode($this->fetchOne(self::SELECT . ' WHERE p.id = :id', ['id' => $id]));
    }

    /** Alle Versionen eines Mitarbeiters, neueste zuerst. @return array<int,array<string,mixed>> */
    public function forEmployee(int $employeeId): array
    {
        return array_map([$this, 'decode'], $this->fetchAll(self::SELECT . ' WHERE p.employee_id = :e ORDER BY p.version DESC', ['e' => $employeeId]));
    }

    /** Das aktuell gültige (zuletzt unterschriebene) Protokoll. @return array<string,mixed>|null */
    public function currentSigned(int $employeeId): ?array
    {
        return $this->decode($this->fetchOne(self::SELECT . " WHERE p.employee_id = :e AND p.status = 'signed' ORDER BY p.version DESC LIMIT 1", ['e' => $employeeId]));
    }

    /** Offener Entwurf des Mitarbeiters (höchstens einer). @return array<string,mixed>|null */
    public function openDraft(int $employeeId): ?array
    {
        return $this->decode($this->fetchOne(self::SELECT . " WHERE p.employee_id = :e AND p.status = 'draft' ORDER BY p.version DESC LIMIT 1", ['e' => $employeeId]));
    }

    public function nextVersion(int $employeeId): int
    {
        return (int) $this->fetchValue('SELECT COALESCE(MAX(version), 0) + 1 FROM handover_protocols WHERE employee_id = :e', ['e' => $employeeId]);
    }

    /**
     * @param array<string,mixed> $filters q, status, employee_id
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return array_map([$this, 'decode'], $this->fetchAll(self::SELECT . " {$where} ORDER BY p.updated_at DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}", $params));
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM handover_protocols p JOIN employees e ON e.id = p.employee_id {$where}", $params);
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        $out = ['draft' => 0, 'signed' => 0, 'superseded' => 0, 'cancelled' => 0];
        foreach ($this->fetchAll('SELECT status, COUNT(*) AS c FROM handover_protocols GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['c'];
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('handover_protocols', $this->encode($data));
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('handover_protocols', $id, $this->encode($data));
    }

    /** Alle bisher gültigen Protokolle eines Mitarbeiters als abgelöst markieren (außer $keepId). */
    public function supersedeOthers(int $employeeId, int $keepId): int
    {
        return $this->execute("UPDATE handover_protocols SET status = 'superseded' WHERE employee_id = ? AND status = 'signed' AND id <> ?", [$employeeId, $keepId]);
    }

    /**
     * Protokollrelevante Assets eines Mitarbeiters: Artikel mit „Relevant für Übergabeprotokoll“, kein Endstatus.
     * @return array<int,array<string,mixed>>
     */
    public function relevantAssetsForEmployee(int $employeeId): array
    {
        return $this->fetchAll(
            'SELECT a.id, a.inventory_number, a.name, a.serial_number, a.mac_address, a.imei, a.purchase_date, a.expected_return_at,
                    ar.name AS article_name, ar.article_number, m.name AS manufacturer_name,
                    t.name AS asset_type_name, cat.name AS category_name, s.name AS status_name,
                    (SELECT MAX(mv.movement_date) FROM movements mv WHERE mv.asset_id = a.id AND mv.type = \'checkout\' AND mv.status <> \'cancelled\') AS assigned_at
             FROM assets a
             JOIN articles ar ON ar.id = a.article_id
             JOIN asset_types t ON t.id = a.asset_type_id
             JOIN asset_statuses s ON s.id = a.status_id
             LEFT JOIN manufacturers m ON m.id = a.manufacturer_id
             LEFT JOIN asset_categories cat ON cat.id = a.asset_category_id
             WHERE a.employee_id = :e AND ar.is_handover_relevant = 1 AND s.is_final = 0
             ORDER BY t.name, m.name, ar.name, a.inventory_number',
            ['e' => $employeeId]
        );
    }

    /**
     * Mitarbeiter mit protokollrelevanten Assets samt Stand des gültigen Protokolls (für die Übersicht).
     * @return array<int,array<string,mixed>>
     */
    public function employeeOverview(string $q = '', int $limit = 200): array
    {
        $params = [];
        $where = 'WHERE e.is_active = 1';
        if ($q !== '') {
            $where .= ' AND (e.display_name LIKE :q OR e.username LIKE :q OR e.personnel_number LIKE :q OR e.department LIKE :q)';
            $params['q'] = $this->like($q);
        }
        $limit = max(1, min($limit, 1000));

        return $this->fetchAll(
            "SELECT e.id, e.display_name, e.username, e.personnel_number, e.department,
                    (SELECT COUNT(*) FROM assets a JOIN articles ar ON ar.id = a.article_id JOIN asset_statuses s ON s.id = a.status_id
                      WHERE a.employee_id = e.id AND ar.is_handover_relevant = 1 AND s.is_final = 0) AS relevant_count,
                    cur.id AS current_id, cur.version AS current_version, cur.signed_at AS current_signed_at, cur.asset_fingerprint AS current_fingerprint, cur.item_count AS current_item_count,
                    dr.id AS draft_id, dr.version AS draft_version
             FROM employees e
             LEFT JOIN handover_protocols cur ON cur.id = (SELECT id FROM handover_protocols WHERE employee_id = e.id AND status = 'signed' ORDER BY version DESC LIMIT 1)
             LEFT JOIN handover_protocols dr ON dr.id = (SELECT id FROM handover_protocols WHERE employee_id = e.id AND status = 'draft' ORDER BY version DESC LIMIT 1)
             {$where}
             HAVING relevant_count > 0 OR current_id IS NOT NULL OR draft_id IS NOT NULL
             ORDER BY e.display_name
             LIMIT {$limit}",
            $params
        );
    }

    // ------------------------------------------------------------------ Vorlagen

    /** @return array<string,mixed>|null */
    public function findTemplate(int $id): ?array
    {
        return $this->decodeTemplate($this->fetchOne('SELECT t.*, u.display_name AS updated_by_display FROM handover_templates t LEFT JOIN users u ON u.id = t.updated_by WHERE t.id = :id', ['id' => $id]));
    }

    /** Aktive Standardvorlage (Fallback: die zuletzt geänderte). @return array<string,mixed>|null */
    public function defaultTemplate(): ?array
    {
        return $this->decodeTemplate($this->fetchOne('SELECT t.*, u.display_name AS updated_by_display FROM handover_templates t LEFT JOIN users u ON u.id = t.updated_by ORDER BY t.is_default DESC, t.updated_at DESC LIMIT 1'));
    }

    /** @param array<string,mixed> $data */
    public function createTemplate(array $data): int
    {
        if (isset($data['blocks']) && !is_string($data['blocks'])) {
            $data['blocks'] = json_encode($data['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->insertRow('handover_templates', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateTemplate(int $id, array $data): void
    {
        if (isset($data['blocks']) && !is_string($data['blocks'])) {
            $data['blocks'] = json_encode($data['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->updateRow('handover_templates', $id, $data);
    }

    // ------------------------------------------------------------------ intern

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(e.display_name LIKE :q OR e.username LIKE :q OR e.personnel_number LIKE :q OR p.protocol_number LIKE :q)';
            $params['q'] = $this->like((string) $filters['q']);
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $clauses[] = 'p.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['employee_id'])) {
            $clauses[] = 'p.employee_id = :employee_id';
            $params['employee_id'] = (int) $filters['employee_id'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }

    /** JSON-Spalten für die Anwendung als Arrays bereitstellen. */
    private function decode(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach (['template_snapshot', 'employee_snapshot', 'items'] as $col) {
            $row[$col] = is_string($row[$col] ?? null) ? (json_decode((string) $row[$col], true) ?: []) : ($row[$col] ?? []);
        }

        return $row;
    }

    private function decodeTemplate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['blocks'] = is_string($row['blocks'] ?? null) ? (json_decode((string) $row['blocks'], true) ?: []) : ($row['blocks'] ?? []);

        return $row;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function encode(array $data): array
    {
        foreach (['template_snapshot', 'employee_snapshot', 'items'] as $col) {
            if (array_key_exists($col, $data) && !is_string($data[$col])) {
                $data[$col] = json_encode($data[$col], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $data;
    }
}
