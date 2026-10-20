<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Security\CurrentUser;
use App\Support\QrCode;
use App\Support\Validator;

/**
 * Inventaretiketten (Standard 45 × 30 mm): Layout aus system_settings (label.*), QR-Code nativ,
 * Druckprotokoll in der Assethistorie.
 */
final class LabelService
{
    public const DEFAULTS = [
        'company_name' => '',
        'show_logo' => '0',
        'logo_file' => '',
        'font_size_company' => '7',
        'font_size_inventory' => '11',
        'qr_size_mm' => '20',
        'qr_position' => 'left',
        'extra_field' => '',
        'extra_text' => '',
        'font_size_extra' => '6',
        'width_mm' => '45',
        'height_mm' => '30',
        'padding_mm' => '2',
    ];

    /** Wählbare Zusatzfelder (Wert → Bezeichnung) */
    public const EXTRA_FIELDS = [
        '' => 'Kein Zusatzfeld',
        'name' => 'Bezeichnung',
        'article' => 'Hersteller + Artikel',
        'serial_number' => 'Seriennummer',
        'asset_type' => 'Assettyp',
        'location' => 'Standort',
        'cost_center' => 'Kostenstelle',
        'purchase_date' => 'Kaufdatum',
        'text' => 'Fester Text (siehe unten)',
    ];

    public const QR_POSITIONS = ['left' => 'links', 'right' => 'rechts', 'top' => 'oben (zentriert)'];
    public const LOGO_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp'];
    private const LOGO_MAX_BYTES = 512 * 1024;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AssetRepository $assets,
        private readonly AssetHistoryRepository $history,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser,
        private readonly Config $config,
        private readonly string $storagePath
    ) {}

    /** Effektives Layout (Defaults + gespeicherte Werte). @return array<string,string> */
    public function layout(): array
    {
        $layout = array_merge(self::DEFAULTS, array_filter($this->settings->withPrefix('label.'), static fn ($v): bool => $v !== null && $v !== ''));
        if ($layout['company_name'] === '') {
            $layout['company_name'] = (string) $this->config->get('app.company_name', 'Firma');
        }

        return $layout;
    }

    /** URL, die im QR-Code steht und beim Aufruf das Asset auflöst. */
    public function qrUrl(string $inventoryNumber): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/a/' . rawurlencode($inventoryNumber);
    }

    /**
     * Druckdaten für ein Asset: Texte + QR-SVG.
     * @param array<string,mixed> $asset Zeile aus AssetRepository
     * @param array<string,string>|null $layout
     * @return array{id:int,inventory_number:string,company:string,extra:?string,qr_svg:string,url:string,logo_url:?string}
     */
    public function labelFor(array $asset, ?array $layout = null): array
    {
        $layout ??= $this->layout();
        $url = $this->qrUrl((string) $asset['inventory_number']);
        $qr = QrCode::encode($url, QrCode::ECC_M);

        return [
            'id' => (int) $asset['id'],
            'inventory_number' => (string) $asset['inventory_number'],
            'company' => $layout['company_name'],
            'extra' => $this->extraValue($asset, $layout),
            'qr_svg' => $qr->toSvg(0, '#000000', null, 'label-qr-svg'),
            'url' => $url,
            'logo_url' => $layout['show_logo'] === '1' && $layout['logo_file'] !== '' && is_file($this->logoPath()) ? '/labels/logo?v=' . filemtime($this->logoPath()) : null,
        ];
    }

    /**
     * Assets für den Druck: explizite IDs (Reihenfolge wie angegeben) oder Listenfilter.
     * @param array<int,int> $ids
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function assetsFor(array $ids, array $filters, int $limit = 500): array
    {
        if ($ids !== []) {
            $rows = $this->assets->search(['ids' => $ids, 'status' => 'all'], $limit);
            $byId = array_column($rows, null, 'id');
            $ordered = [];
            foreach ($ids as $id) {
                if (isset($byId[$id])) {
                    $ordered[] = $byId[$id];
                }
            }

            return $ordered;
        }

        return $this->assets->search($filters, $limit);
    }

    /** Protokolliert den Druck in der Historie (auch bei Nachdruck). @param array<int,int> $ids */
    public function recordPrinted(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return 0;
        }
        $count = 0;
        foreach ($this->assets->search(['ids' => $ids, 'status' => 'all'], 1000) as $asset) {
            $this->history->add((int) $asset['id'], [
                'event_type' => 'label_printed',
                'new_value' => (string) $asset['inventory_number'],
                'user_id' => $this->currentUser->id(),
                'actor_name' => $this->currentUser->displayName(),
            ]);
            $count++;
        }
        if ($count > 0) {
            $this->audit->log('print', 'label', null, $count . ' Etikett(en)', null, ['asset_ids' => array_values($ids)]);
        }

        return $count;
    }

    /**
     * Layout speichern (Administration). Gibt das gespeicherte Layout zurück.
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $logoUpload $_FILES-Eintrag
     * @return array<string,string>
     */
    public function saveLayout(array $input, ?array $logoUpload, bool $removeLogo): array
    {
        $v = new Validator($input);
        $v->string('company_name', 'Firmenname', true, 80)
            ->bool('show_logo')
            ->int('font_size_company', 'Schriftgröße Firmenname', true, 4, 20)
            ->int('font_size_inventory', 'Schriftgröße Inventarnummer', true, 6, 30)
            ->int('font_size_extra', 'Schriftgröße Zusatzfeld', true, 4, 20)
            ->int('qr_size_mm', 'QR-Größe', true, 8, 60)
            ->in('qr_position', 'QR-Position', array_keys(self::QR_POSITIONS), true)
            ->in('extra_field', 'Zusatzfeld', array_keys(self::EXTRA_FIELDS))
            ->string('extra_text', 'Fester Text', false, 80)
            ->int('width_mm', 'Breite', true, 20, 150)
            ->int('height_mm', 'Höhe', true, 15, 100)
            ->int('padding_mm', 'Innenabstand', true, 0, 10);
        $data = $v->validated();
        $errors = [];
        if ((int) $data['qr_size_mm'] > (int) $data['height_mm'] - 2 * (int) $data['padding_mm']) {
            $errors['qr_size_mm'] = 'Der QR-Code passt nicht in die Etikettenhöhe (Höhe minus Innenabstand).';
        }
        if ((int) $data['qr_size_mm'] > (int) $data['width_mm'] - 2 * (int) $data['padding_mm']) {
            $errors['qr_size_mm'] = 'Der QR-Code passt nicht in die Etikettenbreite (Breite minus Innenabstand).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $current = $this->layout();
        $logoFile = $current['logo_file'];
        if ($removeLogo && $logoFile !== '') {
            @unlink($this->logoPath());
            $logoFile = '';
        }
        if ($logoUpload !== null) {
            $logoFile = $this->storeLogo($logoUpload);
        }

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            if ($key === 'logo_file') {
                $values['label.logo_file'] = $logoFile;
                continue;
            }
            $values['label.' . $key] = (string) ($data[$key] ?? $default);
        }
        $this->settings->setMany($values, $this->currentUser->id());
        $this->audit->log('update', 'settings', null, 'Etikettenlayout', $current, $values);

        return $this->layout();
    }

    /**
     * Layoutabhängiges CSS (Maße in mm, @page) – als eigene Ressource ausgeliefert, da die CSP keine Inline-Styles erlaubt.
     * @param array<string,string> $layout
     */
    public function stylesheet(array $layout): string
    {
        $w = (int) $layout['width_mm'];
        $h = (int) $layout['height_mm'];
        $p = (int) $layout['padding_mm'];
        $qr = (int) $layout['qr_size_mm'];
        $fsCompany = (int) $layout['font_size_company'];
        $fsInv = (int) $layout['font_size_inventory'];
        $fsExtra = (int) $layout['font_size_extra'];
        $pos = in_array($layout['qr_position'], array_keys(self::QR_POSITIONS), true) ? $layout['qr_position'] : 'left';
        $direction = match ($pos) { 'right' => 'row-reverse', 'top' => 'column', default => 'row' };
        $textAlign = $pos === 'top' ? 'center' : 'left';
        $textItems = $pos === 'top' ? 'center' : 'flex-start';
        $logoH = max(3, min(8, $h - 2 * $p - 12));

        return <<<CSS
        @page { size: {$w}mm {$h}mm; margin: 0; }
        .label { width: {$w}mm; height: {$h}mm; padding: {$p}mm; flex-direction: {$direction}; }
        .label-qr { width: {$qr}mm; height: {$qr}mm; }
        .label-text { text-align: {$textAlign}; align-items: {$textItems}; }
        .label-company { font-size: {$fsCompany}pt; }
        .label-inventory { font-size: {$fsInv}pt; }
        .label-extra { font-size: {$fsExtra}pt; }
        .label-logo { max-height: {$logoH}mm; }
        CSS;
    }

    /** Layout aus Query-/Formularwerten übernehmen (nur bekannte Schlüssel, Zahlen begrenzt). @param array<string,mixed> $input @return array<string,string> */
    public function layoutFromInput(array $input): array
    {
        $layout = $this->layout();
        foreach (array_intersect_key($input, self::DEFAULTS) as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if (in_array($key, ['width_mm', 'height_mm', 'padding_mm', 'qr_size_mm', 'font_size_company', 'font_size_inventory', 'font_size_extra'], true)) {
                $value = (string) max(0, min(200, (int) $value));
            }
            if ($key === 'logo_file') {
                continue; // nie aus Eingaben übernehmen
            }
            $layout[$key] = $value;
        }

        return $layout;
    }

    public function logoPath(): string
    {
        $file = $this->layout()['logo_file'];

        return $this->storagePath . '/labels/' . basename($file);
    }

    public function logoMime(): ?string
    {
        $ext = strtolower(pathinfo($this->logoPath(), PATHINFO_EXTENSION));
        $mime = array_search($ext, self::LOGO_MIMES, true);

        return $mime === false ? null : $mime;
    }

    /** Beispieldatensatz für die Vorschau in den Einstellungen. @return array<string,mixed> */
    public function sampleAsset(): array
    {
        return $this->assets->search(['status' => 'all'], 1)[0] ?? $this->dummyAsset();
    }

    /** Fester Dummy-Datensatz für den Testdruck (unabhängig vom tatsächlichen Bestand). @return array<string,mixed> */
    public function dummyAsset(): array
    {
        return [
            'id' => 0, 'inventory_number' => 'PC' . date('y') . '001', 'name' => 'Notebook Vertrieb', 'manufacturer_name' => 'Lenovo',
            'article_name' => 'ThinkPad T14', 'serial_number' => 'PF3AB12X', 'asset_type_name' => 'PC / Endgerät', 'location_path' => 'Peine / IT-Lager',
            'cost_center_number' => '12345', 'purchase_date' => date('Y-m-d'),
        ];
    }

    /** @param array<string,mixed> $asset @param array<string,string> $layout */
    private function extraValue(array $asset, array $layout): ?string
    {
        $value = match ($layout['extra_field']) {
            'name' => $asset['name'] ?: ($asset['article_name'] ?? null),
            'article' => trim(($asset['manufacturer_name'] ?? '') . ' ' . ($asset['article_name'] ?? '')),
            'serial_number' => $asset['serial_number'] !== null ? 'SN ' . $asset['serial_number'] : null,
            'asset_type' => $asset['asset_type_name'] ?? null,
            'location' => $asset['location_path'] ?? null,
            'cost_center' => $asset['cost_center_number'] ?? null,
            'purchase_date' => !empty($asset['purchase_date']) ? date('d.m.Y', strtotime((string) $asset['purchase_date'])) : null,
            'text' => $layout['extra_text'],
            default => null,
        };
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $upload */
    private function storeLogo(array $upload): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ValidationException::single('logo', 'Das Logo konnte nicht hochgeladen werden.');
        }
        if ((int) $upload['size'] > self::LOGO_MAX_BYTES) {
            throw ValidationException::single('logo', 'Das Logo darf höchstens 512 KB groß sein.');
        }
        $tmp = (string) $upload['tmp_name'];
        $mime = $this->detectMime($tmp);
        if ($mime === null || !isset(self::LOGO_MIMES[$mime])) {
            throw ValidationException::single('logo', 'Erlaubte Formate: PNG, JPEG, SVG, WebP.');
        }
        $dir = $this->storagePath . '/labels';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Logo-Verzeichnis konnte nicht angelegt werden.');
        }
        foreach (glob($dir . '/logo.*') ?: [] as $old) {
            @unlink($old);
        }
        $name = 'logo.' . self::LOGO_MIMES[$mime];
        $target = $dir . '/' . $name;
        if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : rename($tmp, $target))) {
            throw new \RuntimeException('Logo konnte nicht gespeichert werden.');
        }

        return $name;
    }

    /** SVG ohne Skripte, Event-Handler, externe Referenzen oder eingebettete Fremdinhalte (gesamte Datei). */
    private function isSafeSvg(string $path): bool
    {
        $svg = (string) file_get_contents($path);
        if (strlen($svg) > 2 * 1024 * 1024) {
            return false;
        }
        $lower = strtolower($svg);
        foreach (['<script', '<foreignobject', '<iframe', '<embed', '<object', '<!entity', 'javascript:', 'data:text/html'] as $needle) {
            if (str_contains($lower, $needle)) {
                return false;
            }
        }
        // on*-Attribute (onload, onclick …) und externe href/xlink:href
        if (preg_match('/\son[a-z]+\s*=/i', $svg) === 1) {
            return false;
        }
        if (preg_match('/(?:xlink:)?href\s*=\s*["\']\s*(?!#|data:image\/)/i', $svg) === 1) {
            return false;
        }

        return true;
    }

    private function detectMime(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, 512);
        if (str_starts_with($head, "\x89PNG")) {
            return 'image/png';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (stripos($head, '<svg') !== false && $this->isSafeSvg($path)) {
            return 'image/svg+xml';
        }

        return null;
    }
}
