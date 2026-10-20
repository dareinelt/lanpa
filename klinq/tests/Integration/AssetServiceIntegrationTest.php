<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\EmployeeRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\InventoryNumberService;
use App\Services\SearchService;
use Tests\Support\DatabaseTestCase;

final class AssetServiceIntegrationTest extends DatabaseTestCase
{
    private function loginAs(string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => 1, 'username' => 'tester', 'display_name' => 'Test User', 'role' => $role]);
    }

    private function typeId(string $code): string
    {
        return (string) $this->c->get(AssetTypeRepository::class)->findByCode($code)['id'];
    }

    /** @param array<string,mixed> $overrides */
    private function pcInput(array $overrides = []): array
    {
        return array_merge(['asset_type_id' => $this->typeId('PC'), 'name' => 'Testgerät'], $overrides);
    }

    public function testSequenceIsPerPrefixAndYearAndCatchesUpWithManualNumbers(): void
    {
        $numbers = new InventoryNumberService($this->pdo, new \DateTimeImmutable('2026-05-01'));
        $this->assertSame('PC26001', $numbers->next('PC'));
        $this->assertSame('PC26002', $numbers->next('pc'));
        $this->assertSame('MD26001', $numbers->next('MD'));
        $this->assertSame('PC88001', $numbers->next('PC', true));

        // Manuell vergebene höhere Nummer im Bestand: Sequenz springt dahinter
        $this->loginAs('admin');
        $svc = $this->c->get(AssetService::class);
        $svc->create($this->pcInput(['is_legacy' => '1', 'inventory_number' => 'PC88010']));
        $this->assertSame('PC88011', $numbers->next('PC', true));

        // Jahreswechsel startet neu
        $next = new InventoryNumberService($this->pdo, new \DateTimeImmutable('2027-01-02'));
        $this->assertSame('PC27001', $next->next('PC'));
    }

    public function testLastReturnsPreviewWithoutAdvancingSequence(): void
    {
        $numbers = new InventoryNumberService($this->pdo, new \DateTimeImmutable('2026-05-01'));

        // Noch keine Nummer vergeben: kein Vorschlag
        $this->assertNull($numbers->last('PC'));

        $this->assertSame('PC26001', $numbers->next('PC'));
        // last() zeigt die zuletzt vergebene Nummer, verändert die Sequenz aber nicht
        $this->assertSame('PC26001', $numbers->last('PC'));
        $this->assertSame('PC26001', $numbers->last('PC'));
        $this->assertSame('PC26002', $numbers->next('PC'));
        $this->assertSame('PC26002', $numbers->last('PC'));
    }

    public function testPeekNextReturnsFollowingNumberWithoutAdvancingSequence(): void
    {
        $numbers = new InventoryNumberService($this->pdo, new \DateTimeImmutable('2026-05-01'));

        // Noch keine Nummer vergeben: die nächste wäre die erste
        $this->assertSame('PC26001', $numbers->peekNext('PC'));

        $this->assertSame('PC26001', $numbers->next('PC'));
        // peekNext() zeigt die nächste freie Nummer, verändert die Sequenz aber nicht
        $this->assertSame('PC26002', $numbers->peekNext('PC'));
        $this->assertSame('PC26002', $numbers->peekNext('PC'));
        $this->assertSame('PC26002', $numbers->next('PC'));
        $this->assertSame('PC26003', $numbers->peekNext('PC'));
    }

    public function testCreateAssignsNumberWritesHistoryAndDerivesStatus(): void
    {
        $this->loginAs('assetmanagement');
        $svc = $this->c->get(AssetService::class);
        $assets = $this->c->get(AssetRepository::class);
        $history = $this->c->get(AssetHistoryRepository::class);
        $employeeId = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Erika Muster', 'first_name' => 'Erika', 'last_name' => 'Muster']);

        $created = $svc->create($this->pcInput(['serial_number' => 'pf-3ab 12x', 'mac_address' => 'a1-b2-c3-d4-e5-f6', 'purchase_price' => '1.299,00']));
        $this->assertMatches('/^PC\d{5}$/', $created['inventory_number']);
        $row = $assets->find($created['id']);
        $this->assertSame('in_stock', $row['status_code']);
        $this->assertSame('PF3AB12X', $row['serial_number_normalized']);
        $this->assertSame('A1:B2:C3:D4:E5:F6', $row['mac_address']);
        $this->assertSame('1299.00', $row['purchase_price']);
        $this->assertSame('Test User', $history->forAsset($created['id'])[0]['actor_name']);

        // Mitarbeiter zugeordnet ⇒ automatisch „Ausgegeben“
        $issued = $svc->create($this->pcInput(['employee_id' => (string) $employeeId]));
        $this->assertSame('issued', $assets->find($issued['id'])['status_code']);
        $events = array_column($history->forAsset($issued['id']), 'event_type');
        $this->assertContains('created', $events);
        $this->assertContains('assignment_changed', $events);
    }

    public function testDuplicateSerialAndMacAreRejected(): void
    {
        $this->loginAs('admin');
        $svc = $this->c->get(AssetService::class);
        $svc->create($this->pcInput(['serial_number' => 'ABC123', 'mac_address' => '00:11:22:33:44:55']));

        $this->assertThrows(ValidationException::class, fn () => $svc->create($this->pcInput(['serial_number' => 'abc-123'])), 'Seriennummer');
        $this->assertThrows(ValidationException::class, fn () => $svc->create($this->pcInput(['mac_address' => '001122334455'])), 'MAC');
        // Gleiche Seriennummer bei anderem Assettyp ist erlaubt
        $svc->create(['asset_type_id' => $this->typeId('ZUB'), 'serial_number' => 'ABC123']);
    }

    public function testManualNumberMustMatchTypePrefixAndFormat(): void
    {
        $this->loginAs('admin');
        $svc = $this->c->get(AssetService::class);
        $this->assertThrows(ValidationException::class, fn () => $svc->create($this->pcInput(['is_legacy' => '1', 'inventory_number' => 'MD88001'])), 'Format');
        $this->assertThrows(ValidationException::class, fn () => $svc->create($this->pcInput(['is_legacy' => '1', 'inventory_number' => 'PC8801'])), 'Format');
        $created = $svc->create($this->pcInput(['is_legacy' => '1', 'inventory_number' => ' pc88123 ']));
        $this->assertSame('PC88123', $created['inventory_number']);
        $this->assertThrows(ValidationException::class, fn () => $svc->create($this->pcInput(['is_legacy' => '1', 'inventory_number' => 'PC88123'])), 'vergeben');
    }

    public function testUpdateRecordsDiffAndDetectsVersionConflict(): void
    {
        $this->loginAs('admin');
        $svc = $this->c->get(AssetService::class);
        $assets = $this->c->get(AssetRepository::class);
        $history = $this->c->get(AssetHistoryRepository::class);
        $id = $svc->create($this->pcInput(['name' => 'Alt']))['id'];
        $existing = $assets->find($id);

        $svc->update($id, $existing, ['name' => 'Neu', 'version' => (string) $existing['version']]);
        $fresh = $assets->find($id);
        $this->assertSame('Neu', $fresh['name']);
        $this->assertSame((int) $existing['version'] + 1, (int) $fresh['version']);
        $change = array_values(array_filter($history->forAsset($id), static fn ($h) => $h['field'] === 'name'))[0];
        $this->assertSame('Alt', $change['old_value']);
        $this->assertSame('Neu', $change['new_value']);

        // Zweiter Bearbeiter mit veralteter Version
        $this->assertThrows(ConflictException::class, fn () => $svc->update($id, $existing, ['name' => 'Konflikt', 'version' => (string) $existing['version']]));
        $this->assertSame('Neu', $assets->find($id)['name']);
    }

    public function testStatusChangeClearsAssignmentAndRequiresRetirePermission(): void
    {
        $this->loginAs('admin');
        $svc = $this->c->get(AssetService::class);
        $assets = $this->c->get(AssetRepository::class);
        $employeeId = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Max Muster']);
        $id = $svc->create($this->pcInput(['employee_id' => (string) $employeeId, 'expected_return_at' => '2030-01-01']))['id'];
        $this->assertSame('issued', $assets->find($id)['status_code']);

        $svc->changeStatus($id, $assets->find($id), 'defective', 'Display gebrochen');
        $row = $assets->find($id);
        $this->assertSame('defective', $row['status_code']);
        $this->assertSame($employeeId, (int) $row['employee_id'], 'Defekt behält die Zuordnung');

        $svc->changeStatus($id, $row, 'in_stock');
        $row = $assets->find($id);
        $this->assertNull($row['employee_id']);
        $this->assertNull($row['expected_return_at']);

        $this->loginAs('readonly');
        $this->assertThrows(ForbiddenException::class, fn () => $svc->changeStatus($id, $row, 'retired'));
        $this->loginAs('assetmanagement');
        $svc->changeStatus($id, $row, 'retired');
        $this->assertSame('retired', $assets->find($id)['status_code']);
    }

    public function testGlobalSearchFindsAssetsBySerialAndResolvesExactNumber(): void
    {
        $this->loginAs('admin');
        $svc = $this->c->get(AssetService::class);
        $created = $svc->create($this->pcInput(['serial_number' => 'XYZ-9876', 'name' => 'Suchgerät']));

        $search = $this->c->get(SearchService::class);
        $groups = array_column($search->search('xyz9876'), null, 'key');
        $found = array_filter($groups['assets']['items'], static fn ($i) => $i['url'] === '/assets/' . $created['id']);
        $this->assertCount(1, $found);
        $this->assertStringContains('SN XYZ-9876', array_values($found)[0]['meta']);

        $direct = $search->resolveDirect($created['inventory_number']);
        $this->assertSame('/assets/' . $created['id'], $direct);
    }
}
