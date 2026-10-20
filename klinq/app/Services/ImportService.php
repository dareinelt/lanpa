<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\ImportRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Support\ColognePhonetic;
use App\Support\CsvReader;
use App\Support\CsvWriter;
use App\Support\Validator;

/**
 * CSV-Import von Assets (Migration aus Altsystemen).
 *
 * Ablauf: Upload → Analyse (Vorschau, ohne Schreibzugriff auf Produktivdaten) → Bestätigung → Import.
 * Beim Import wird jede Zeile erneut geprüft; nur fehlerfreie Zeilen werden angelegt. Alles wird protokolliert.
 */
final class ImportService
{
    public const TYPE_ASSETS = 'assets';
    public const MAX_ROWS = 10000;
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const PREVIEW_TTL_HOURS = 24;

    /** Spalten: Schlüssel => [Bezeichnung, Aliasnamen (normalisiert), Pflicht, Hinweis, Beispiel]. */
    public const COLUMNS = [
        'inventory_number' => ['Inventarnummer', ['inventarnummer', 'inventarnr', 'inventar_nr', 'inventory_number', 'inv_nr'], false, 'Leer = automatisch vergeben (Altbestand: Jahreskennung 88)', 'PC88001'],
        'asset_type' => ['Assettyp', ['assettyp', 'typ', 'asset_type', 'type', 'geraetetyp'], true, 'Code (PC, MD, NET, ZUB) oder Name; leer, wenn aus der Inventarnummer ableitbar', 'PC'],
        'category' => ['Kategorie', ['kategorie', 'category'], false, 'Name innerhalb des Assettyps', 'Notebook'],
        'name' => ['Bezeichnung', ['bezeichnung', 'name', 'modell', 'model', 'geraet'], false, '', 'ThinkPad T14 Gen 3'],
        'manufacturer' => ['Hersteller', ['hersteller', 'manufacturer'], true, 'Name; unbekannte Hersteller werden optional angelegt', 'Lenovo'],
        'article' => ['Artikel', ['artikel', 'article', 'artikelnummer', 'article_number'], true, 'Name oder Artikelnummer eines vorhandenen Stammartikels des Herstellers', 'ThinkPad T14 Gen 3'],
        'serial_number' => ['Seriennummer', ['seriennummer', 'seriennr', 'serial', 'serial_number', 'sn', 's_n'], false, 'Eindeutig je Assettyp', 'PF3ABC12'],
        'mac_address' => ['MAC-Adresse', ['mac_adresse', 'mac', 'mac_address'], false, '12 Hexadezimalzeichen', '00:1A:2B:3C:4D:5E'],
        'imei' => ['IMEI', ['imei'], false, '14–16 Ziffern', ''],
        'status' => ['Status', ['status', 'zustand'], false, 'Code oder Name; Standard „Lagerbestand“, mit Mitarbeiter „Ausgegeben“', 'Lagerbestand'],
        'employee' => ['Mitarbeiter', ['mitarbeiter', 'benutzername', 'username', 'employee', 'personalnummer', 'benutzer'], false, 'Benutzername, Personalnummer oder eindeutiger Anzeigename', 'mmustermann'],
        'location' => ['Standort', ['standort', 'location', 'raum', 'ort'], false, 'Pfad „Gebäude / Etage / Raum“, Kürzel oder eindeutiger Name', 'Peine / Gebäude A / IT-Lager'],
        'cost_center' => ['Kostenstelle', ['kostenstelle', 'cost_center', 'kst'], false, 'Fünfstellige Nummer', '12345'],
        'purchase_date' => ['Kaufdatum', ['kaufdatum', 'purchase_date', 'anschaffungsdatum', 'anschaffung'], false, 'TT.MM.JJJJ oder JJJJ-MM-TT', '15.03.2024'],
        'purchase_price' => ['Kaufpreis', ['kaufpreis', 'preis', 'purchase_price', 'anschaffungskosten', 'anschaffungswert'], false, 'Betrag, z. B. 1234,56', '1299,00'],
        'warranty_until' => ['Garantie bis', ['garantie_bis', 'garantie', 'warranty_until', 'garantieende', 'warranty'], false, 'TT.MM.JJJJ oder JJJJ-MM-TT', '14.03.2027'],
        'supplier' => ['Lieferant', ['lieferant', 'supplier', 'haendler'], false, 'Name (muss vorhanden sein, sonst Warnung)', 'Bechtle AG'],
        'legacy' => ['Altbestand', ['altbestand', 'legacy', 'is_legacy'], false, 'Ja/Nein; Standard aus den Importoptionen', 'Ja'],
        'note' => ['Bemerkung', ['bemerkung', 'notiz', 'note', 'kommentar', 'anmerkung'], false, '', 'Aus Altsystem übernommen'],
    ];

    public const STATUS_LABELS = [
        'valid' => 'Gültig', 'warning' => 'Mit Hinweisen', 'error' => 'Fehler', 'duplicate' => 'Dublette',
        'imported' => 'Importiert', 'skipped' => 'Übersprungen',
    ];

    /** @var array<string,mixed> Nachschlage-Caches je Analyse */
    private array $cache = [];

    public function __construct(
        private readonly ImportRepository $imports,
        private readonly AssetService $assets,
        private readonly AssetRepository $assetRepo,
        private readonly AssetTypeRepository $types,
        private readonly AssetStatusRepository $statuses,
        private readonly ManufacturerRepository $manufacturers,
        private readonly ManufacturerService $manufacturerService,
        private readonly ArticleRepository $articles,
        private readonly ArticleService $articleService,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly SupplierRepository $suppliers,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser,
        private readonly Config $config
    ) {}

    // ------------------------------------------------------------------ Upload & Vorschau

    /**
     * Datei entgegennehmen, ablegen, analysieren und als Vorschau-Lauf speichern.
     * @param array<string,mixed>|null $upload $_FILES-Eintrag
     * @param array<string,mixed> $input Optionen aus dem Formular
     */
    public function upload(?array $upload, array $input): int
    {
        $this->currentUser->require('imports.manage');
        if ($upload === null) {
            throw ValidationException::single('file', 'Bitte eine CSV-Datei auswählen.');
        }
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ValidationException::single('file', 'Die Datei ist zu groß.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw ValidationException::single('file', 'Die Datei konnte nicht hochgeladen werden.');
        }
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw ValidationException::single('file', sprintf('Die Datei darf höchstens %d MB groß sein.', self::MAX_BYTES / 1024 / 1024));
        }
        $originalName = basename(str_replace('\\', '/', (string) ($upload['name'] ?? 'import.csv')));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw ValidationException::single('file', 'Es werden nur CSV-Dateien (.csv, .txt) unterstützt.');
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $content = $tmp !== '' && is_readable($tmp) ? (string) file_get_contents($tmp) : '';
        if ($content === '' || str_contains($content, "\0")) {
            throw ValidationException::single('file', 'Die Datei ist leer oder keine Textdatei.');
        }

        $options = $this->normalizeOptions($input);
        $stored = $this->storeFile($content);
        try {
            $runId = $this->imports->createRun([
                'type' => self::TYPE_ASSETS,
                'status' => 'preview',
                'original_name' => mb_substr($originalName, 0, 255),
                'stored_path' => $stored,
                'file_size' => strlen($content),
                'delimiter' => $options['delimiter'] ?? ';',
                'options' => json_encode($options, JSON_UNESCAPED_UNICODE),
                'created_by' => $this->currentUser->id(),
            ]);
        } catch (\Throwable $e) {
            $this->deleteFile($stored);
            throw $e;
        }

        try {
            $this->analyseRun($runId);
        } catch (ValidationException $e) {
            $this->imports->updateRun($runId, ['status' => 'failed', 'error_message' => mb_substr(implode(' ', $e->errors()), 0, 500)]);
            $this->deleteFile($stored);
            throw $e;
        }

        return $runId;
    }

    /** Analyse (erneut) ausführen und Zeilenprotokoll als Vorschau speichern. @return array<string,mixed> Lauf */
    public function analyseRun(int $runId): array
    {
        $run = $this->requireRun($runId);
        $result = $this->analyse($this->readFile($run), self::options($run));
        $counts = self::counts($result['rows']);
        $this->imports->updateRun($runId, [
            'delimiter' => $result['delimiter'],
            'encoding' => $result['encoding'],
            'columns_found' => json_encode($result['columns'], JSON_UNESCAPED_UNICODE),
            'rows_total' => count($result['rows']),
            'rows_valid' => $counts['valid'],
            'rows_warning' => $counts['warning'],
            'rows_error' => $counts['error'],
            'rows_duplicate' => $counts['duplicate'],
        ]);
        $this->imports->replaceRows($runId, $result['rows']);

        return $this->requireRun($runId);
    }

    /**
     * Datei analysieren – ohne Schreibzugriff.
     * @param array<string,mixed> $options
     * @return array{delimiter:string, encoding:string, columns:array<string,mixed>, rows:list<array<string,mixed>>}
     */
    public function analyse(string $content, array $options): array
    {
        $parsed = CsvReader::parse($content, $options['delimiter'] ?? null, self::MAX_ROWS);
        if ($parsed['headers'] === []) {
            throw ValidationException::single('file', 'Die Datei enthält keine Kopfzeile.');
        }
        $mapping = self::mapColumns($parsed['headers']);
        if (!isset($mapping['asset_type']) && !isset($mapping['inventory_number'])) {
            throw ValidationException::single('file', 'Die Kopfzeile muss mindestens die Spalte „Assettyp“ oder „Inventarnummer“ enthalten. Gefunden: ' . implode(', ', $parsed['headers']));
        }
        if ($parsed['rows'] === []) {
            throw ValidationException::single('file', 'Die Datei enthält keine Datenzeilen.');
        }

        $this->cache = ['seen' => ['inventory_number' => [], 'serial' => [], 'mac' => [], 'imei' => []], 'new_manufacturers' => []];
        $rows = [];
        foreach ($parsed['rows'] as $index => $record) {
            $values = [];
            foreach ($mapping as $key => $header) {
                $values[$key] = $record['cells'][$header] ?? '';
            }
            $rows[] = $this->analyseRow($index + 1, $record['line'], $values, $options);
        }

        $unmapped = array_values(array_filter($parsed['headers'], static fn (string $h): bool => !in_array(CsvReader::normalizeHeader($h), $mapping, true)));

        return [
            'delimiter' => $parsed['delimiter'],
            'encoding' => $parsed['encoding'],
            'columns' => ['mapped' => $mapping, 'unmapped' => $unmapped, 'skipped_rows' => $parsed['skipped']],
            'rows' => $rows,
        ];
    }

    /**
     * Kopfzeile den bekannten Spalten zuordnen. @param list<string> $headers @return array<string,string> key => normalisierter Header
     */
    public static function mapColumns(array $headers): array
    {
        $normalized = array_map([CsvReader::class, 'normalizeHeader'], $headers);
        $mapping = [];
        foreach (self::COLUMNS as $key => [$label, $aliases]) {
            foreach ($normalized as $header) {
                if ($header !== '' && (in_array($header, $aliases, true) || $header === CsvReader::normalizeHeader($label)) && !in_array($header, $mapping, true)) {
                    $mapping[$key] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Eine Zeile prüfen: Referenzen auflösen, Eingaben über AssetService validieren, Dubletten erkennen.
     * @param array<string,string> $v Werte je Spaltenschlüssel
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function analyseRow(int $rowNumber, int $line, array $v, array $options): array
    {
        $messages = [];
        $add = static function (string $level, string $field, string $text) use (&$messages): void {
            $messages[] = ['level' => $level, 'field' => $field, 'text' => $text];
        };
        $input = [];

        // Inventarnummer und Assettyp
        $number = strtoupper(preg_replace('/\s+/', '', $v['inventory_number'] ?? '') ?? '');
        $type = null;
        if (($v['asset_type'] ?? '') !== '') {
            $type = $this->findType($v['asset_type']);
            if ($type === null) {
                $add('error', 'asset_type', 'Assettyp „' . $v['asset_type'] . '“ unbekannt.');
            }
        }
        if ($number !== '') {
            if (!InventoryNumberService::isValid($number)) {
                $add('error', 'inventory_number', 'Inventarnummer „' . $number . '“ hat kein gültiges Format (z. B. PC88001).');
            } else {
                if ($type === null) {
                    $type = $this->findTypeByNumber($number);
                    if ($type === null && ($v['asset_type'] ?? '') === '') {
                        $add('error', 'asset_type', 'Assettyp fehlt und ist aus der Inventarnummer nicht ableitbar.');
                    }
                }
                if (isset($this->cache['seen']['inventory_number'][$number])) {
                    $add('duplicate', 'inventory_number', 'Inventarnummer ' . $number . ' kommt bereits in Zeile ' . $this->cache['seen']['inventory_number'][$number] . ' vor.');
                } else {
                    $this->cache['seen']['inventory_number'][$number] = $rowNumber;
                    $existing = $this->assetRepo->findByUniqueField('inventory_number', $number);
                    if ($existing !== null) {
                        $add('duplicate', 'inventory_number', 'Inventarnummer ' . $number . ' ist bereits vergeben' . (!empty($existing['name']) ? ' (' . $existing['name'] . ')' : '') . '.');
                    }
                }
                $input['inventory_number'] = $number;
            }
        } elseif ($type === null && ($v['asset_type'] ?? '') === '') {
            $add('error', 'asset_type', 'Assettyp ist erforderlich.');
        }
        if ($type !== null) {
            $input['asset_type_id'] = (string) $type['id'];
        }

        // Altbestand
        $legacy = ($v['legacy'] ?? '') !== '' ? self::parseBool($v['legacy']) : (bool) $options['legacy'];
        if ($legacy === null) {
            $add('error', 'legacy', 'Altbestand: „' . $v['legacy'] . '“ ist kein Ja/Nein-Wert.');
            $legacy = (bool) $options['legacy'];
        }
        $input['is_legacy'] = $legacy ? '1' : '0';

        // Hersteller / Artikel / Kategorie
        $manufacturer = null;
        $newManufacturer = null;
        if (($v['manufacturer'] ?? '') !== '') {
            $manufacturer = $this->findManufacturer($v['manufacturer']);
            if ($manufacturer !== null) {
                $input['manufacturer_id'] = (string) $manufacturer['id'];
            } else {
                $similar = $this->manufacturerService->findDuplicates($v['manufacturer']);
                $hint = $similar !== [] ? ' Ähnlich: ' . implode(', ', array_map(static fn (array $d): string => '„' . $d['name'] . '“', array_slice($similar, 0, 3))) . '.' : '';
                if ($options['create_manufacturers']) {
                    $newManufacturer = trim($v['manufacturer']);
                    $key = ColognePhonetic::normalizedName($newManufacturer);
                    $this->cache['new_manufacturers'][$key] ??= $newManufacturer;
                    $add('warning', 'manufacturer', 'Hersteller „' . $newManufacturer . '“ wird neu angelegt.' . $hint);
                } else {
                    $add('error', 'manufacturer', 'Hersteller „' . $v['manufacturer'] . '“ unbekannt.' . $hint);
                }
            }
        }
        // Artikel ist Pflicht: nur vorhandene Stammartikel des Herstellers (Name oder Artikelnummer), inaktive nicht erlaubt
        $articleValue = trim((string) ($v['article'] ?? ''));
        if ($articleValue === '') {
            $add('error', 'article', 'Artikel ist erforderlich – bitte einen in den Stammdaten vorhandenen Artikel angeben.');
        } elseif ($manufacturer === null) {
            $add('error', 'article', 'Artikel „' . $articleValue . '“ kann ohne vorhandenen Hersteller nicht zugeordnet werden.');
        } else {
            $article = $this->findArticle((int) $manufacturer['id'], $articleValue);
            if ($article === null) {
                $similar = $this->articleService->findDuplicates($articleValue, (int) $manufacturer['id'], $articleValue);
                $hint = $similar !== [] ? ' Ähnlich: ' . implode(', ', array_map(static fn (array $d): string => '„' . $d['name'] . '“', array_slice($similar, 0, 3))) . '.' : '';
                $add('error', 'article', 'Artikel „' . $articleValue . '“ beim Hersteller ' . $manufacturer['name'] . ' nicht gefunden – bitte zuerst als Stammartikel anlegen.' . $hint);
            } elseif ((int) $article['is_active'] !== 1) {
                $add('error', 'article', 'Artikel „' . $article['name'] . '“ ist inaktiv.');
            } else {
                $input['article_id'] = (string) $article['id'];
                if ($type !== null && (int) $article['asset_type_id'] !== (int) $type['id']) {
                    $add('error', 'article', 'Artikel „' . $article['name'] . '“ gehört zum Assettyp ' . $article['asset_type_name'] . ', nicht zu ' . $type['name'] . '.');
                }
            }
        }
        if (($v['category'] ?? '') !== '' && $type !== null) {
            $category = $this->findCategory((int) $type['id'], $v['category']);
            if ($category !== null) {
                $input['asset_category_id'] = (string) $category['id'];
            } else {
                $add('warning', 'category', 'Kategorie „' . $v['category'] . '“ beim Assettyp ' . $type['name'] . ' nicht gefunden – wird ignoriert.');
            }
        }

        // Status, Mitarbeiter, Standort, Kostenstelle, Lieferant
        if (($v['status'] ?? '') !== '') {
            $status = $this->findStatus($v['status']);
            if ($status !== null) {
                $input['status_id'] = (string) $status['id'];
            } else {
                $add('error', 'status', 'Status „' . $v['status'] . '“ unbekannt.');
            }
        }
        if (($v['employee'] ?? '') !== '') {
            $employee = $this->findEmployee($v['employee']);
            if ($employee !== null) {
                $input['employee_id'] = (string) $employee['id'];
                if ((int) $employee['is_active'] !== 1) {
                    $add('warning', 'employee', 'Mitarbeiter „' . $employee['display_name'] . '“ ist inaktiv.');
                }
            } else {
                $add('error', 'employee', 'Mitarbeiter „' . $v['employee'] . '“ nicht gefunden (Benutzername, Personalnummer oder eindeutiger Name).');
            }
        }
        if (($v['location'] ?? '') !== '') {
            $location = $this->findLocation($v['location']);
            if ($location !== null) {
                $input['location_id'] = (string) $location['id'];
            } else {
                $add('error', 'location', 'Standort „' . $v['location'] . '“ nicht gefunden.');
            }
        }
        if (($v['cost_center'] ?? '') !== '') {
            $cc = $this->costCenters->findByNumber(trim($v['cost_center']));
            if ($cc !== null) {
                $input['cost_center_id'] = (string) $cc['id'];
            } else {
                $add('error', 'cost_center', 'Kostenstelle „' . $v['cost_center'] . '“ nicht gefunden.');
            }
        }
        if (($v['supplier'] ?? '') !== '') {
            $supplier = $this->suppliers->findByName(trim($v['supplier']));
            if ($supplier !== null) {
                $input['supplier_id'] = (string) $supplier['id'];
            } else {
                $add('warning', 'supplier', 'Lieferant „' . $v['supplier'] . '“ nicht gefunden – wird ignoriert.');
            }
        }

        // Datums- und Zahlenfelder
        foreach (['purchase_date', 'warranty_until'] as $dateField) {
            if (($v[$dateField] ?? '') !== '') {
                $date = Validator::parseDate($v[$dateField]);
                if ($date === null) {
                    $add('error', $dateField, self::COLUMNS[$dateField][0] . ': „' . $v[$dateField] . '“ ist kein gültiges Datum (TT.MM.JJJJ).');
                } else {
                    $input[$dateField] = $date;
                }
            }
        }
        if (($v['purchase_price'] ?? '') !== '') {
            $input['purchase_price'] = preg_replace('/[^\d,.\-]/', '', $v['purchase_price']) ?? '';
        }
        foreach (['name', 'serial_number', 'mac_address', 'imei', 'note'] as $textField) {
            if (($v[$textField] ?? '') !== '') {
                $input[$textField] = $v[$textField];
            }
        }

        // Dubletten innerhalb der Datei (Seriennummer je Typ, MAC, IMEI)
        if (isset($input['serial_number'])) {
            $serialKey = ($type['id'] ?? '?') . '|' . (AssetService::normalizeSerial($input['serial_number']) ?? '');
            if (isset($this->cache['seen']['serial'][$serialKey])) {
                $add('duplicate', 'serial_number', 'Seriennummer kommt bereits in Zeile ' . $this->cache['seen']['serial'][$serialKey] . ' vor.');
            } else {
                $this->cache['seen']['serial'][$serialKey] = $rowNumber;
            }
        }
        foreach (['mac_address' => 'mac', 'imei' => 'imei'] as $field => $bucket) {
            if (isset($input[$field])) {
                $key = $bucket === 'mac' ? (AssetService::normalizeMac($input[$field]) ?? $input[$field]) : (AssetService::normalizeImei($input[$field]) ?? $input[$field]);
                if (isset($this->cache['seen'][$bucket][$key])) {
                    $add('duplicate', $field, self::COLUMNS[$field][0] . ' kommt bereits in Zeile ' . $this->cache['seen'][$bucket][$key] . ' vor.');
                } else {
                    $this->cache['seen'][$bucket][$key] = $rowNumber;
                }
            }
        }

        // Fachliche Validierung (identisch zur manuellen Anlage) – ohne zu schreiben
        if (isset($input['asset_type_id']) && !array_filter($messages, static fn (array $m): bool => $m['level'] === 'error' && $m['field'] === 'asset_type')) {
            try {
                $this->assets->validateNew($input);
            } catch (ValidationException $e) {
                $flagged = array_column($messages, 'field');
                foreach ($e->errors() as $field => $text) {
                    if (in_array((string) $field, $flagged, true)) {
                        continue; // bereits mit konkreterer Meldung gemeldet
                    }
                    $isDuplicate = in_array($field, ['serial_number', 'mac_address', 'imei', 'inventory_number'], true) && str_contains($text, 'bereits');
                    $add($isDuplicate ? 'duplicate' : 'error', (string) $field, $text);
                }
            } catch (\App\Exceptions\ForbiddenException) {
                $add('error', 'status', 'Der Status erfordert die Berechtigung „Assets ausmustern“.');
            }
        }

        $levels = array_column($messages, 'level');
        $status = in_array('duplicate', $levels, true) ? 'duplicate' : (in_array('error', $levels, true) ? 'error' : (in_array('warning', $levels, true) ? 'warning' : 'valid'));

        $summaryParts = array_filter([
            $type['name'] ?? ($v['asset_type'] ?? null),
            trim(($v['manufacturer'] ?? '') . ' ' . ($v['name'] ?? '')) ?: null,
            ($v['serial_number'] ?? '') !== '' ? 'SN ' . $v['serial_number'] : null,
        ]);

        return [
            'row_number' => $rowNumber,
            'line' => $line,
            'status' => $status,
            'inventory_number' => $number !== '' ? $number : null,
            'expected_number' => $number !== '' ? $number : ($type !== null ? $type['inventory_prefix'] . ($legacy ? InventoryNumberService::LEGACY_YEAR_CODE : date('y')) . '…' : null),
            'asset_id' => null,
            'summary' => implode(' · ', $summaryParts),
            'messages' => $messages,
            'data' => $v,
            'input' => $input,
            'new_manufacturer' => $newManufacturer,
        ];
    }

    // ------------------------------------------------------------------ Import

    /**
     * Vorschau-Lauf ausführen: erneut prüfen, gültige Zeilen anlegen, Ergebnis protokollieren.
     * @return array<string,mixed> Lauf
     */
    public function commit(int $runId, bool $strict = false): array
    {
        $this->currentUser->require('imports.manage');
        $run = $this->requireRun($runId);
        if ($run['status'] !== 'preview') {
            throw new ConflictException('Dieser Import wurde bereits ' . ($run['status'] === 'completed' ? 'durchgeführt' : 'verworfen') . '.');
        }
        $options = self::options($run);
        $result = $this->analyse($this->readFile($run), $options);
        $counts = self::counts($result['rows']);
        if ($strict && ($counts['error'] > 0 || $counts['duplicate'] > 0)) {
            $this->imports->replaceRows($runId, $result['rows']);
            $this->imports->updateRun($runId, ['rows_error' => $counts['error'], 'rows_duplicate' => $counts['duplicate'], 'rows_valid' => $counts['valid'], 'rows_warning' => $counts['warning']]);
            throw ValidationException::single('strict', 'Import abgebrochen: ' . ($counts['error'] + $counts['duplicate']) . ' Zeile(n) mit Fehlern oder Dubletten. Bitte Datei korrigieren oder ohne „nur fehlerfrei“ importieren.');
        }

        $imported = 0;
        $rows = $this->imports->transaction(function () use ($result, $options, &$imported): array {
            $createdManufacturers = [];
            $rows = $result['rows'];
            foreach ($rows as &$row) {
                if (!in_array($row['status'], ['valid', 'warning'], true)) {
                    continue;
                }
                $input = $row['input'];
                try {
                    if ($row['new_manufacturer'] !== null && $options['create_manufacturers']) {
                        $key = ColognePhonetic::normalizedName($row['new_manufacturer']);
                        if (!isset($createdManufacturers[$key])) {
                            $existing = $this->manufacturers->findByNormalizedName($key);
                            $createdManufacturers[$key] = $existing !== null ? (int) $existing['id'] : $this->manufacturerService->create(['name' => $row['new_manufacturer'], 'is_active' => '1'], true);
                        }
                        $input['manufacturer_id'] = (string) $createdManufacturers[$key];
                    }
                    $created = $this->assets->create($input);
                    $row['status'] = 'imported';
                    $row['asset_id'] = $created['id'];
                    $row['inventory_number'] = $created['inventory_number'];
                    $imported++;
                } catch (ValidationException $e) {
                    $row['status'] = 'error';
                    foreach ($e->errors() as $field => $text) {
                        $row['messages'][] = ['level' => 'error', 'field' => (string) $field, 'text' => $text];
                    }
                }
            }
            unset($row);

            return $rows;
        });

        $counts = self::counts($rows);
        $this->imports->replaceRows($runId, $rows);
        $this->imports->updateRun($runId, [
            'status' => 'completed',
            'rows_total' => count($rows),
            'rows_valid' => $counts['valid'],
            'rows_warning' => $counts['warning'],
            'rows_error' => $counts['error'],
            'rows_duplicate' => $counts['duplicate'],
            'rows_imported' => $imported,
            'committed_at' => gmdate('Y-m-d H:i:s'),
        ]);
        // Rohdaten liegen je Zeile im Protokoll – die Upload-Datei wird nicht mehr benötigt
        $this->deleteFile((string) $run['stored_path']);
        $this->audit->log('import', 'import_run', $runId, $run['original_name'], null, [
            'rows_total' => count($rows), 'rows_imported' => $imported, 'rows_error' => $counts['error'], 'rows_duplicate' => $counts['duplicate'], 'strict' => $strict,
        ]);

        return $this->requireRun($runId);
    }

    public function cancel(int $runId): void
    {
        $this->currentUser->require('imports.manage');
        $run = $this->requireRun($runId);
        if ($run['status'] !== 'preview') {
            throw new ConflictException('Nur Vorschau-Läufe können verworfen werden.');
        }
        $this->imports->updateRun($runId, ['status' => 'cancelled']);
        $this->deleteFile($run['stored_path']);
        $this->audit->log('cancel', 'import_run', $runId, $run['original_name']);
    }

    /** Alte Vorschauen (Dateien) aufräumen. */
    public function cleanupStalePreviews(): int
    {
        $n = 0;
        foreach ($this->imports->stalePreviews(self::PREVIEW_TTL_HOURS) as $run) {
            $this->imports->updateRun((int) $run['id'], ['status' => 'cancelled', 'error_message' => 'Vorschau abgelaufen']);
            $this->deleteFile((string) $run['stored_path']);
            $n++;
        }

        return $n;
    }

    // ------------------------------------------------------------------ Berichte

    /** Fehlerreport: alle nicht importierten Zeilen mit Meldungen und Originaldaten. */
    public function errorReportCsv(int $runId): string
    {
        $this->requireRun($runId);
        $rows = array_values(array_filter($this->imports->rows($runId), static fn (array $r): bool => in_array($r['status'], ['error', 'duplicate', 'skipped'], true)));
        $columnKeys = array_keys(self::COLUMNS);
        $headers = array_merge(['Zeile', 'Status', 'Meldungen'], array_map(static fn (string $k): string => self::COLUMNS[$k][0], $columnKeys));

        return CsvWriter::build($headers, (static function () use ($rows, $columnKeys): \Generator {
            foreach ($rows as $r) {
                $line = [$r['row_number'], self::STATUS_LABELS[$r['status']] ?? $r['status'], implode(' | ', array_map(static fn (array $m): string => $m['text'], $r['messages']))];
                foreach ($columnKeys as $k) {
                    $line[] = $r['data'][$k] ?? '';
                }
                yield $line;
            }
        })());
    }

    /** Vorlage mit allen Spalten und Beispielzeilen. */
    public static function templateCsv(): string
    {
        $headers = array_values(array_map(static fn (array $c): string => $c[0], self::COLUMNS));
        $example = array_values(array_map(static fn (array $c): string => $c[4], self::COLUMNS));
        $second = $example;
        $second[0] = '';
        $second[1] = 'MD';
        $second[2] = 'Smartphone';
        $second[3] = 'iPhone 13';
        $second[4] = 'Apple';
        $second[5] = 'iPhone 13';
        $second[6] = 'F2LXYZ123';
        $second[7] = '';
        $second[8] = '356789012345678';
        $second[9] = 'Ausgegeben';
        $second[10] = 'emusterfrau';
        $second[11] = '';
        $second[15] = '';

        return CsvWriter::build(array_values($headers), [array_values($example), array_values($second)]);
    }

    // ------------------------------------------------------------------ Hilfen

    /** @param array<string,mixed> $input @return array{legacy:bool, create_manufacturers:bool, delimiter:?string} */
    public function normalizeOptions(array $input): array
    {
        $delimiter = (string) ($input['delimiter'] ?? '');
        $delimiter = match ($delimiter) { 'semicolon' => ';', 'comma' => ',', 'tab' => "\t", 'pipe' => '|', default => null };

        return [
            'legacy' => !empty($input['legacy']),
            'create_manufacturers' => !empty($input['create_manufacturers']),
            'delimiter' => $delimiter,
        ];
    }

    /** @param array<string,mixed> $run @return array{legacy:bool, create_manufacturers:bool, delimiter:?string} */
    public static function options(array $run): array
    {
        $o = json_decode((string) ($run['options'] ?? '{}'), true) ?: [];

        return ['legacy' => !empty($o['legacy']), 'create_manufacturers' => !empty($o['create_manufacturers']), 'delimiter' => $o['delimiter'] ?? null];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,int> */
    public static function counts(array $rows): array
    {
        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        return $counts;
    }

    public static function parseBool(string $value): ?bool
    {
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['1', 'ja', 'j', 'yes', 'y', 'true', 'x', 'wahr'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'nein', 'n', 'no', 'false', '', 'falsch', '-'], true)) {
            return false;
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function requireRun(int $runId): array
    {
        return $this->imports->findRun($runId) ?? throw new \App\Exceptions\NotFoundException('Import nicht gefunden.');
    }

    private function storageDir(): string
    {
        $dir = rtrim((string) $this->config->get('uploads.path', dirname(__DIR__, 2) . '/storage/uploads'), '/') . '/imports';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Importverzeichnis kann nicht angelegt werden.');
        }

        return $dir;
    }

    private function storeFile(string $content): string
    {
        $name = 'imports/' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.csv';
        if (file_put_contents($this->storageDir() . '/' . basename($name), $content) === false) {
            throw new \RuntimeException('Importdatei konnte nicht gespeichert werden.');
        }

        return $name;
    }

    /** @param array<string,mixed> $run */
    private function readFile(array $run): string
    {
        $path = $this->storageDir() . '/' . basename((string) $run['stored_path']);
        if (!is_file($path)) {
            throw ValidationException::single('file', 'Die Importdatei ist nicht mehr vorhanden. Bitte erneut hochladen.');
        }

        return (string) file_get_contents($path);
    }

    private function deleteFile(string $storedPath): void
    {
        $path = $this->storageDir() . '/' . basename($storedPath);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array<string,mixed>|null */
    private function findType(string $term): ?array
    {
        $this->cache['types'] ??= $this->types->all();
        $t = mb_strtolower(trim($term));
        foreach ($this->cache['types'] as $type) {
            if (mb_strtolower((string) $type['code']) === $t || mb_strtolower((string) $type['name']) === $t || mb_strtolower((string) $type['inventory_prefix']) === $t) {
                return $type;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findTypeByNumber(string $number): ?array
    {
        $this->cache['types'] ??= $this->types->all();
        $prefix = preg_replace('/\d.*$/', '', $number) ?? '';
        foreach ($this->cache['types'] as $type) {
            if ((string) $type['inventory_prefix'] === $prefix) {
                return $type;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findCategory(int $typeId, string $term): ?array
    {
        $this->cache['categories'][$typeId] ??= $this->types->categories($typeId);
        $t = mb_strtolower(trim($term));
        foreach ($this->cache['categories'][$typeId] as $category) {
            if (mb_strtolower((string) $category['name']) === $t) {
                return $category;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findStatus(string $term): ?array
    {
        $this->cache['statuses'] ??= $this->statuses->all();
        $t = mb_strtolower(trim($term));
        foreach ($this->cache['statuses'] as $status) {
            if (mb_strtolower((string) $status['code']) === $t || mb_strtolower((string) $status['name']) === $t) {
                return $status;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function findManufacturer(string $name): ?array
    {
        $key = ColognePhonetic::normalizedName($name);
        if ($key === '') {
            return null;
        }
        $this->cache['manufacturers'][$key] ??= $this->manufacturers->findByNormalizedName($key) ?? false;

        return $this->cache['manufacturers'][$key] ?: null;
    }

    /** Stammartikel des Herstellers über Name (Schreibweise egal) oder Artikelnummer. @return array<string,mixed>|null */
    private function findArticle(int $manufacturerId, string $term): ?array
    {
        $key = $manufacturerId . '|' . mb_strtolower(trim($term));
        if (!array_key_exists($key, $this->cache['articles'] ?? [])) {
            $hit = $this->articles->findDuplicate($manufacturerId, trim($term))
                ?? $this->articles->findByNormalizedName($manufacturerId, ColognePhonetic::normalizedArticleName($term))
                ?? $this->articles->findByArticleNumber($manufacturerId, trim($term));
            $this->cache['articles'][$key] = $hit !== null ? ($this->articles->find((int) $hit['id']) ?? false) : false;
        }

        return $this->cache['articles'][$key] ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findEmployee(string $term): ?array
    {
        $t = trim($term);
        $this->cache['employees'][$t] ??= $this->employees->findByUsername($t)
            ?? $this->employees->findByPersonnelNumber($t)
            ?? $this->employees->findUniqueByDisplayName($t)
            ?? false;

        return $this->cache['employees'][$t] ?: null;
    }

    /** @return array<string,mixed>|null */
    private function findLocation(string $term): ?array
    {
        $t = trim($term);
        $path = preg_replace('/\s*\/\s*/', ' / ', $t) ?? $t;
        $this->cache['locations'][$t] ??= $this->locations->findByPath($path) ?? $this->locations->findUniqueByNameOrCode($t) ?? false;

        return $this->cache['locations'][$t] ?: null;
    }
}
