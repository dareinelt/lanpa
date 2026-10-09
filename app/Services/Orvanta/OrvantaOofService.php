<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Exceptions\ValidationException;
use App\Repositories\OrvantaOofRepository;
use App\Services\Office\OfficeAppService;
use App\Support\Html;
use App\Support\Validator;

/**
 * Abwesenheitsnotizen (Out-of-Office) fuer Orvanta.
 *
 * Vorlagen werden im Adminbereich gepflegt und ueber AD-Gruppen zugewiesen.
 * Jede Vorlage besteht aus einem festen Text, den der Benutzer nicht aendern
 * kann, und einem dynamischen Beispieltext, den der Benutzer in Orvanta an
 * seine Vertretung anpasst. Beim Aktivieren wird der zusammengesetzte Text
 * zusammen mit der zugewiesenen Signatur auf den Exchange-Server uebertragen
 * (SetUserOofSettings); den Versand uebernimmt der Server, Orvanta muss
 * dafuer nicht geoeffnet bleiben. Massgeblich fuer den Banner in Orvanta ist
 * deshalb der Zustand auf dem Server.
 *
 * @phpstan-import-type OofTemplateRow from OrvantaOofRepository
 * @phpstan-import-type OofSettingsRow from OrvantaOofRepository
 * @phpstan-type OofInput array{template_id:int,dynamic_text:string,external_audience:string,schedule_mode:string,start_date:string,end_date:string,active:bool}
 */
final class OrvantaOofService
{
    /** Empfaenger der Notiz: nur interne Postfaecher oder auch Externe. */
    public const AUDIENCES = ['none', 'all'];

    public const SCHEDULE_MODES = ['range', 'until_off'];

    /** Laenge des festen und des dynamischen Textes (Zeichen). */
    public const MAX_FIXED_TEXT = 4000;

    public const MAX_DYNAMIC_TEXT = 2000;

    private const FONT = 'Arial, Helvetica, sans-serif';

    public function __construct(
        private readonly OrvantaOofRepository $repository,
        private readonly OrvantaSignatureService $signatures,
        private readonly OrvantaConfigService $config,
    ) {
    }

    // ------------------------------------------------------------------ Verwaltung

    /**
     * @return list<OofTemplateRow>
     */
    public function all(): array
    {
        return $this->repository->all();
    }

    /**
     * @return OofTemplateRow|null
     */
    public function find(int $id): ?array
    {
        return $id > 0 ? $this->repository->find($id) : null;
    }

    /**
     * @return OofTemplateRow
     */
    public static function blank(): array
    {
        return [
            'id' => 0,
            'name' => '',
            'fixed_text' => '',
            'example_text' => '',
            'groups' => [],
            'sort_order' => 1,
            'active' => true,
        ];
    }

    /**
     * Formulareingaben pruefen und normalisieren.
     *
     * @param array<string,mixed> $input name, fixed_text, example_text, groups (String), sort_order, active
     * @return OofTemplateRow
     */
    public function validate(array $input, int $id = 0): array
    {
        $errors = [];
        $name = Validator::cleanText(is_scalar($input['name'] ?? null) ? (string) $input['name'] : '', 120);
        if ($name === '') {
            $errors['name'] = 'Bitte einen Namen für die Vorlage angeben.';
        }
        $fixed = self::cleanMultiline(is_scalar($input['fixed_text'] ?? null) ? (string) $input['fixed_text'] : '', self::MAX_FIXED_TEXT);
        if (trim($fixed) === '') {
            $errors['fixed_text'] = 'Bitte den festen Text der Abwesenheitsnotiz angeben.';
        }
        $example = self::cleanMultiline(is_scalar($input['example_text'] ?? null) ? (string) $input['example_text'] : '', self::MAX_DYNAMIC_TEXT);
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
            'fixed_text' => $fixed,
            'example_text' => $example,
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
     * @return OofTemplateRow|null
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
        foreach ($this->repository->all(true) as $template) {
            foreach ($template['groups'] as $group) {
                if (isset($wanted[mb_strtolower($group)])) {
                    return $template;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $ssoUser
     * @return OofTemplateRow|null
     */
    public function forUser(?array $ssoUser): ?array
    {
        if ($ssoUser === null) {
            return null;
        }
        $groups = is_array($ssoUser['groups'] ?? null) ? array_map('strval', $ssoUser['groups']) : [];

        return $this->match($groups);
    }

    // ------------------------------------------------------------------ Einstellungen

    /**
     * @param OofTemplateRow|null $template
     * @return OofSettingsRow
     */
    public function settings(string $uid, ?array $template): array
    {
        $stored = $uid !== '' ? $this->repository->settings($uid) : null;
        if ($stored === null) {
            return [
                'uid' => $uid,
                'template_id' => $template['id'] ?? 0,
                'dynamic_text' => $template['example_text'] ?? '',
                'external_audience' => 'none',
                'schedule_mode' => 'until_off',
                'start_date' => '',
                'end_date' => '',
                'active' => false,
            ];
        }
        // Eine neu zugewiesene Vorlage bringt ihren Beispieltext mit; die
        // Notiz wird dabei nicht ungefragt auf dem Server aktiviert.
        if ($template !== null && $stored['template_id'] !== $template['id']) {
            $stored['template_id'] = $template['id'];
            $stored['dynamic_text'] = $template['example_text'];
            $stored['active'] = false;
        }

        return $stored;
    }

    /**
     * Eingaben des Dialogs pruefen und normalisieren.
     *
     * @param array<string,mixed> $input
     * @return OofInput
     */
    public function validateSettings(array $input): array
    {
        $errors = [];
        $text = self::cleanMultiline(is_scalar($input['dynamic_text'] ?? null) ? (string) $input['dynamic_text'] : '', self::MAX_DYNAMIC_TEXT);
        $audience = is_scalar($input['external_audience'] ?? null) ? (string) $input['external_audience'] : 'none';
        if (!in_array($audience, self::AUDIENCES, true)) {
            $errors['external_audience'] = 'Unbekannter Empfängerkreis der Abwesenheitsnotiz.';
            $audience = 'none';
        }
        $mode = is_scalar($input['schedule_mode'] ?? null) ? (string) $input['schedule_mode'] : 'until_off';
        if (!in_array($mode, self::SCHEDULE_MODES, true)) {
            $errors['schedule_mode'] = 'Unbekannter Zeitraum der Abwesenheitsnotiz.';
            $mode = 'until_off';
        }
        $start = self::normalizeDate($input['start_date'] ?? null);
        $end = self::normalizeDate($input['end_date'] ?? null);
        $active = !empty($input['active']);
        if ($active && $mode === 'range') {
            if ($start === '') {
                $errors['start_date'] = 'Bitte den Beginn des Zeitraums angeben.';
            }
            if ($end === '') {
                $errors['end_date'] = 'Bitte das Ende des Zeitraums angeben.';
            }
            if ($start !== '' && $end !== '' && $end < $start) {
                $errors['end_date'] = 'Das Ende des Zeitraums darf nicht vor dem Beginn liegen.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'template_id' => (int) ($input['template_id'] ?? 0),
            'dynamic_text' => $text,
            'external_audience' => $audience,
            'schedule_mode' => $mode,
            'start_date' => $start,
            'end_date' => $end,
            'active' => $active,
        ];
    }

    /**
     * Abwesenheitsnotiz setzen oder abschalten: Text zusammensetzen, auf den
     * Exchange-Server uebertragen und die Eingaben lokal merken.
     *
     * @param OofTemplateRow $template
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $ssoUser
     * @return OofSettingsRow
     */
    public function apply(string $uid, string $mailbox, array $template, array $input, OrvantaExchangeService $exchange, ?array $ssoUser): array
    {
        $settings = $this->validateSettings($input);
        $settings['template_id'] = $template['id'];
        $settings['uid'] = $uid;
        if ($settings['active']) {
            $exchange->setOofSettings($mailbox, [
                'state' => $settings['schedule_mode'] === 'range' ? 'Scheduled' : 'Enabled',
                'external_audience' => $settings['external_audience'] === 'all' ? 'All' : 'None',
                'start' => self::dateStart($settings['start_date']),
                'end' => self::dateEnd($settings['end_date']),
                'reply' => $this->message($template, $settings['dynamic_text'], $ssoUser),
            ]);
        } else {
            $exchange->setOofSettings($mailbox, [
                'state' => 'Disabled',
                'external_audience' => $settings['external_audience'] === 'all' ? 'All' : 'None',
                'start' => 0,
                'end' => 0,
                'reply' => '',
            ]);
        }
        if ($uid !== '') {
            $this->repository->saveSettings($uid, $settings);
        }

        return $settings;
    }

    // ------------------------------------------------------------------ Zustand

    /**
     * Zustand der Abwesenheitsnotiz des Postfachs. Massgeblich ist der
     * Exchange-Server; im Demomodus ohne Server traegt die gespeicherte
     * Einstellung den Zustand.
     *
     * @return array{state:string,active:bool,scheduled:bool,external_audience:string,start:int,end:int}
     */
    public function state(string $mailbox, OrvantaExchangeService $exchange, ?array $settings): array
    {
        if ($this->config->isDemo()) {
            $active = $settings !== null && $settings['active'];
            $mode = $settings['schedule_mode'] ?? 'until_off';
            $start = self::dateStart((string) ($settings['start_date'] ?? ''));
            $end = self::dateEnd((string) ($settings['end_date'] ?? ''));
            if ($active && $mode === 'range') {
                $now = time();
                $active = ($start === 0 || $now >= $start) && ($end === 0 || $now <= $end);
            }

            return [
                'state' => $active ? ($mode === 'range' ? 'Scheduled' : 'Enabled') : 'Disabled',
                'active' => $active,
                'scheduled' => $active && $mode === 'range',
                'external_audience' => ($settings['external_audience'] ?? 'none') === 'all' ? 'All' : 'None',
                'start' => $start,
                'end' => $end,
            ];
        }
        $server = $exchange->oofSettings($mailbox);
        $state = $server['state'];

        return [
            'state' => $state,
            'active' => $state !== 'Disabled',
            'scheduled' => $state === 'Scheduled',
            'external_audience' => $server['external_audience'],
            'start' => $server['start'],
            'end' => $server['end'],
        ];
    }

    /**
     * Zustand und Text der Abwesenheitsnotiz fuer den Banner und den Dialog.
     *
     * @param array<string,mixed>|null $ssoUser
     * @return array<string,mixed>
     */
    public function status(string $uid, string $mailbox, OrvantaExchangeService $exchange, ?array $ssoUser): array
    {
        $template = $this->forUser($ssoUser);
        $settings = $this->settings($uid, $template);
        $state = $this->state($mailbox, $exchange, $settings);
        $signature = $this->signatures->forUser($ssoUser);

        return [
            'available' => $template !== null,
            'template' => $template === null ? null : ['id' => $template['id'], 'name' => $template['name']],
            'fixed_text' => $template['fixed_text'] ?? '',
            'dynamic_text' => $settings['dynamic_text'],
            'external_audience' => $settings['external_audience'],
            'schedule_mode' => $settings['schedule_mode'],
            'start_date' => $settings['start_date'],
            'end_date' => $settings['end_date'],
            'saved_active' => $settings['active'],
            'state' => $state['state'],
            'active' => $state['active'],
            'scheduled' => $state['scheduled'],
            'server_audience' => $state['external_audience'],
            'server_start' => $state['start'],
            'server_end' => $state['end'],
            'signature' => $signature === null ? '' : $signature['html'],
        ];
    }

    // ------------------------------------------------------------------ Text

    /**
     * Fester Text und dynamischer Text als Absaetze; der dynamische Text
     * entfaellt, wenn der Benutzer ihn geleert hat.
     *
     * @param OofTemplateRow $template
     */
    public function text(array $template, string $dynamicText): string
    {
        $text = trim($template['fixed_text']);
        $dynamic = trim($dynamicText);

        return $dynamic === '' ? $text : $text . "\n\n" . $dynamic;
    }

    /**
     * E-Mail-tauglicher Text der Abwesenheitsnotiz: fester Teil, dynamischer
     * Teil und die dem Benutzer zugewiesene Signatur. Alle Werte werden
     * escaped; Zeilenumbrueche werden zu <br>.
     *
     * @param OofTemplateRow $template
     * @param array<string,mixed>|null $ssoUser
     */
    public function message(array $template, string $dynamicText, ?array $ssoUser): string
    {
        $signature = $this->signatures->forUser($ssoUser);

        return $this->html($template, $dynamicText, $signature === null ? '' : $signature['html']);
    }

    /**
     * Wie message(), jedoch mit bereits gerendertem Signatur-HTML (Vorschau
     * im Adminbereich, das die Signatur des jeweiligen Benutzers nicht kennt).
     *
     * @param OofTemplateRow $template
     */
    public function html(array $template, string $dynamicText, string $signatureHtml): string
    {
        $lines = preg_split('/\R/u', $this->text($template, $dynamicText)) ?: [];
        $body = '<div style="font-family:' . self::FONT . ';font-size:10pt;">'
            . implode('<br>', array_map(static fn (string $line): string => Html::e($line), $lines))
            . '</div>';

        return $signatureHtml === '' ? $body : OrvantaSignatureService::append($body, $signatureHtml);
    }

    // ------------------------------------------------------------------ Hilfen

    /** Text mit Zeilenumbruechen: Steuerzeichen entfernen, Laenge begrenzen. */
    private static function cleanMultiline(string $value, int $max): string
    {
        $value = (string) preg_replace('/\r\n?/', "\n", $value);
        $value = (string) preg_replace('/[^\P{C}\n\t]/u', '', $value);
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /** Datum als Y-m-d; '' wenn kein gueltiges Datum. */
    private static function normalizeDate(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /** Beginn eines Tages (lokale Zeitzone) als Zeitstempel; 0 ohne Datum. */
    public static function dateStart(string $date): int
    {
        $time = $date === '' ? false : strtotime($date . ' 00:00:00');

        return $time === false ? 0 : $time;
    }

    /** Ende eines Tages (lokale Zeitzone) als Zeitstempel; 0 ohne Datum. */
    public static function dateEnd(string $date): int
    {
        $time = $date === '' ? false : strtotime($date . ' 23:59:59');

        return $time === false ? 0 : $time;
    }
}
