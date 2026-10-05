<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Exceptions\ValidationException;
use App\Repositories\OrvantaSignatureRepository;
use App\Repositories\PhonebookRepository;
use App\Services\Office\OfficeAppService;
use App\Services\SettingsService;
use App\Support\Html;
use App\Support\Validator;

/**
 * E-Mail-Signaturvorlagen fuer Orvanta.
 *
 * Vorlagen werden im Adminbereich gepflegt und ueber AD-Gruppen zugeordnet.
 * Name, Position, Abteilung und Rufnummer stammen aus dem Active Directory
 * (Telefonliste), Strasse/Ort und optional der Rufnummern-Praefix aus der
 * Vorlage, Schrift-/Trennzeichenfarbe und Logo aus den Designeinstellungen.
 *
 * Die fertige Signatur wird serverseitig beim Senden, Antworten und Speichern
 * von Entwuerfen angefuegt (append()); der Benutzer kann sie in Orvanta weder
 * entfernen noch bearbeiten. Ein umschliessendes `div.ov-signature-block`
 * dient als Marker, damit ein wieder geoeffneter Entwurf keine doppelte
 * Signatur erhaelt.
 *
 * @phpstan-import-type SignatureRow from OrvantaSignatureRepository
 * @phpstan-type Person array{display_name:string,title:string,department:string,phone:string}
 */
final class OrvantaSignatureService
{
    public const MARKER_CLASS = 'ov-signature-block';

    /** Zitatblock des Editors – die Signatur wird davor eingefuegt. */
    public const QUOTE_CLASS = 'ov-quote';

    public const PHONE_MODES = ['prefix', 'full'];

    public const EXTENSION_LENGTH = 4;

    /** Zeilenhoehe des Signaturtextes in px (10pt Schrift); das Logo wird auf Zeilen x Zeilenhoehe skaliert. */
    public const LINE_HEIGHT = 18;

    /** Obergrenze der Logobreite in px (sehr breite Logos werden proportional verkleinert). */
    public const LOGO_MAX_WIDTH = 240;

    public const DEFAULT_TEXT_COLOR = 'color_text';

    public const DEFAULT_SEPARATOR_COLOR = 'color_accent';

    private const FONT = 'Arial, Helvetica, sans-serif';

    /** Beispielperson fuer die Vorschau im Adminbereich. */
    public const SAMPLE_PERSON = [
        'display_name' => 'Erika Musterfrau',
        'title' => 'Sachbearbeiterin',
        'department' => 'Verwaltung',
        'phone' => '+49 5331 934-1234',
    ];

    /** @var array{src:string,width:int,height:int}|null|false false = noch nicht geladen; width/height 0 = unbekannt */
    private array|null|false $logo = false;

    /**
     * @param \Closure(): (array{path:string,mime:string}|null) $logoLoader liefert das hochgeladene Logo (LogoService::current())
     */
    public function __construct(
        private readonly OrvantaSignatureRepository $repository,
        private readonly PhonebookRepository $phonebook,
        private readonly SettingsService $settings,
        private readonly \Closure $logoLoader,
    ) {
    }

    // ------------------------------------------------------------------ Verwaltung

    /**
     * @return list<SignatureRow>
     */
    public function all(): array
    {
        return $this->repository->all();
    }

    /**
     * @return SignatureRow|null
     */
    public function find(int $id): ?array
    {
        return $id > 0 ? $this->repository->find($id) : null;
    }

    /**
     * @return SignatureRow
     */
    public static function blank(): array
    {
        return [
            'id' => 0,
            'name' => '',
            'greeting' => 'Mit freundlichen Grüßen',
            'street' => '',
            'postal_city' => '',
            'phone_mode' => 'prefix',
            'phone_prefix' => 'T.: +49 (05331) 934 - ',
            'text_color' => self::DEFAULT_TEXT_COLOR,
            'separator_color' => self::DEFAULT_SEPARATOR_COLOR,
            'groups' => [],
            'sort_order' => 1,
            'active' => true,
        ];
    }

    /**
     * Formulareingaben pruefen und normalisieren.
     *
     * @param array<string,mixed> $input name, greeting, street, postal_city, phone_mode, phone_prefix, text_color, separator_color, groups (String), sort_order, active
     * @return SignatureRow
     */
    public function validate(array $input, int $id = 0): array
    {
        $errors = [];
        $name = Validator::cleanText(is_scalar($input['name'] ?? null) ? (string) $input['name'] : '', 120);
        if ($name === '') {
            $errors['name'] = 'Bitte einen Namen für die Vorlage angeben.';
        }
        $greeting = Validator::cleanText(is_scalar($input['greeting'] ?? null) ? (string) $input['greeting'] : '', 120);
        $street = Validator::cleanText(is_scalar($input['street'] ?? null) ? (string) $input['street'] : '', 190);
        $postalCity = Validator::cleanText(is_scalar($input['postal_city'] ?? null) ? (string) $input['postal_city'] : '', 190);
        $phoneMode = is_scalar($input['phone_mode'] ?? null) ? (string) $input['phone_mode'] : 'prefix';
        if (!in_array($phoneMode, self::PHONE_MODES, true)) {
            $errors['phone_mode'] = 'Unbekannte Art der Rufnummer.';
            $phoneMode = 'prefix';
        }
        // Praefix bewusst nur rechts kuerzen: ein abschliessendes Leerzeichen gehoert dazu.
        $phonePrefix = is_scalar($input['phone_prefix'] ?? null) ? (string) $input['phone_prefix'] : '';
        $phonePrefix = preg_replace('/[\x00-\x1F\x7F]/u', '', ltrim($phonePrefix)) ?? '';
        if (mb_strlen($phonePrefix) > 64) {
            $errors['phone_prefix'] = 'Der Präfix darf höchstens 64 Zeichen lang sein.';
        }
        if ($phoneMode === 'prefix' && trim($phonePrefix) === '') {
            $errors['phone_prefix'] = 'Bitte den Präfix der Rufnummer angeben (z. B. „T.: +49 (05331) 934 - “).';
        }
        $colors = [];
        foreach (['text_color' => self::DEFAULT_TEXT_COLOR, 'separator_color' => self::DEFAULT_SEPARATOR_COLOR] as $field => $default) {
            $value = is_scalar($input[$field] ?? null) ? (string) $input[$field] : '';
            if ($value === '') {
                $value = $default;
            } elseif (!isset(SettingsService::THEME_COLORS[$value])) {
                $errors[$field] = 'Bitte eine der Designfarben auswählen.';
                $value = $default;
            }
            $colors[$field] = $value;
        }
        $groups = OfficeAppService::splitGroups(is_string($input['groups'] ?? null) ? $input['groups'] : implode(',', is_array($input['groups'] ?? null) ? $input['groups'] : []));
        foreach ($groups as $group) {
            if (mb_strlen($group) > 190) {
                $errors['groups'] = 'Gruppennamen dürfen höchstens 190 Zeichen lang sein.';
                break;
            }
        }
        $sortOrder = (int) ($input['sort_order'] ?? 1);
        if ($sortOrder < 1 || $sortOrder > 999) {
            $errors['sort_order'] = 'Die Reihenfolge muss zwischen 1 und 999 liegen.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'id' => $id,
            'name' => $name,
            'greeting' => $greeting,
            'street' => $street,
            'postal_city' => $postalCity,
            'phone_mode' => $phoneMode,
            'phone_prefix' => $phonePrefix,
            'text_color' => $colors['text_color'],
            'separator_color' => $colors['separator_color'],
            'groups' => $groups,
            'sort_order' => $sortOrder,
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    public function save(?int $id, array $input): int
    {
        if ($id !== null && $this->repository->find($id) === null) {
            throw new ValidationException(['id' => 'Die Vorlage wurde nicht gefunden.']);
        }

        return $this->repository->save($id, $this->validate($input, $id ?? 0));
    }

    public function delete(int $id): void
    {
        if ($id > 0) {
            $this->repository->delete($id);
        }
    }

    // ------------------------------------------------------------------ Zuordnung

    /**
     * Erste aktive Vorlage (nach Reihenfolge), deren AD-Gruppen den Gruppen
     * des Benutzers entsprechen (ohne Beachtung der Gross-/Kleinschreibung).
     *
     * @param list<string> $groups
     * @return SignatureRow|null
     */
    public function match(array $groups): ?array
    {
        $wanted = [];
        foreach ($groups as $group) {
            $group = mb_strtolower(trim((string) $group));
            if ($group !== '') {
                $wanted[$group] = true;
            }
        }
        if ($wanted === []) {
            return null;
        }
        foreach ($this->repository->all(true) as $signature) {
            foreach ($signature['groups'] as $group) {
                if (isset($wanted[mb_strtolower($group)])) {
                    return $signature;
                }
            }
        }

        return null;
    }

    /**
     * Fertige Signatur des angemeldeten Benutzers oder null, wenn ihm keine
     * Vorlage zugeordnet ist.
     *
     * @param array<string,mixed>|null $ssoUser
     * @return array{id:int,name:string,html:string}|null
     */
    public function forUser(?array $ssoUser): ?array
    {
        if ($ssoUser === null) {
            return null;
        }
        $groups = is_array($ssoUser['groups'] ?? null) ? array_map('strval', $ssoUser['groups']) : [];
        $signature = $this->match($groups);
        if ($signature === null) {
            return null;
        }

        return ['id' => $signature['id'], 'name' => $signature['name'], 'html' => $this->render($signature, $this->person($ssoUser))];
    }

    /**
     * AD-Angaben des Benutzers aus der Telefonliste; ohne Eintrag (Testbenutzer)
     * nur der Anzeigename der Anmeldung.
     *
     * @param array<string,mixed> $ssoUser
     * @return Person
     */
    public function person(array $ssoUser): array
    {
        $row = null;
        try {
            $row = $this->phonebook->findActiveById((int) ($ssoUser['id'] ?? 0));
        } catch (\Throwable) {
            // Ohne Telefonliste bleibt die Signatur auf den Anmeldenamen beschraenkt.
        }

        return [
            'display_name' => trim((string) ($row['display_name'] ?? $ssoUser['display_name'] ?? $ssoUser['username'] ?? '')),
            'title' => trim((string) ($row['title'] ?? '')),
            'department' => trim((string) ($row['department'] ?? '')),
            'phone' => trim((string) ($row['phone'] ?? '')),
        ];
    }

    // ------------------------------------------------------------------ Darstellung

    /**
     * HTML der Signatur (E-Mail-tauglich: Tabelle, Inline-Styles, Logo als
     * data-URI). Alle Werte werden escaped; Farben sind geprueft Hex-Werte.
     *
     * @param SignatureRow $signature
     * @param Person $person
     */
    public function render(array $signature, array $person): string
    {
        $theme = $this->settings->theme();
        $text = $theme[$signature['text_color'] ?? ''] ?? $theme[self::DEFAULT_TEXT_COLOR];
        $accent = $theme[$signature['separator_color'] ?? ''] ?? $theme[self::DEFAULT_SEPARATOR_COLOR];
        $separator = ' <span style="color:' . $accent . '">&#9632;</span> ';
        $base = 'font-family:' . self::FONT . ';font-size:10pt;color:' . $text;

        $lines = [];
        $first = array_filter([
            $person['display_name'] !== '' ? '<b>' . Html::e($person['display_name']) . '</b>' : '',
            Html::e($person['title']),
            Html::e($person['department']),
        ], static fn (string $part): bool => $part !== '');
        if ($first !== []) {
            $lines[] = implode($separator, $first);
        }
        $address = array_filter([Html::e($signature['street']), Html::e($signature['postal_city'])], static fn (string $part): bool => $part !== '');
        if ($address !== []) {
            $lines[] = implode($separator, $address);
        }
        $phone = $this->phoneLine($signature, $person['phone']);
        if ($phone !== '') {
            $lines[] = $phone;
        }

        $logo = $this->logo();
        $html = '<div class="' . self::MARKER_CLASS . '">';
        if ($signature['greeting'] !== '') {
            $html .= '<p style="margin:0 0 1.5em 0;' . $base . '">' . Html::e($signature['greeting']) . '</p>';
        }
        $html .= '<table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;' . $base . '"><tr>';
        if ($logo !== null && $lines !== []) {
            [$width, $height] = self::logoSize($logo['width'], $logo['height'], count($lines));
            $size = ($width > 0 ? ' width="' . $width . '"' : '') . ' height="' . $height . '"';
            $css = ($width > 0 ? 'width:' . $width . 'px;' : '') . 'height:' . $height . 'px;';
            $html .= '<td style="vertical-align:top;padding:0 12px 0 0">';
            $html .= '<img src="' . $logo['src'] . '" alt=""' . $size . ' style="display:block;' . $css . 'border:0">';
            $html .= '</td>';
        }
        $html .= '<td style="vertical-align:top;white-space:nowrap;line-height:' . self::LINE_HEIGHT . 'px;mso-line-height-rule:exactly;' . $base . '">' . implode('<br>', $lines) . '</td>';
        $html .= '</tr></table></div>';

        return $html;
    }

    /**
     * Logogroesse in px: Hoehe = Anzahl Textzeilen x Zeilenhoehe, Breite
     * proportional (hoechstens LOGO_MAX_WIDTH, dann wird die Hoehe reduziert).
     * Feste width/height-Attribute sind noetig, weil Outlook max-width und
     * height:auto ignoriert und das Bild sonst in Originalgroesse zeigt.
     *
     * @return array{0:int,1:int} [Breite (0 = unbekannt), Hoehe]
     */
    public static function logoSize(int $naturalWidth, int $naturalHeight, int $lines): array
    {
        $height = max(1, $lines) * self::LINE_HEIGHT;
        if ($naturalWidth <= 0 || $naturalHeight <= 0) {
            return [0, $height];
        }
        $width = (int) round($height * $naturalWidth / $naturalHeight);
        if ($width > self::LOGO_MAX_WIDTH) {
            $width = self::LOGO_MAX_WIDTH;
            $height = max(1, (int) round($width * $naturalHeight / $naturalWidth));
        }

        return [max(1, $width), $height];
    }

    /**
     * Vorschau einer Vorlage mit Beispiel- oder echten AD-Daten.
     *
     * @param SignatureRow $signature
     * @param Person|null $person
     */
    public function preview(array $signature, ?array $person = null): string
    {
        return $this->render($signature, $person ?? self::SAMPLE_PERSON);
    }

    /**
     * Rufnummernzeile: Praefix aus der Vorlage + fette Durchwahl (letzte vier
     * Ziffern der AD-Rufnummer) oder die komplette AD-Rufnummer.
     *
     * @param SignatureRow $signature
     */
    private function phoneLine(array $signature, string $phone): string
    {
        if ($phone === '') {
            return '';
        }
        if ($signature['phone_mode'] === 'full') {
            return 'T.: ' . Html::e($phone);
        }
        $extension = self::extension($phone);
        if ($extension === '') {
            return '';
        }

        return Html::e(rtrim($signature['phone_prefix'])) . ' <b>' . Html::e($extension) . '</b>';
    }

    /**
     * Vierstellige Durchwahl aus einer AD-Rufnummer (letzte Ziffern).
     */
    public static function extension(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }

        return strlen($digits) > self::EXTENSION_LENGTH ? substr($digits, -self::EXTENSION_LENGTH) : $digits;
    }

    /**
     * @return array{src:string,width:int,height:int}|null
     */
    private function logo(): ?array
    {
        if ($this->logo === false) {
            $this->logo = null;
            $current = ($this->logoLoader)();
            if (is_array($current) && is_readable($current['path'])) {
                $contents = file_get_contents($current['path']);
                if ($contents !== false && $contents !== '') {
                    [$width, $height] = self::imageDimensions($contents, $current['mime']);
                    $this->logo = [
                        'src' => 'data:' . $current['mime'] . ';base64,' . base64_encode($contents),
                        'width' => $width,
                        'height' => $height,
                    ];
                }
            }
        }

        return $this->logo;
    }

    /**
     * Pixelmasse eines Bildes (Raster ueber getimagesizefromstring, SVG ueber
     * width/height bzw. viewBox); [0, 0], wenn nicht ermittelbar.
     *
     * @return array{0:int,1:int}
     */
    public static function imageDimensions(string $contents, string $mime): array
    {
        if ($mime === 'image/svg+xml') {
            if (preg_match('~<svg\b[^>]*>~is', $contents, $tag) !== 1) {
                return [0, 0];
            }
            $attribute = static function (string $name) use ($tag): float {
                if (preg_match('~\s' . $name . '\s*=\s*["\']\s*([0-9.]+)\s*(px)?\s*["\']~i', $tag[0], $match) === 1) {
                    return (float) $match[1];
                }

                return 0.0;
            };
            $width = $attribute('width');
            $height = $attribute('height');
            if (($width <= 0 || $height <= 0)
                && preg_match('~\sviewBox\s*=\s*["\']\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*["\']~i', $tag[0], $box) === 1) {
                $width = (float) $box[1];
                $height = (float) $box[2];
            }

            return $width > 0 && $height > 0 ? [(int) round($width), max(1, (int) round($height))] : [0, 0];
        }
        $size = @getimagesizefromstring($contents);

        return is_array($size) && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : [0, 0];
    }

    // ------------------------------------------------------------------ Anfuegen

    /**
     * Signatur an einen Nachrichtentext anfuegen: eine bereits enthaltene
     * Signatur wird ersetzt; vor einem Zitatblock des Editors wird die
     * Signatur zwischen Text und Zitat gesetzt.
     */
    public static function append(string $body, string $signatureHtml): string
    {
        $body = self::strip($body);
        if ($signatureHtml === '') {
            return $body;
        }
        $position = self::quotePosition($body);
        if ($position !== null) {
            return substr($body, 0, $position) . $signatureHtml . substr($body, $position);
        }

        return $body . $signatureHtml;
    }

    /**
     * Entfernt eine bereits enthaltene Signatur (Marker-Block).
     */
    public static function strip(string $body): string
    {
        if (stripos($body, self::MARKER_CLASS) === false) {
            return $body;
        }
        $pattern = '~<div[^>]*\bclass\s*=\s*["\'][^"\']*\b' . preg_quote(self::MARKER_CLASS, '~') . '\b[^"\']*["\'][^>]*>.*?</table>\s*</div>~is';

        return preg_replace($pattern, '', $body) ?? $body;
    }

    private static function quotePosition(string $body): ?int
    {
        if (preg_match('~<div[^>]*\bclass\s*=\s*["\'][^"\']*\b' . preg_quote(self::QUOTE_CLASS, '~') . '\b~i', $body, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return (int) $match[0][1];
    }
}
