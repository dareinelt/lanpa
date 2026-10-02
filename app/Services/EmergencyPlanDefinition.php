<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;

final class EmergencyPlanDefinition
{
    public const TYPES = ['action', 'contact', 'decision', 'checklist', 'note', 'sms'];
    public const STATUSES = ['open', 'in_progress', 'blocked', 'done'];

    public static function validate(array $input): array
    {
        $title = self::text($input['title'] ?? '', 190, 'Titel', true);
        $description = self::text($input['description'] ?? '', 4000, 'Beschreibung');
        $nodes = $input['nodes'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) < 1 || count($nodes) > 80) {
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
            if (!is_array($checks) || !array_is_list($checks) || count($checks) > 20 || ($type === 'checklist' && $checks === [])) {
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
            $alarmId = filter_var($node['alarm_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($alarmId === false || $alarmId < 0 || ($type === 'sms' && $alarmId === 0)) {
                self::fail('Bitte eine SMS-Alarmvorlage auswählen.');
            }
            $result[] = [
                'id' => $id, 'type' => $type,
                'title' => self::text($node['title'] ?? '', 190, 'Elementtitel', true),
                'text' => self::text($node['text'] ?? '', 4000, 'Anweisung'),
                'owner' => self::text($node['owner'] ?? '', 190, 'Zuständigkeit'),
                'phone' => self::text($node['phone'] ?? '', 100, 'Telefon'),
                'link' => $link, 'minutes' => $minutes, 'checks' => $checks,
                'dependencies' => array_map(static fn ($edge) => ['id' => $edge['id'], 'when' => $edge['when']], $dependencies),
                'join' => $join, 'alarm_id' => $type === 'sms' ? $alarmId : 0,
            ];
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

    private static function fail(string $message): never
    {
        throw new ValidationException(['plan' => $message]);
    }
}
