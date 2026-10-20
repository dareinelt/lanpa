<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\ImportRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\ImportService;
use App\Services\ManufacturerService;
use Tests\Support\DatabaseTestCase;

final class ImportServiceIntegrationTest extends DatabaseTestCase
{
    private int $userId;
    private int $locationId;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO users (username, display_name, role_id, auth_source, is_active) SELECT 'import-tester', 'Import Tester', id, 'local', 1 FROM roles ORDER BY id LIMIT 1");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->loginAs('assetmanagement');

        $locations = $this->c->get(LocationRepository::class);
        $site = $locations->create(['name' => 'Import-Standort', 'type' => 'site', 'full_path' => 'Import-Standort', 'depth' => 0, 'is_active' => 1]);
        $this->locationId = $locations->create(['name' => 'Import-Raum', 'type' => 'room', 'parent_id' => $site, 'full_path' => 'Import-Standort / Import-Raum', 'depth' => 1, 'is_active' => 1]);
        $this->c->get(CostCenterRepository::class)->create(['number' => '96001', 'description' => 'Import-KST', 'is_active' => 1]);
        $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Import Nutzer', 'username' => 'inutzer', 'personnel_number' => '96001', 'department' => 'Import', 'is_active' => 1]);
        $this->c->get(ManufacturerService::class)->create(['name' => 'Importwerk', 'is_active' => '1'], true);
    }

    protected function tearDown(): void
    {
        // Upload-Dateien der (zurückgerollten) Läufe entfernen
        $base = rtrim((string) $this->c->get(\App\Core\Config::class)->get('uploads.path'), '/') . '/';
        foreach ($this->pdo->query("SELECT stored_path FROM import_runs WHERE stored_path LIKE 'imports/%'")->fetchAll(\PDO::FETCH_COLUMN) as $stored) {
            if (is_file($base . $stored)) {
                @unlink($base . $stored);
            }
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function loginAs(string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $this->userId, 'username' => 'import-tester', 'display_name' => 'Import Tester', 'role' => $role]);
    }

    private function service(): ImportService
    {
        return $this->c->get(ImportService::class);
    }

    /** @return array<string,mixed> $_FILES-ähnlicher Eintrag */
    private function uploadFor(string $content, string $name = 'test.csv'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $content);
        $this->tempFiles[] = $tmp;

        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK, 'type' => 'text/csv'];
    }

    private const HEADER = "Inventarnummer;Assettyp;Bezeichnung;Hersteller;Seriennummer;Status;Mitarbeiter;Standort;Kostenstelle;Kaufdatum;Kaufpreis;Altbestand\n";

    private function sampleCsv(): string
    {
        return self::HEADER
            . ";PC;Import Gerät 1;Importwerk;IMPT-001;Lagerbestand;;Import-Standort / Import-Raum;96001;15.03.2024;1.299,00;Ja\n"
            . ";PC;Import Gerät 2;Importwerk;IMPT-002;Ausgegeben;inutzer;Import-Raum;96001;2023-02-01;100;Ja\n"
            . ";PC;Neuer Hersteller;Neuwerk GmbH;IMPT-003;Lagerbestand;;;;;;Ja\n"
            . ";PC;Falsches Datum;Importwerk;IMPT-004;Lagerbestand;;;;31.02.2024;;Ja\n"
            . ";PC;Unbekannter Nutzer;Importwerk;IMPT-005;Ausgegeben;niemand;;;;;Ja\n"
            . ";PC;Serien-Dublette;Importwerk;IMPT-001;Lagerbestand;;;;;;Ja\n"
            . ";;Ohne Typ;Importwerk;IMPT-006;Lagerbestand;;;;;;Ja\n";
    }

    // ------------------------------------------------------------------ Analyse

    public function testUploadAnalysesRowsAndStoresPreview(): void
    {
        $runId = $this->service()->upload($this->uploadFor($this->sampleCsv()), ['legacy' => '1', 'create_manufacturers' => '1']);
        $run = $this->c->get(ImportRepository::class)->findRun($runId);

        $this->assertSame('preview', $run['status']);
        $this->assertSame(7, (int) $run['rows_total']);
        $this->assertSame(2, (int) $run['rows_valid']);
        $this->assertSame(1, (int) $run['rows_warning']);
        $this->assertSame(3, (int) $run['rows_error']);
        $this->assertSame(1, (int) $run['rows_duplicate']);
        $this->assertSame(';', $run['delimiter']);
        $this->assertSame('UTF-8', $run['encoding']);

        $columns = json_decode((string) $run['columns_found'], true);
        $this->assertTrue(isset($columns['mapped']['inventory_number']));
        $this->assertTrue(isset($columns['mapped']['purchase_price']));
        $this->assertSame([], $columns['unmapped']);

        $rows = $this->c->get(ImportRepository::class)->rows($runId);
        $this->assertCount(7, $rows);
        $this->assertSame('valid', $rows[0]['status']);
        $this->assertSame('valid', $rows[1]['status']);
        $this->assertSame('warning', $rows[2]['status']);
        $this->assertStringContains('Neuwerk GmbH', $rows[2]['messages'][0]['text']);
        $this->assertSame('error', $rows[3]['status']);
        $this->assertSame('purchase_date', $rows[3]['messages'][0]['field']);
        $this->assertSame('error', $rows[4]['status']);
        $this->assertSame('employee', $rows[4]['messages'][0]['field']);
        $this->assertSame('duplicate', $rows[5]['status']);
        $this->assertStringContains('Zeile 1', $rows[5]['messages'][0]['text']);
        $this->assertSame('error', $rows[6]['status']);
        $this->assertSame('asset_type', $rows[6]['messages'][0]['field']);
        $this->assertSame('Import Gerät 1', $rows[0]['data']['name']);

        $this->assertSame(0, $this->c->get(AssetRepository::class)->countSearch(['q' => 'IMPT-']));
    }

    public function testUnknownManufacturerIsErrorWithoutCreateOption(): void
    {
        $runId = $this->service()->upload($this->uploadFor($this->sampleCsv()), ['legacy' => '1']);
        $rows = $this->c->get(ImportRepository::class)->rows($runId, 'error');

        $names = array_map(static fn (array $r): string => (string) $r['data']['name'], $rows);
        $this->assertContains('Neuer Hersteller', $names);
    }

    public function testDetectsDuplicatesAgainstDatabase(): void
    {
        $typeId = (string) $this->c->get(AssetTypeRepository::class)->findByCode('PC')['id'];
        $existing = $this->c->get(AssetService::class)->create(['asset_type_id' => $typeId, 'name' => 'Vorhanden', 'serial_number' => 'IMPT-EXIST', 'purchase_price' => '']);

        $csv = self::HEADER
            . $existing['inventory_number'] . ";PC;Nummer doppelt;Importwerk;IMPT-010;Lagerbestand;;;;;;Nein\n"
            . ";PC;Serial doppelt;Importwerk;IMPT-EXIST;Lagerbestand;;;;;;Nein\n";
        $runId = $this->service()->upload($this->uploadFor($csv), []);
        $rows = $this->c->get(ImportRepository::class)->rows($runId);

        $this->assertSame('duplicate', $rows[0]['status']);
        $this->assertSame('inventory_number', $rows[0]['messages'][0]['field']);
        $this->assertCount(1, $rows[0]['messages']);
        $this->assertSame('duplicate', $rows[1]['status']);
        $this->assertSame('serial_number', $rows[1]['messages'][0]['field']);
    }

    public function testRejectsFilesWithoutUsableHeader(): void
    {
        $this->assertThrows(ValidationException::class, fn () => $this->service()->upload($this->uploadFor("Foo;Bar\n1;2\n"), []), 'Assettyp');
        $this->assertThrows(ValidationException::class, fn () => $this->service()->upload($this->uploadFor("PC;Test\n"), ['delimiter' => 'semicolon']));
        $this->assertThrows(ValidationException::class, fn () => $this->service()->upload($this->uploadFor("Assettyp;Name\nPC;x", 'bild.png'), []), 'CSV');
        $this->assertThrows(ValidationException::class, fn () => $this->service()->upload(null, []), 'auswählen');
        $this->assertThrows(ValidationException::class, fn () => $this->service()->upload($this->uploadFor("Assettyp;Name\nPC;\0x"), []), 'Textdatei');
    }

    public function testExplicitDelimiterAndWindows1252(): void
    {
        $content = mb_convert_encoding("Assettyp,Bezeichnung,Standort\nPC,Gerät Ü,Import-Standort / Import-Raum\n", 'Windows-1252', 'UTF-8');
        $runId = $this->service()->upload($this->uploadFor($content), ['delimiter' => 'comma']);
        $run = $this->c->get(ImportRepository::class)->findRun($runId);

        $this->assertSame(',', $run['delimiter']);
        $this->assertSame('Windows-1252', $run['encoding']);
        $rows = $this->c->get(ImportRepository::class)->rows($runId);
        $this->assertSame('valid', $rows[0]['status']);
        $this->assertSame('Gerät Ü', $rows[0]['data']['name']);
    }

    // ------------------------------------------------------------------ Durchführung

    public function testCommitImportsValidRowsAndKeepsProtocol(): void
    {
        $runId = $this->service()->upload($this->uploadFor($this->sampleCsv()), ['legacy' => '1', 'create_manufacturers' => '1']);
        $run = $this->service()->commit($runId);

        $this->assertSame('completed', $run['status']);
        $this->assertSame(3, (int) $run['rows_imported']);
        $this->assertNotNull($run['committed_at']);

        $rows = $this->c->get(ImportRepository::class)->rows($runId);
        $imported = array_values(array_filter($rows, static fn (array $r): bool => $r['status'] === 'imported'));
        $this->assertCount(3, $imported);
        foreach ($imported as $row) {
            $this->assertNotNull($row['asset_id']);
            $this->assertMatches('/^PC88\d{3,}$/', (string) $row['inventory_number']);
        }

        $assets = $this->c->get(AssetRepository::class);
        $first = $assets->find((int) $imported[0]['asset_id']);
        $this->assertSame('Import Gerät 1', $first['name']);
        $this->assertSame('IMPT-001', $first['serial_number']);
        $this->assertSame('1299.00', $first['purchase_price']);
        $this->assertSame('2024-03-15', $first['purchase_date']);
        $this->assertSame($this->locationId, (int) $first['location_id']);
        $this->assertSame(1, (int) $first['is_legacy']);

        $second = $assets->find((int) $imported[1]['asset_id']);
        $this->assertSame('issued', $second['status_code']);
        $this->assertNotNull($second['employee_id']);
        $this->assertSame('2023-02-01', $second['purchase_date']);

        $third = $assets->find((int) $imported[2]['asset_id']);
        $this->assertSame('Neuwerk GmbH', $third['manufacturer_name']);
        $this->assertNotNull($this->c->get(ManufacturerRepository::class)->findByNormalizedName(\App\Support\ColognePhonetic::normalizedName('Neuwerk GmbH')));

        // Fehlerzeilen bleiben im Protokoll, ohne Assets
        $errors = array_filter($rows, static fn (array $r): bool => in_array($r['status'], ['error', 'duplicate'], true));
        $this->assertCount(4, $errors);
    }

    public function testStrictCommitAbortsWhenFileHasProblems(): void
    {
        $runId = $this->service()->upload($this->uploadFor($this->sampleCsv()), ['legacy' => '1', 'create_manufacturers' => '1']);
        $this->assertThrows(ValidationException::class, fn () => $this->service()->commit($runId, true), 'abgebrochen');

        $run = $this->c->get(ImportRepository::class)->findRun($runId);
        $this->assertSame('preview', $run['status']);
        $this->assertSame(0, (int) $run['rows_imported']);
        $this->assertSame(0, $this->c->get(AssetRepository::class)->countSearch(['q' => 'IMPT-']));
    }

    public function testCommitTwiceIsRejected(): void
    {
        $csv = self::HEADER . ";PC;Einmal;Importwerk;IMPT-020;Lagerbestand;;;;;;Nein\n";
        $runId = $this->service()->upload($this->uploadFor($csv), []);
        $this->service()->commit($runId);

        $this->assertThrows(ConflictException::class, fn () => $this->service()->commit($runId), 'bereits');
        $this->assertThrows(ConflictException::class, fn () => $this->service()->cancel($runId));
    }

    public function testCommitReanalysesAndSkipsRowsThatBecameDuplicates(): void
    {
        $csv = self::HEADER . ";PC;Später doppelt;Importwerk;IMPT-030;Lagerbestand;;;;;;Nein\n";
        $runId = $this->service()->upload($this->uploadFor($csv), []);
        $this->assertSame(1, (int) $this->c->get(ImportRepository::class)->findRun($runId)['rows_valid']);

        // Zwischenzeitlich manuell angelegt
        $typeId = (string) $this->c->get(AssetTypeRepository::class)->findByCode('PC')['id'];
        $this->c->get(AssetService::class)->create(['asset_type_id' => $typeId, 'name' => 'Manuell', 'serial_number' => 'IMPT-030', 'purchase_price' => '']);

        $run = $this->service()->commit($runId);
        $this->assertSame(0, (int) $run['rows_imported']);
        $this->assertSame(1, (int) $run['rows_duplicate']);
    }

    public function testCancelMarksRunAndDeletesFile(): void
    {
        $runId = $this->service()->upload($this->uploadFor($this->sampleCsv()), []);
        $run = $this->c->get(ImportRepository::class)->findRun($runId);
        $path = rtrim((string) $this->c->get(\App\Core\Config::class)->get('uploads.path'), '/') . '/' . $run['stored_path'];
        $this->assertTrue(is_file($path));

        $this->service()->cancel($runId);

        $this->assertSame('cancelled', $this->c->get(ImportRepository::class)->findRun($runId)['status']);
        $this->assertFalse(is_file($path));
    }

    public function testErrorReportContainsOnlyProblemRows(): void
    {
        $runId = $this->service()->upload($this->uploadFor($this->sampleCsv()), ['legacy' => '1', 'create_manufacturers' => '1']);
        $csv = $this->service()->errorReportCsv($runId);
        $lines = array_filter(explode("\n", trim($csv)));

        $this->assertCount(5, $lines); // Kopf + 3 Fehler + 1 Dublette
        $this->assertStringContains('Zeile;Status;Meldungen;Inventarnummer', $lines[array_key_first($lines)]);
        $this->assertStringContains('31.02.2024', $csv);
        $this->assertStringContains('Zeile 1', $csv);
        $this->assertFalse(str_contains($csv, 'Import Gerät 1'));
    }

    // ------------------------------------------------------------------ Rechte

    public function testRequiresImportPermission(): void
    {
        $this->loginAs('lager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->upload($this->uploadFor($this->sampleCsv()), []));

        $this->loginAs('readonly');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->upload($this->uploadFor($this->sampleCsv()), []));
    }
}
