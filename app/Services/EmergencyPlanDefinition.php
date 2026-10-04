<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Support\Validator;

final class EmergencyPlanDefinition
{
    public const TYPES = ['action', 'contact', 'decision', 'checklist', 'note', 'sms'];
    public const STATUSES = ['open', 'in_progress', 'blocked', 'done'];
    public const MAX_COORDINATE = 100000;

    public static function validate(array $input, bool $preview = false): array
    {
        $title = self::text($input['title'] ?? '', 190, 'Titel', !$preview);
        $description = self::text($input['description'] ?? '', 4000, 'Beschreibung');
        $nodes = $input['nodes'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || (!$preview && count($nodes) < 1) || count($nodes) > 80) {
            self::fail('Ein Plan benötigt 1 bis 80 Elemente.');
        }
        $seen = [];
        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                self::fail('Ungültiges Element.');
            }
            $id = $node['id'] ?? '';
            $type = $node['type'] ?? '';
            if (!is_string($id) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id) !== 1 || isset($seen[$id])) {
                self::fail('Elementkennungen müssen eindeutig sein.');
            }
            if (!in_array($type, self::TYPES, true)) {
                self::fail('Unbekannter Elementtyp.');
            }
            $dependencies = $node['dependencies'] ?? [];
            if (!is_array($dependencies) || !array_is_list($dependencies) || count($dependencies) > 80) {
                self::fail('Ungültige Verbindungen.');
            }
            $used = [];
            foreach ($dependencies as $edge) {
                if (!is_array($edge) || !is_string($edge['id'] ?? null) || !isset($seen[$edge['id']]) || isset($used[$edge['id']])) {
                    self::fail('Verbindungen dürfen nur auf verschiedene, vorherige Elemente zeigen. Bitte Reihenfolge prüfen.');
                }
                $condition = $edge['when'] ?? '';
                if (!in_array($condition, ['always', 'yes', 'no'], true)
                    || ($condition !== 'always' && $seen[$edge['id']] !== 'decision')) {
                    self::fail('Ja/Nein-Verbindungen sind nur nach Entscheidungen möglich.');
                }
                $used[$edge['id']] = true;
            }
            $join = $node['join'] ?? 'all';
            if (!in_array($join, ['all', 'any'], true)) {
                self::fail('Ungültige Verknüpfung.');
            }
            $checks = $node['checks'] ?? [];
            if (!is_array($checks) || !array_is_list($checks) || count($checks) > 20 || (!$preview && $type === 'checklist' && $checks === [])) {
                self::fail('Checklisten benötigen 1 bis 20 Prüfpunkte.');
            }
            $checks = array_map(static fn ($item) => self::text($item, 300, 'Prüfpunkt', true), $checks);
            $link = self::text($node['link'] ?? '', 1000, 'Link');
            if ($link !== '' && (filter_var($link, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($link, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                self::fail('Links müssen vollständige HTTP- oder HTTPS-Adressen sein.');
            }
            $minutes = filter_var($node['minutes'] ?? 0, FILTER_VALIDATE_INT);
            if ($minutes === false || $minutes < 0 || $minutes > 10080) {
                self::fail('Zielzeit: 0 bis 10080 Minuten ab Ereignisstart.');
            }
            $smsMode = $type === 'sms' ? ($node['sms_mode'] ?? EmergencyPlanSms::MODE_TEMPLATE) : EmergencyPlanSms::MODE_TEMPLATE;
            if (!in_array($smsMode, [EmergencyPlanSms::MODE_TEMPLATE, EmergencyPlanSms::MODE_NUMBERS], true)) {
                self::fail('Ungültige SMS-Empfängerart.');
            }
            $numbersMode = $type === 'sms' && $smsMode === EmergencyPlanSms::MODE_NUMBERS;
            $alarmId = filter_var($node['alarm_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($alarmId === false || $alarmId < 0 || (!$preview && $type === 'sms' && !$numbersMode && $alarmId === 0)) {
                self::fail('Bitte eine SMS-Alarmvorlage auswählen.');
            }
            $nodeTitle = self::text($node['title'] ?? '', 190, 'Elementtitel', !$preview);
            $smsNumbers = [];
            $smsText = '';
            if ($numbersMode) {
                $smsNumbers = self::phoneNumbers($node['sms_numbers'] ?? [], $preview);
                $smsText = self::text($node['sms_text'] ?? '', EmergencyPlanSms::MAX_LENGTH, 'SMS-Text', !$preview);
                $length = EmergencyPlanSms::length($smsText, $title, $nodeTitle);
                if ($length > EmergencyPlanSms::MAX_LENGTH) {
                    self::fail('SMS-Text „' . $nodeTitle . '“: nach Einsetzen der Textbausteine ' . $length
                        . ' Zeichen, erlaubt sind höchstens ' . EmergencyPlanSms::MAX_LENGTH . '.');
                }
            }
            // Optionale, im Editor per Drag-and-Drop gesetzte Diagrammposition (reines Layout, ohne Einfluss auf den Ablauf).
            $layout = [];
            if (($node['x'] ?? null) !== null || ($node['y'] ?? null) !== null) {
                $x = filter_var($node['x'] ?? null, FILTER_VALIDATE_INT);
                $y = filter_var($node['y'] ?? null, FILTER_VALIDATE_INT);
                if ($x === false || $y === false || $x < 0 || $y < 0 || $x > self::MAX_COORDINATE || $y > self::MAX_COORDINATE) {
                    self::fail('Ungültige Position im Ablaufdiagramm.');
                }
                $layout = ['x' => $x, 'y' => $y];
            }
            $result[] = [
                'id' => $id, 'type' => $type,
                'title' => $nodeTitle,
                'text' => self::text($node['text'] ?? '', 4000, 'Anweisung'),
                'owner' => self::text($node['owner'] ?? '', 190, 'Zuständigkeit'),
                'phone' => self::text($node['phone'] ?? '', 100, 'Telefon'),
                'link' => $link, 'minutes' => $minutes, 'checks' => $checks,
                'dependencies' => array_map(static fn ($edge) => ['id' => $edge['id'], 'when' => $edge['when']], $dependencies),
                'join' => $join, 'alarm_id' => $type === 'sms' && !$numbersMode ? $alarmId : 0,
                'sms_mode' => $smsMode, 'sms_numbers' => $smsNumbers, 'sms_text' => $smsText,
                'attachments' => EmergencyPlanAttachments::validateList($node['attachments'] ?? null, $type),
            ] + $layout;
            $seen[$id] = $type;
        }

        return ['title' => $title, 'description' => $description, 'nodes' => $result];
    }

    /** Verbindungen bilden einen DAG in der sichtbaren Elementreihenfolge. */
    public static function readiness(array $definition, array $state): array
    {
        $ready = [];
        foreach ($definition['nodes'] as $node) {
            $outcomes = [];
            foreach ($node['dependencies'] as $edge) {
                $previous = $state[$edge['id']] ?? [];
                $outcomes[] = ($ready[$edge['id']] ?? '') === 'skipped' ? 'skip'
                    : (($previous['status'] ?? 'open') !== 'done' ? 'wait'
                        : ($edge['when'] === 'always' || ($previous['answer'] ?? '') === $edge['when'] ? 'yes' : 'skip'));
            }
            if ($outcomes === []) {
                $ready[$node['id']] = 'ready';
            } elseif ($node['join'] === 'any') {
                $ready[$node['id']] = in_array('yes', $outcomes, true) ? 'ready' : (in_array('wait', $outcomes, true) ? 'waiting' : 'skipped');
            } else {
                $ready[$node['id']] = in_array('skip', $outcomes, true) ? 'skipped' : (in_array('wait', $outcomes, true) ? 'waiting' : 'ready');
            }
        }

        return $ready;
    }

    public static function text(mixed $value, int $max, string $label, bool $required = false): string
    {
        if (!is_string($value) || mb_strlen($value) > $max || ($required && trim($value) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            self::fail($label . ': bitte gültigen Text mit höchstens ' . $max . ' Zeichen eingeben.');
        }

        return trim($value);
    }

    /** @return list<string> bereinigte, eindeutige Rufnummern */
    private static function phoneNumbers(mixed $numbers, bool $preview): array
    {
        if (!is_array($numbers) || !array_is_list($numbers)) {
            self::fail('Ungültige Rufnummern.');
        }
        $result = [];
        foreach ($numbers as $number) {
            $number = self::text($number, 64, 'Rufnummer');
            if ($number === '') {
                continue;
            }
            if (!Validator::isPhoneNumber($number) || preg_match('/[\t\r\n]/', $number) === 1) {
                self::fail('Ungültige Rufnummer „' . $number . '“. Erlaubt sind Ziffern, +, *, #, /, -, Leerzeichen und Klammern.');
            }
            $result[$number] = $number;
        }
        $result = array_values($result);
        if ((!$preview && $result === []) || count($result) > EmergencyPlanSms::MAX_NUMBERS) {
            self::fail('SMS an einzelne Rufnummern: bitte 1 bis ' . EmergencyPlanSms::MAX_NUMBERS . ' Rufnummern angeben.');
        }

        return $result;
    }

    private static function fail(string $message): never
    {
        throw new ValidationException(['plan' => $message]);
    }
}
