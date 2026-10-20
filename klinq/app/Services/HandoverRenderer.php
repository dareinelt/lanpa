<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Baukasten des Übergabeprotokolls: rendert eine Blockliste (JSON der Vorlage) mit den
 * Snapshot-Daten eines Protokolls zu HTML. Ohne Datenbank nutzbar (Vorschau, Tests, PDF).
 *
 * Blocktypen: heading, text, meta, employee, assets, confirmation, signature, divider, spacer.
 * Platzhalter in Texten: {mitarbeiter}, {vorname}, {nachname}, {personalnummer}, {abteilung},
 * {position}, {firma}, {datum}, {uhrzeit}, {protokollnummer}, {version}, {aussteller}, {anzahl}.
 */
final class HandoverRenderer
{
    public const BLOCK_TYPES = [
        'heading' => 'Überschrift',
        'text' => 'Textabsatz',
        'meta' => 'Protokolldaten (Nummer, Version, Datum …)',
        'employee' => 'Mitarbeiterdaten',
        'assets' => 'Tabelle der Arbeitsmittel',
        'confirmation' => 'Bestätigung (Checkbox)',
        'signature' => 'Unterschriftsfeld',
        'divider' => 'Trennlinie',
        'spacer' => 'Abstand',
    ];

    public const EMPLOYEE_FIELDS = [
        'display_name' => 'Name',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'personnel_number' => 'Personalnummer',
        'username' => 'Benutzername',
        'email' => 'E-Mail',
        'phone' => 'Telefon',
        'department' => 'Abteilung',
        'position' => 'Position',
        'location_path' => 'Standort',
        'cost_center' => 'Kostenstelle',
    ];

    public const ASSET_COLUMNS = [
        'inventory_number' => 'Inventarnummer',
        'asset_type' => 'Typ',
        'article' => 'Artikel',
        'manufacturer' => 'Hersteller',
        'category' => 'Kategorie',
        'serial_number' => 'Seriennummer',
        'mac_address' => 'MAC-Adresse',
        'imei' => 'IMEI',
        'assigned_at' => 'Ausgegeben am',
        'expected_return_at' => 'Rückgabe bis',
        'note' => 'Bemerkung',
    ];

    public const META_FIELDS = [
        'protocol_number' => 'Protokollnummer',
        'version' => 'Version',
        'date' => 'Datum',
        'company' => 'Unternehmen',
        'issuer' => 'Ausgestellt von',
        'item_count' => 'Anzahl Arbeitsmittel',
    ];

    public const PLACEHOLDERS = [
        '{mitarbeiter}' => 'Anzeigename des Mitarbeiters',
        '{vorname}' => 'Vorname',
        '{nachname}' => 'Nachname',
        '{personalnummer}' => 'Personalnummer',
        '{abteilung}' => 'Abteilung',
        '{position}' => 'Position',
        '{firma}' => 'Unternehmensname (Einstellungen)',
        '{datum}' => 'Datum der Ausstellung bzw. Unterschrift',
        '{uhrzeit}' => 'Uhrzeit der Unterschrift',
        '{protokollnummer}' => 'Protokollnummer',
        '{version}' => 'Versionsnummer',
        '{aussteller}' => 'Aussteller (angemeldeter Benutzer)',
        '{anzahl}' => 'Anzahl der aufgeführten Arbeitsmittel',
    ];

    /**
     * Rendert das Protokoll als HTML-Fragment.
     *
     * @param array<int,array<string,mixed>> $blocks Vorlage
     * @param array<string,mixed> $context protocol (protocol_number, version, signed_at, created_at, issuer_name, status),
     *                                     employee (Snapshot), items (Liste), company (string), signature (Data-URI|null),
     *                                     interactive (bool: Checkboxen/Signaturfeld als Formularelemente statt statisch)
     */
    public static function render(array $blocks, array $context): string
    {
        $out = [];
        $confirmationIndex = 0;
        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($block['type'])) {
                continue;
            }
            $out[] = match ((string) $block['type']) {
                'heading' => self::heading($block, $context),
                'text' => self::text($block, $context),
                'meta' => self::meta($block, $context),
                'employee' => self::employee($block, $context),
                'assets' => self::assets($block, $context),
                'confirmation' => self::confirmation($block, $context, $confirmationIndex++),
                'signature' => self::signature($block, $context),
                'divider' => '<hr class="hp-divider">',
                'spacer' => '<div class="hp-spacer hp-spacer-' . self::spacerSize((int) ($block['height'] ?? 16)) . '"></div>',
                default => '',
            };
        }

        return '<div class="hp-document">' . implode("\n", array_filter($out)) . '</div>';
    }

    /** Vollständiges HTML-Dokument (für die PDF-Erzeugung) inkl. Druck-Stylesheet. */
    public static function renderDocument(array $blocks, array $context): string
    {
        $title = self::placeholders('Übergabeprotokoll {protokollnummer}', $context);

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . self::e($title) . '</title><style>'
            . self::printCss() . '</style></head><body class="hp-print">' . self::render($blocks, $context) . '</body></html>';
    }

    /** Anzahl Bestätigungen (Checkboxen) mit Pflicht in einer Vorlage – für die serverseitige Prüfung. */
    public static function requiredConfirmations(array $blocks): array
    {
        $required = [];
        $i = 0;
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'confirmation') {
                if (!empty($block['required'])) {
                    $required[] = $i;
                }
                $i++;
            }
        }

        return $required;
    }

    /** Prüft und normalisiert eine vom Baukasten gelieferte Blockliste. @return array<int,array<string,mixed>> */
    public static function normalizeBlocks(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Die Vorlage enthält keine gültige Blockliste.');
        }
        $blocks = [];
        foreach ($raw as $block) {
            if (!is_array($block) || !isset(self::BLOCK_TYPES[(string) ($block['type'] ?? '')])) {
                continue;
            }
            $type = (string) $block['type'];
            $clean = ['type' => $type];
            switch ($type) {
                case 'heading':
                    $clean['text'] = self::str($block['text'] ?? '', 200);
                    $clean['level'] = in_array((int) ($block['level'] ?? 2), [1, 2, 3], true) ? (int) $block['level'] : 2;
                    break;
                case 'text':
                    $clean['text'] = self::str($block['text'] ?? '', 4000);
                    $clean['style'] = in_array($block['style'] ?? 'normal', ['normal', 'small', 'bold', 'muted'], true) ? (string) $block['style'] : 'normal';
                    break;
                case 'meta':
                    $clean['fields'] = self::pick($block['fields'] ?? array_keys(self::META_FIELDS), self::META_FIELDS);
                    break;
                case 'employee':
                    $clean['title'] = self::str($block['title'] ?? 'Mitarbeiter', 120);
                    $clean['fields'] = self::pick($block['fields'] ?? array_keys(self::EMPLOYEE_FIELDS), self::EMPLOYEE_FIELDS);
                    break;
                case 'assets':
                    $clean['title'] = self::str($block['title'] ?? 'Arbeitsmittel', 120);
                    $clean['columns'] = self::pick($block['columns'] ?? ['inventory_number', 'article', 'serial_number'], self::ASSET_COLUMNS);
                    if ($clean['columns'] === []) {
                        $clean['columns'] = ['inventory_number', 'article', 'serial_number'];
                    }
                    $clean['empty_text'] = self::str($block['empty_text'] ?? 'Keine protokollrelevanten Arbeitsmittel zugeordnet.', 300);
                    break;
                case 'confirmation':
                    $clean['text'] = self::str($block['text'] ?? '', 1000);
                    $clean['required'] = !empty($block['required']) && $block['required'] !== '0' && $block['required'] !== 'false';
                    break;
                case 'signature':
                    $clean['party'] = ($block['party'] ?? 'employee') === 'issuer' ? 'issuer' : 'employee';
                    $clean['label'] = self::str($block['label'] ?? ($clean['party'] === 'issuer' ? 'Unterschrift Aussteller' : 'Unterschrift Mitarbeiter'), 120);
                    break;
                case 'spacer':
                    $clean['height'] = max(4, min(80, (int) ($block['height'] ?? 16)));
                    break;
            }
            $blocks[] = $clean;
        }
        if ($blocks === []) {
            throw new \InvalidArgumentException('Die Vorlage muss mindestens einen Block enthalten.');
        }

        return $blocks;
    }

    /** Ersetzt Platzhalter in einem Text (Ausgabe ist bereits HTML-escaped). */
    public static function placeholders(string $text, array $context): string
    {
        $employee = (array) ($context['employee'] ?? []);
        $protocol = (array) ($context['protocol'] ?? []);
        $when = $protocol['signed_at'] ?? $protocol['created_at'] ?? null;
        $ts = is_string($when) && $when !== '' ? strtotime($when) : time();
        $map = [
            '{mitarbeiter}' => (string) ($employee['display_name'] ?? ''),
            '{vorname}' => (string) ($employee['first_name'] ?? ''),
            '{nachname}' => (string) ($employee['last_name'] ?? ''),
            '{personalnummer}' => (string) ($employee['personnel_number'] ?? ''),
            '{abteilung}' => (string) ($employee['department'] ?? ''),
            '{position}' => (string) ($employee['position'] ?? ''),
            '{firma}' => (string) ($context['company'] ?? ''),
            '{datum}' => $ts ? date('d.m.Y', $ts) : '',
            '{uhrzeit}' => $ts ? date('H:i', $ts) : '',
            '{protokollnummer}' => (string) ($protocol['protocol_number'] ?? ''),
            '{version}' => (string) ($protocol['version'] ?? ''),
            '{aussteller}' => (string) ($protocol['issuer_name'] ?? ''),
            '{anzahl}' => (string) count((array) ($context['items'] ?? [])),
        ];

        return strtr($text, $map);
    }

    // ------------------------------------------------------------------ Blöcke

    private static function heading(array $block, array $context): string
    {
        $level = in_array((int) ($block['level'] ?? 2), [1, 2, 3], true) ? (int) $block['level'] : 2;

        return "<h{$level} class=\"hp-heading\">" . self::e(self::placeholders((string) ($block['text'] ?? ''), $context)) . "</h{$level}>";
    }

    private static function text(array $block, array $context): string
    {
        $style = (string) ($block['style'] ?? 'normal');
        $text = self::e(self::placeholders((string) ($block['text'] ?? ''), $context));

        return '<p class="hp-text hp-text-' . self::e($style) . '">' . nl2br($text) . '</p>';
    }

    private static function meta(array $block, array $context): string
    {
        $protocol = (array) ($context['protocol'] ?? []);
        $when = $protocol['signed_at'] ?? $protocol['created_at'] ?? null;
        $values = [
            'protocol_number' => (string) ($protocol['protocol_number'] ?? ''),
            'version' => isset($protocol['version']) ? 'Version ' . (int) $protocol['version'] : '',
            'date' => is_string($when) && $when !== '' ? date('d.m.Y', (int) strtotime($when)) : date('d.m.Y'),
            'company' => (string) ($context['company'] ?? ''),
            'issuer' => (string) ($protocol['issuer_name'] ?? ''),
            'item_count' => (string) count((array) ($context['items'] ?? [])),
        ];
        $fields = self::pick($block['fields'] ?? array_keys(self::META_FIELDS), self::META_FIELDS);
        $cells = '';
        foreach ($fields as $f) {
            $cells .= '<div class="hp-meta-item"><span class="hp-label">' . self::e(self::META_FIELDS[$f]) . '</span><span class="hp-value">' . self::e($values[$f] !== '' ? $values[$f] : '–') . '</span></div>';
        }

        return '<div class="hp-meta">' . $cells . '</div>';
    }

    private static function employee(array $block, array $context): string
    {
        $employee = (array) ($context['employee'] ?? []);
        $fields = self::pick($block['fields'] ?? array_keys(self::EMPLOYEE_FIELDS), self::EMPLOYEE_FIELDS);
        $rows = '';
        foreach ($fields as $f) {
            $value = $f === 'cost_center'
                ? trim((string) ($employee['cost_center_number'] ?? '') . ' ' . (string) ($employee['cost_center_name'] ?? ''))
                : (string) ($employee[$f] ?? '');
            $rows .= '<tr><th>' . self::e(self::EMPLOYEE_FIELDS[$f]) . '</th><td>' . self::e($value !== '' ? $value : '–') . '</td></tr>';
        }
        $title = self::str($block['title'] ?? '', 120);

        return '<section class="hp-section hp-employee">' . ($title !== '' ? '<h3>' . self::e($title) . '</h3>' : '') . '<table class="hp-kv"><tbody>' . $rows . '</tbody></table></section>';
    }

    private static function assets(array $block, array $context): string
    {
        $items = (array) ($context['items'] ?? []);
        $columns = self::pick($block['columns'] ?? ['inventory_number', 'article', 'serial_number'], self::ASSET_COLUMNS);
        if ($columns === []) {
            $columns = ['inventory_number', 'article', 'serial_number'];
        }
        $title = self::str($block['title'] ?? '', 120);
        $html = '<section class="hp-section hp-assets">' . ($title !== '' ? '<h3>' . self::e($title) . '</h3>' : '');
        if ($items === []) {
            return $html . '<p class="hp-text hp-text-muted">' . self::e((string) ($block['empty_text'] ?? 'Keine Arbeitsmittel.')) . '</p></section>';
        }
        $html .= '<table class="hp-table"><thead><tr><th class="hp-num">#</th>';
        foreach ($columns as $c) {
            $html .= '<th>' . self::e(self::ASSET_COLUMNS[$c]) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_values($items) as $i => $item) {
            $html .= '<tr><td class="hp-num">' . ($i + 1) . '</td>';
            foreach ($columns as $c) {
                $html .= '<td>' . self::e(self::assetValue((array) $item, $c)) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private static function confirmation(array $block, array $context, int $index): string
    {
        $text = self::e(self::placeholders((string) ($block['text'] ?? ''), $context));
        $required = !empty($block['required']);
        if (!empty($context['interactive'])) {
            return '<label class="hp-confirm hp-confirm-interactive"><input type="checkbox" name="confirmations[]" value="' . $index . '"' . ($required ? ' required data-required-confirmation' : '') . '> <span>' . $text . ($required ? ' <span class="hp-required">*</span>' : '') . '</span></label>';
        }
        $checked = !empty($context['confirmed']) || (($context['protocol']['status'] ?? '') === 'signed');

        return '<div class="hp-confirm"><span class="hp-checkbox' . ($checked ? ' is-checked' : '') . '" aria-hidden="true">' . ($checked ? '✕' : '') . '</span><span>' . $text . '</span></div>';
    }

    private static function signature(array $block, array $context): string
    {
        $party = ($block['party'] ?? 'employee') === 'issuer' ? 'issuer' : 'employee';
        $label = self::e(self::placeholders((string) ($block['label'] ?? ''), $context));
        $protocol = (array) ($context['protocol'] ?? []);
        $employee = (array) ($context['employee'] ?? []);
        $signedAt = is_string($protocol['signed_at'] ?? null) && $protocol['signed_at'] !== '' ? date('d.m.Y H:i', (int) strtotime($protocol['signed_at'])) : null;

        if ($party === 'issuer') {
            $name = (string) ($protocol['issuer_name'] ?? '');

            return '<div class="hp-signature hp-signature-issuer"><div class="hp-signature-box hp-signature-typed">' . self::e($name) . '</div><div class="hp-signature-label">' . $label . ($name !== '' ? ' – ' . self::e($name) : '') . '</div></div>';
        }

        if (!empty($context['interactive'])) {
            return '<div class="hp-signature hp-signature-employee" data-signature-block><div class="signature-pad-wrapper"><canvas id="signature-pad" class="signature-pad" aria-label="Unterschriftsfeld"></canvas><span class="signature-hint">Bitte hier mit Finger oder Stift unterschreiben</span></div>'
                . '<div class="signature-actions"><button type="button" class="btn btn-ghost btn-sm" data-signature-clear>Löschen</button></div>'
                . '<div class="hp-signature-label">' . $label . ' – ' . self::e((string) ($employee['display_name'] ?? '')) . '</div></div>';
        }
        $img = is_string($context['signature'] ?? null) && $context['signature'] !== ''
            ? '<img class="hp-signature-image" src="' . self::e((string) $context['signature']) . '" alt="Unterschrift">'
            : '';

        return '<div class="hp-signature hp-signature-employee"><div class="hp-signature-box">' . $img . '</div><div class="hp-signature-label">' . $label . ' – ' . self::e((string) ($employee['display_name'] ?? '')) . ($signedAt !== null ? ', ' . self::e($signedAt) : '') . '</div></div>';
    }

    // ------------------------------------------------------------------ Hilfen

    private static function assetValue(array $item, string $column): string
    {
        $value = match ($column) {
            'article' => trim((string) ($item['manufacturer_name'] ?? '') . ' ' . (string) ($item['article_name'] ?? $item['name'] ?? '')),
            'asset_type' => (string) ($item['asset_type_name'] ?? ''),
            'manufacturer' => (string) ($item['manufacturer_name'] ?? ''),
            'category' => (string) ($item['category_name'] ?? ''),
            'assigned_at', 'expected_return_at' => self::date($item[$column] ?? null),
            default => (string) ($item[$column] ?? ''),
        };

        return $value !== '' ? $value : '–';
    }

    /** Abstand auf feste Stufen runden (kein Inline-Style wegen CSP). */
    private static function spacerSize(int $height): int
    {
        foreach ([8, 16, 24, 32, 48, 64] as $step) {
            if ($height <= $step) {
                return $step;
            }
        }

        return 64;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $ts = strtotime($value);

        return $ts ? date('d.m.Y', $ts) : '';
    }

    /** @param array<string,string> $allowed @return list<string> */
    private static function pick(mixed $values, array $allowed): array
    {
        if (is_string($values)) {
            $values = array_map('trim', explode(',', $values));
        }
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $values), static fn (string $v): bool => isset($allowed[$v]))));
    }

    private static function str(mixed $value, int $max): string
    {
        return mb_substr(trim((string) (is_scalar($value) ? $value : '')), 0, $max);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Druck-/PDF-Stylesheet (eigenständig, ohne App-CSS). */
    public static function printCss(): string
    {
        return <<<'CSS'
@page { size: A4; margin: 18mm 16mm 20mm 16mm; }
body.hp-print { margin: 0; padding: 24px; }
.hp-document { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #1a1a1a; line-height: 1.45; }
.hp-document { max-width: 100%; }
.hp-heading { margin: 0 0 8pt; color: #0f3d6b; }
h1.hp-heading { font-size: 18pt; border-bottom: 2px solid #0f6cbd; padding-bottom: 4pt; }
h2.hp-heading { font-size: 14pt; margin-top: 12pt; }
h3.hp-heading, .hp-section h3 { font-size: 11.5pt; margin: 12pt 0 5pt; color: #0f3d6b; }
.hp-text { margin: 0 0 7pt; }
.hp-text-small { font-size: 9pt; }
.hp-text-bold { font-weight: 600; }
.hp-text-muted { color: #666; }
.hp-meta { display: flex; flex-wrap: wrap; gap: 6pt 18pt; margin: 6pt 0 10pt; padding: 7pt 9pt; background: #f2f6fa; border: 1px solid #d5e0ea; border-radius: 4px; }
.hp-meta-item { display: flex; flex-direction: column; min-width: 90pt; }
.hp-label { font-size: 8pt; text-transform: uppercase; letter-spacing: .04em; color: #5a6b7c; }
.hp-value { font-weight: 600; }
.hp-kv { border-collapse: collapse; width: 100%; }
.hp-kv th { text-align: left; width: 32%; font-weight: 500; color: #5a6b7c; padding: 3pt 6pt 3pt 0; vertical-align: top; }
.hp-kv td { padding: 3pt 0; }
.hp-table { border-collapse: collapse; width: 100%; font-size: 9.5pt; page-break-inside: auto; }
.hp-table th, .hp-table td { border: 1px solid #c9d3dd; padding: 4pt 5pt; text-align: left; vertical-align: top; }
.hp-table th { background: #e8eef5; font-weight: 600; }
.hp-table tr { page-break-inside: avoid; }
.hp-num { width: 18pt; text-align: right; color: #5a6b7c; }
.hp-confirm { display: flex; gap: 7pt; align-items: flex-start; margin: 8pt 0; }
.hp-checkbox { display: inline-block; width: 11pt; height: 11pt; border: 1.5px solid #333; border-radius: 2px; text-align: center; line-height: 10pt; font-size: 9pt; flex: none; }
.hp-required { color: #b3261e; }
.hp-divider { border: 0; border-top: 1px solid #c9d3dd; margin: 10pt 0; }
.hp-spacer-8 { height: 8px; } .hp-spacer-16 { height: 16px; } .hp-spacer-24 { height: 24px; } .hp-spacer-32 { height: 32px; } .hp-spacer-48 { height: 48px; } .hp-spacer-64 { height: 64px; }
.hp-signature { margin-top: 22pt; page-break-inside: avoid; max-width: 300pt; }
.hp-signature-box { height: 70pt; border-bottom: 1px solid #333; display: flex; align-items: flex-end; }
.hp-signature-typed { font-family: "Brush Script MT", "Segoe Script", cursive; font-size: 16pt; color: #333; padding-bottom: 4pt; }
.hp-signature-image { max-height: 68pt; max-width: 100%; }
.hp-signature-label { font-size: 8.5pt; color: #5a6b7c; margin-top: 3pt; }
CSS;
    }
}
