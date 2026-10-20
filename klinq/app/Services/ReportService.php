<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Repositories\ReportRepository;
use App\Security\CurrentUser;
use App\Support\CsvWriter;

/**
 * Berichte: Inventarliste, Bestandsauswertungen, Entnahmen und Retouren – als Tabelle und CSV.
 * Alle Berichte arbeiten auf denselben Filtern wie die zugehörigen Listen, damit Ansicht und Export übereinstimmen.
 */
final class ReportService
{
    /** Obergrenze je Export, damit ein Bericht nicht den Speicher sprengt */
    public const EXPORT_LIMIT = 50000;
    /** Standardzeitraum für Bewegungsberichte in Tagen */
    public const DEFAULT_PERIOD_DAYS = 90;
    public const STATUS_LABELS = ['open' => 'Offen', 'completed' => 'Abgeschlossen', 'cancelled' => 'Storniert'];

    public const REPORTS = [
        'inventory' => ['label' => 'Inventarliste', 'description' => 'Alle Assets mit Typ, Status, Mitarbeiter, Standort, Kostenstelle, Kauf- und Garantiedaten.', 'icon' => 'box'],
        'stock' => ['label' => 'Bestandsbericht', 'description' => 'Bestand je Status, Assettyp, Standort und Kostenstelle inkl. Anschaffungswert.', 'icon' => 'chart'],
        'checkouts' => ['label' => 'Entnahmen', 'description' => 'Ausgaben an Mitarbeiter im Zeitraum – mit Standort, Kostenstelle, Quelle und Status.', 'icon' => 'checkout'],
        'returns' => ['label' => 'Retouren', 'description' => 'Rückgaben im Zeitraum – mit Zustand, Schäden, Zubehör und Einlagerungsort.', 'icon' => 'return'],
    ];

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly AssetRepository $assets,
        private readonly MovementRepository $movements,
        private readonly LocationRepository $locations,
        private readonly CurrentUser $currentUser
    ) {}

    // ------------------------------------------------------------------ Inventarliste

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
    public function inventory(array $filters, int $limit = self::EXPORT_LIMIT, int $offset = 0, string $sort = 'inventory_number', string $dir = 'asc'): array
    {
        return $this->assets->search($this->inventoryFilters($filters), $limit, $offset, $sort, $dir);
    }

    /** @param array<string,mixed> $filters */
    public function inventoryCount(array $filters): int
    {
        return $this->assets->countSearch($this->inventoryFilters($filters));
    }

    /** Standortfilter schließt untergeordnete Standorte ein. @param array<string,mixed> $filters @return array<string,mixed> */
    private function inventoryFilters(array $filters): array
    {
        $filters['status'] = ($filters['status'] ?? '') !== '' ? $filters['status'] : 'active';
        if (!empty($filters['location_id'])) {
            $filters['location_ids'] = $this->locations->descendantIds((int) $filters['location_id']);
        }

        return $filters;
    }

    /** @param array<string,mixed> $filters */
    public function inventoryCsv(array $filters): string
    {
        $this->currentUser->require('reports.export');
        $rows = $this->inventory($filters);

        return CsvWriter::build(
            ['Inventarnummer', 'Bezeichnung', 'Assettyp', 'Kategorie', 'Hersteller', 'Artikel', 'Seriennummer', 'MAC-Adresse', 'IMEI', 'Status',
             'Mitarbeiter', 'Benutzername', 'Abteilung', 'Standort', 'Kostenstelle', 'Kostenstelle Bezeichnung', 'Kaufdatum', 'Kaufpreis', 'Garantie bis',
             'Lieferant', 'Bestellung', 'Rückgabe erwartet', 'Übergeordnetes Asset', 'Altbestand', 'Bemerkung', 'Angelegt am', 'Geändert am'],
            (function () use ($rows): \Generator {
                foreach ($rows as $a) {
                    yield [
                        $a['inventory_number'], $a['name'], $a['asset_type_name'], $a['category_name'], $a['manufacturer_name'], $a['article_name'],
                        $a['serial_number'], $a['mac_address'], $a['imei'], $a['status_name'],
                        $a['employee_name'], $a['employee_username'], $a['employee_department'], $a['location_path'], $a['cost_center_number'], $a['cost_center_name'],
                        self::date($a['purchase_date']), self::decimal($a['purchase_price']), self::date($a['warranty_until']),
                        $a['supplier_name'], $a['order_number'], self::date($a['expected_return_at']), $a['parent_inventory_number'], (bool) $a['is_legacy'], $a['note'],
                        self::datetime($a['created_at']), self::datetime($a['updated_at']),
                    ];
                }
            })()
        );
    }

    // ------------------------------------------------------------------ Bestandsbericht

    /** @return array<string,mixed> */
    public function stock(): array
    {
        return [
            'by_status' => $this->reports->stockByStatus(),
            'by_type' => $this->reports->stockByType(),
            'by_location' => $this->reports->stockByLocation(),
            'by_cost_center' => $this->reports->stockByCostCenter(),
            'unassigned' => $this->reports->unassignedCounts(),
        ];
    }

    public function stockCsv(string $section): string
    {
        $this->currentUser->require('reports.export');
        $stock = $this->stock();
        switch ($section) {
            case 'type':
                return CsvWriter::build(
                    ['Assettyp', 'Code', 'Gesamt', 'Lagerbestand', 'Ausgegeben', 'Defekt/Reparatur', 'Ausgemustert/Entsorgt', 'Anschaffungswert (aktiv)'],
                    array_map(static fn (array $r): array => [$r['name'], $r['code'], (int) $r['total'], (int) $r['in_stock'], (int) $r['issued'], (int) $r['defective'], (int) $r['final_count'], self::decimal($r['purchase_value'])], $stock['by_type'])
                );
            case 'location':
                return CsvWriter::build(
                    ['Standort', 'Gesamt', 'Lagerbestand', 'Ausgegeben', 'Defekt/Reparatur'],
                    array_map(static fn (array $r): array => [$r['full_path'], (int) $r['total'], (int) $r['in_stock'], (int) $r['issued'], (int) $r['defective']], $stock['by_location'])
                );
            case 'cost_center':
                return CsvWriter::build(
                    ['Kostenstelle', 'Bezeichnung', 'Gesamt', 'Ausgegeben', 'Anschaffungswert'],
                    array_map(static fn (array $r): array => [$r['number'], $r['description'], (int) $r['total'], (int) $r['issued'], self::decimal($r['purchase_value'])], $stock['by_cost_center'])
                );
            case 'status':
                return CsvWriter::build(
                    ['Status', 'Code', 'Anzahl', 'Endgültig'],
                    array_map(static fn (array $r): array => [$r['name'], $r['code'], (int) $r['total'], (bool) $r['is_final']], $stock['by_status'])
                );
        }
        throw ValidationException::single('section', 'Unbekannter Berichtsabschnitt.');
    }

    // ------------------------------------------------------------------ Entnahmen / Retouren

    /**
     * Filter für Bewegungsberichte normalisieren: Typ fest, Zeitraum mit Standard, Status „alle außer storniert“ wählbar.
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function movementFilters(string $type, array $input): array
    {
        $from = self::validDate($input['date_from'] ?? null) ?? date('Y-m-d', strtotime('-' . self::DEFAULT_PERIOD_DAYS . ' days'));
        $to = self::validDate($input['date_to'] ?? null) ?? date('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $status = (string) ($input['status'] ?? '');

        return [
            'type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'status' => in_array($status, ['open', 'completed', 'cancelled', 'all'], true) ? $status : '',
            'employee_id' => $input['employee_id'] ?? '',
            'location_id' => $input['location_id'] ?? '',
            'cost_center_id' => $input['cost_center_id'] ?? '',
            'asset_type_id' => $input['asset_type_id'] ?? '',
            'source' => $input['source'] ?? '',
            'q' => trim((string) ($input['q'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
    public function movements(array $filters, int $limit = self::EXPORT_LIMIT, int $offset = 0): array
    {
        return $this->movements->search($filters, $limit, $offset);
    }

    /** @param array<string,mixed> $filters */
    public function movementsCount(array $filters): int
    {
        return $this->movements->countSearch($filters);
    }

    /** Kennzahlen zum Zeitraum eines Bewegungsberichts. @param array<string,mixed> $filters @return array<string,mixed> */
    public function movementSummary(array $filters): array
    {
        $from = (string) $filters['date_from'];
        $to = (string) $filters['date_to'];

        return [
            'by_month' => $this->reports->movementsByMonth($from, $to),
            'conditions' => $this->reports->returnConditions($from, $to),
            'top_employees' => $this->reports->checkoutsByEmployee($from, $to),
        ];
    }

    /** @param array<string,mixed> $filters */
    public function movementsCsv(array $filters): string
    {
        $this->currentUser->require('reports.export');
        $rows = $this->movements($filters);
        $isReturn = ($filters['type'] ?? '') === 'return';
        $headers = ['Datum', 'Uhrzeit', 'Typ', 'Status', 'Inventarnummer', 'Bezeichnung', 'Assettyp', 'Seriennummer', 'Mitarbeiter', 'Benutzername', 'Abteilung',
                    'Kostenstelle', $isReturn ? 'Einlagerungsort' : 'Standort', 'Quelle', 'Erfasst von', 'Bemerkung'];
        if ($isReturn) {
            $headers = array_merge($headers, ['Zustand', 'Schaden', 'Schadensbeschreibung', 'Zubehör geprüft', 'Zubehör-Hinweis', 'Zielstatus', 'Dokumente']);
        } else {
            $headers = array_merge($headers, ['Von Standort', 'Fehlende Angaben']);
        }

        return CsvWriter::build($headers, (function () use ($rows, $isReturn): \Generator {
            foreach ($rows as $m) {
                $row = [
                    self::date($m['movement_date']), self::time($m['movement_at']),
                    $m['type'] === 'checkout' ? 'Entnahme' : 'Retoure', self::STATUS_LABELS[$m['status']] ?? $m['status'],
                    $m['inventory_number'], $m['asset_name'], $m['asset_type_name'], $m['serial_number'],
                    $m['employee_name'], $m['employee_username'], $m['employee_department'],
                    $m['cost_center_number'], $m['to_location_path'],
                    MovementService::SOURCES[$m['source']] ?? $m['source'], $m['created_by_name'], $m['note'],
                ];
                if ($isReturn) {
                    $row = array_merge($row, [
                        MovementService::CONDITIONS[$m['condition_code']] ?? $m['condition_code'], (bool) $m['has_damage'], $m['damage_description'],
                        (bool) $m['accessories_checked'], $m['accessories_note'], MovementService::RETURN_TARGETS[$m['target_status_code']] ?? $m['target_status_code'], (int) $m['document_count'],
                    ]);
                } else {
                    $missing = is_string($m['missing_fields'] ?? null) ? json_decode($m['missing_fields'], true) : ($m['missing_fields'] ?? []);
                    $row = array_merge($row, [
                        $m['from_location_path'],
                        implode(', ', array_map(static fn (string $k): string => MovementService::MISSING_LABELS[$k] ?? $k, is_array($missing) ? $missing : [])),
                    ]);
                }
                yield $row;
            }
        })());
    }

    // ------------------------------------------------------------------ Hilfen

    public static function date(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? $value : date('d.m.Y', $ts);
    }

    /** Zeitstempel liegen in der DB in UTC – Ausgabe in der konfigurierten Zeitzone. */
    public static function datetime(?string $value, string $format = 'd.m.Y H:i'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = date_create($value, new \DateTimeZone('UTC'));
        if ($dt === false) {
            return $value;
        }

        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
    }

    public static function time(?string $value): ?string
    {
        return self::datetime($value, 'H:i');
    }

    public static function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, ',', '');
    }

    public static function validDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
