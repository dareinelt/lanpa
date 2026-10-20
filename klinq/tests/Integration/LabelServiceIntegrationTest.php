<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\AuditLogRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\AuditLogService;
use App\Services\LabelService;
use App\Services\SettingsService;
use Tests\Support\DatabaseTestCase;

final class LabelServiceIntegrationTest extends DatabaseTestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c->get(CurrentUser::class)->login(['id' => 1, 'username' => 'tester', 'display_name' => 'Test User', 'role' => 'admin']);
        $this->storage = sys_get_temp_dir() . '/labels-test-' . bin2hex(random_bytes(4));
        mkdir($this->storage);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/labels/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage . '/labels');
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function service(): LabelService
    {
        return new LabelService(
            $this->c->get(SettingsService::class),
            $this->c->get(AssetRepository::class),
            $this->c->get(AssetHistoryRepository::class),
            $this->c->get(AuditLogService::class),
            $this->c->get(CurrentUser::class),
            $this->c->get(Config::class),
            $this->storage
        );
    }

    /** @return array<string,mixed> */
    private function createAsset(string $typeCode, array $overrides = []): array
    {
        $typeId = (string) $this->c->get(AssetTypeRepository::class)->findByCode($typeCode)['id'];

        $created = $this->c->get(AssetService::class)->create(array_merge(['asset_type_id' => $typeId, 'name' => 'Etikettentest'], $overrides));

        return $this->c->get(AssetRepository::class)->find((int) $created['id']);
    }

    /** @return array<string,mixed> */
    private function validLayout(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Testfirma AG', 'show_logo' => '0', 'font_size_company' => '7', 'font_size_inventory' => '11', 'font_size_extra' => '6',
            'qr_size_mm' => '20', 'qr_position' => 'left', 'extra_field' => '', 'extra_text' => '', 'width_mm' => '45', 'height_mm' => '30', 'padding_mm' => '2',
        ], $overrides);
    }

    public function testQrUrlUsesAppUrlAndResolvePath(): void
    {
        $base = rtrim((string) $this->c->get(Config::class)->get('app.url'), '/');
        $this->assertSame($base . '/a/PC26001', $this->service()->qrUrl('PC26001'));
        $this->assertSame($base . '/a/ABC%2F1', $this->service()->qrUrl('ABC/1'));
    }

    public function testLabelForBuildsTextsAndQrAccordingToLayout(): void
    {
        $asset = $this->createAsset('PC', ['serial_number' => 'SN-4711', 'name' => 'Notebook']);
        $svc = $this->service();

        $label = $svc->labelFor($asset, array_merge($svc->layout(), ['company_name' => 'Testfirma AG', 'extra_field' => 'serial_number']));
        $this->assertSame((int) $asset['id'], $label['id']);
        $this->assertSame($asset['inventory_number'], $label['inventory_number']);
        $this->assertSame('Testfirma AG', $label['company']);
        $this->assertSame('SN SN-4711', $label['extra']);
        $this->assertStringContains('<svg', $label['qr_svg']);
        $this->assertStringContains('class="label-qr-svg"', $label['qr_svg']);
        $this->assertSame($svc->qrUrl($asset['inventory_number']), $label['url']);
        $this->assertNull($label['logo_url']);

        $text = $svc->labelFor($asset, array_merge($svc->layout(), ['extra_field' => 'text', 'extra_text' => ' Eigentum der Firma ']));
        $this->assertSame('Eigentum der Firma', $text['extra']);
        $none = $svc->labelFor($asset, array_merge($svc->layout(), ['extra_field' => '']));
        $this->assertNull($none['extra']);
    }

    public function testAssetsForKeepsRequestedOrderAndSkipsUnknownIds(): void
    {
        $a = $this->createAsset('PC');
        $b = $this->createAsset('MD');
        $c = $this->createAsset('PC');
        $ids = [(int) $c['id'], 999999, (int) $a['id'], (int) $b['id']];

        $rows = $this->service()->assetsFor($ids, []);
        $this->assertSame([(int) $c['id'], (int) $a['id'], (int) $b['id']], array_map(static fn (array $r): int => (int) $r['id'], $rows));

        // Ohne IDs: Filter wie in der Liste
        $filtered = $this->service()->assetsFor([], ['q' => $b['inventory_number']]);
        $this->assertCount(1, $filtered);
        $this->assertSame($b['inventory_number'], $filtered[0]['inventory_number']);
    }

    public function testRecordPrintedWritesHistoryPerAssetAndOneAuditEntry(): void
    {
        $a = $this->createAsset('PC');
        $b = $this->createAsset('PC');
        $history = $this->c->get(AssetHistoryRepository::class);

        $count = $this->service()->recordPrinted([(int) $a['id'], (int) $b['id'], 999999]);
        $this->assertSame(2, $count);

        $entry = $history->forAsset((int) $a['id'])[0];
        $this->assertSame('label_printed', $entry['event_type']);
        $this->assertSame($a['inventory_number'], $entry['new_value']);
        $this->assertSame('Test User', $entry['actor_name']);
        $this->assertSame(1, (int) $entry['user_id']);

        // Nachdruck wird erneut protokolliert
        $this->service()->recordPrinted([(int) $a['id']]);
        $printed = array_filter($history->forAsset((int) $a['id']), static fn (array $h): bool => $h['event_type'] === 'label_printed');
        $this->assertCount(2, $printed);

        $audit = $this->c->get(AuditLogRepository::class)->search(['object_type' => 'label'], 10);
        $this->assertCount(2, $audit);
        $this->assertSame('print', $audit[0]['action']);

        $this->assertSame(0, $this->service()->recordPrinted([]));
    }

    public function testSaveLayoutPersistsSettingsAndLayoutReflectsThem(): void
    {
        $svc = $this->service();
        $saved = $svc->saveLayout($this->validLayout(['qr_position' => 'right', 'extra_field' => 'serial_number', 'width_mm' => '50', 'company_name' => ' Testfirma AG ']), null, false);

        $this->assertSame('right', $saved['qr_position']);
        $this->assertSame('serial_number', $saved['extra_field']);
        $this->assertSame('50', $saved['width_mm']);
        $this->assertSame('Testfirma AG', $saved['company_name']);
        $this->assertSame('50', $this->c->get(SettingsService::class)->get('label.width_mm'));
        $this->assertSame('right', $svc->layout()['qr_position']);
        $this->assertSame('', $svc->layout()['logo_file']);
    }

    public function testSaveLayoutRejectsQrThatDoesNotFit(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->saveLayout($this->validLayout(['qr_size_mm' => '28', 'height_mm' => '30', 'padding_mm' => '2']), null, false));
        $this->assertTrue(isset($e->errors()['qr_size_mm']));

        $this->assertThrows(ValidationException::class, fn () => $this->service()->saveLayout($this->validLayout(['qr_position' => 'diagonal']), null, false));
        $this->assertThrows(ValidationException::class, fn () => $this->service()->saveLayout($this->validLayout(['company_name' => '']), null, false));
    }

    public function testLogoUploadIsTypeCheckedStoredAndRemovable(): void
    {
        $svc = $this->service();
        $png = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFklEQVR4nGNgYPj/n4GBgYGBgYGBAQAf7wL/8n7GOAAAAABJRU5ErkJggg=='));
        $upload = ['error' => UPLOAD_ERR_OK, 'size' => filesize($png), 'tmp_name' => $png, 'name' => 'irgendwas.bin'];

        $layout = $svc->saveLayout($this->validLayout(['show_logo' => '1']), $upload, false);
        $this->assertSame('logo.png', $layout['logo_file']);
        $this->assertTrue(is_file($this->storage . '/labels/logo.png'));
        $this->assertSame('image/png', $svc->logoMime());

        $asset = $this->createAsset('PC');
        $this->assertMatches('#^/labels/logo\?v=\d+$#', (string) $svc->labelFor($asset)['logo_url']);

        // Falscher Typ wird abgelehnt
        $txt = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($txt, 'kein bild');
        $this->assertThrows(ValidationException::class, fn () => $svc->saveLayout($this->validLayout(), ['error' => UPLOAD_ERR_OK, 'size' => 9, 'tmp_name' => $txt, 'name' => 'x.png'], false), 'Erlaubte Formate');
        @unlink($txt);

        // Entfernen
        $layout = $svc->saveLayout($this->validLayout(['show_logo' => '1']), null, true);
        $this->assertSame('', $layout['logo_file']);
        $this->assertFalse(is_file($this->storage . '/labels/logo.png'));
        $this->assertNull($svc->labelFor($asset)['logo_url']);
    }

    public function testStylesheetContainsPageSizeAndLayoutValues(): void
    {
        $svc = $this->service();
        $css = $svc->stylesheet(array_merge($svc->layout(), ['width_mm' => '45', 'height_mm' => '30', 'padding_mm' => '2', 'qr_size_mm' => '20', 'qr_position' => 'right', 'font_size_inventory' => '11']));
        $this->assertStringContains('@page { size: 45mm 30mm; margin: 0; }', $css);
        $this->assertStringContains('width: 45mm; height: 30mm; padding: 2mm; flex-direction: row-reverse;', $css);
        $this->assertStringContains('.label-qr { width: 20mm; height: 20mm; }', $css);
        $this->assertStringContains('.label-inventory { font-size: 11pt; }', $css);

        $top = $svc->stylesheet(array_merge($svc->layout(), ['qr_position' => 'top']));
        $this->assertStringContains('flex-direction: column', $top);
        $this->assertStringContains('text-align: center', $top);
    }

    public function testLayoutFromInputOnlyAcceptsKnownKeysAndClampsNumbers(): void
    {
        $layout = $this->service()->layoutFromInput(['width_mm' => '9999', 'qr_size_mm' => '-5', 'company_name' => 'Vorschau', 'logo_file' => '../../etc/passwd', 'evil' => 'x']);
        $this->assertSame('200', $layout['width_mm']);
        $this->assertSame('0', $layout['qr_size_mm']);
        $this->assertSame('Vorschau', $layout['company_name']);
        $this->assertSame('', $layout['logo_file']);
        $this->assertFalse(isset($layout['evil']));
    }
}
