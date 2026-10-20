<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Rendert einen Entnahme- bzw. Retourennachweis (Bewegung) als eigenständiges HTML-Dokument –
 * für die PDF-Erzeugung und die E-Mail an den Mitarbeiter. Anders als das Übergabeprotokoll ist
 * der Nachweis nicht per Baukasten anpassbar, sondern ein festes, knappes Layout.
 *
 * @phpstan-type Movement array<string,mixed>
 */
final class MovementReceiptRenderer
{
    /** @param array<string,mixed> $movement Zeile aus MovementRepository (inkl. Joins), $company Firmenname */
    public static function renderDocument(array $movement, string $company): string
    {
        $isCheckout = ($movement['type'] ?? '') === 'checkout';
        $title = ($isCheckout ? 'Entnahmenachweis' : 'Retourennachweis') . ' ' . (string) ($movement['inventory_number'] ?? '');

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . self::e($title) . '</title><style>'
            . self::css() . '</style></head><body class="mr-print">' . self::render($movement, $company) . '</body></html>';
    }

    /** @param array<string,mixed> $movement */
    public static function render(array $movement, string $company): string
    {
        $isCheckout = ($movement['type'] ?? '') === 'checkout';
        $heading = $isCheckout ? 'Entnahmenachweis' : 'Retourennachweis';
        $date = is_string($movement['movement_date'] ?? null) && $movement['movement_date'] !== ''
            ? date('d.m.Y', (int) strtotime((string) $movement['movement_date'])) : '–';

        $rows = [
            'Inventarnummer' => (string) ($movement['inventory_number'] ?? '–'),
            'Artikel' => trim(((string) ($movement['manufacturer_name'] ?? '')) . ' ' . ((string) ($movement['article_name'] ?? $movement['asset_name'] ?? ''))) ?: '–',
            'Typ' => (string) ($movement['asset_type_name'] ?? '–'),
            'Seriennummer' => (string) ($movement['serial_number'] ?? '') !== '' ? (string) $movement['serial_number'] : '–',
            'Mitarbeiter' => (string) ($movement['employee_name'] ?? '–'),
            $isCheckout ? 'Ausgegeben am' : 'Zurückgenommen am' => $date,
            $isCheckout ? 'Neuer Standort' : 'Standort' => (string) ($movement['to_location_path'] ?? '–'),
        ];
        if (!$isCheckout) {
            $conditions = MovementService::CONDITIONS;
            $rows['Zustand'] = $conditions[$movement['condition_code'] ?? ''] ?? '–';
            if (!empty($movement['has_damage'])) {
                $rows['Schaden'] = (string) ($movement['damage_description'] ?? '');
            }
            $rows['Zubehör vollständig'] = !empty($movement['accessories_checked']) ? 'Ja' : 'Nein';
        } else {
            $rows['Rückgabe erwartet bis'] = is_string($movement['expected_return_at'] ?? null) && $movement['expected_return_at'] !== ''
                ? date('d.m.Y', (int) strtotime((string) $movement['expected_return_at'])) : 'unbefristet';
        }
        if (!empty($movement['note'])) {
            $rows['Bemerkung'] = (string) $movement['note'];
        }

        $body = '<div class="mr-document">';
        $body .= '<header class="mr-header"><h1>' . self::e($heading) . '</h1><p class="mr-company">' . self::e($company) . '</p></header>';
        $body .= '<table class="mr-kv"><tbody>';
        foreach ($rows as $label => $value) {
            $body .= '<tr><th>' . self::e($label) . '</th><td>' . nl2br(self::e($value !== '' ? $value : '–')) . '</td></tr>';
        }
        $body .= '</tbody></table>';
        $body .= '<p class="mr-footer">Erstellt am ' . self::e(date('d.m.Y H:i')) . ' Uhr · automatisch erzeugter Nachweis, keine Unterschrift erforderlich.</p>';
        $body .= '</div>';

        return $body;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
@page { size: A4; margin: 20mm 18mm; }
body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11pt; color: #1a1a1a; }
.mr-header { margin-bottom: 16pt; border-bottom: 2pt solid #1a1a1a; padding-bottom: 8pt; }
.mr-header h1 { font-size: 18pt; margin: 0 0 4pt 0; }
.mr-company { margin: 0; color: #444; }
.mr-kv { width: 100%; border-collapse: collapse; }
.mr-kv th, .mr-kv td { text-align: left; padding: 6pt 8pt; border-bottom: 0.5pt solid #ccc; vertical-align: top; }
.mr-kv th { width: 40%; color: #444; font-weight: 600; }
.mr-footer { margin-top: 18pt; font-size: 9pt; color: #666; }
CSS;
    }
}
