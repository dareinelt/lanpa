<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;

/** Rekonstruiert ausschließlich flüchtige Vorschauzustände, ohne Repository oder Versanddienst. */
final class EmergencyPlanPreview
{
    public static function build(array $input, array $alarms): array
    {
        if (!is_array($input['definition'] ?? null) || !is_array($input['operations'] ?? null)
            || !array_is_list($input['operations']) || count($input['operations']) > 200) {
            throw new ValidationException(['preview' => 'Ungültige Vorschau oder mehr als 200 Aktionen. Bitte Simulation zurücksetzen.']);
        }
        $definition = EmergencyPlanDefinition::validate($input['definition'], true);
        $startedAt = self::timestamp($input['startedAt'] ?? null);
        $options = array_column($alarms, null, 'id');
        foreach ($definition['nodes'] as &$node) {
            if ($node['type'] === 'sms' && $node['sms_mode'] === EmergencyPlanSms::MODE_NUMBERS) {
                $node['alarm'] = EmergencyPlanSms::alarm($node);
                if ($node['sms_numbers'] === []) {
                    $node['alarm']['alarm_group_number'] = 'Noch keine Rufnummer eingetragen.';
                }
                if ($node['sms_text'] === '') {
                    $node['alarm']['alarm_text'] = 'Noch kein SMS-Text eingetragen.';
                    unset($node['alarm']['placeholders']);
                }
            } elseif ($node['type'] === 'sms') {
                $alarm = $options[$node['alarm_id']] ?? null;
                $node['alarm'] = ['alarm_text' => $alarm['text'] ?? 'Noch keine aktive SMS-Vorlage ausgewählt.',
                    'alarm_group_number' => $alarm['target'] ?? '', 'alarm_group_description' => $alarm['title'] ?? 'Vorschau',
                    'alarm_group_type' => $alarm['mode'] ?? 'group'];
            }
        }
        unset($node);
        $definition = EmergencyPlanSms::resolve($definition, $startedAt);
        $event = ['id' => 0, 'revision' => 1, 'title' => $definition['title'], 'snapshot' => $definition,
            'actor' => 'Vorschau', 'state' => [], 'sms' => [], 'status' => 'active',
            'started_at' => $startedAt, 'closed_at' => null];
        $logs = [];
        foreach ($input['operations'] as $operation) {
            if (!is_array($operation)) {
                throw new ValidationException(['preview' => 'Ungültige Vorschauaktion.']);
            }
            foreach (['action', 'node', 'status', 'answer'] as $key) {
                EmergencyPlanDefinition::text($operation[$key] ?? '', 100, 'Vorschauaktion');
            }
            $at = self::timestamp($operation['at'] ?? null);
            $operation['revision'] = $event['revision'];
            $change = EmergencyPlanRuntime::change($event, 'Vorschau', $operation, $at);
            $event['state'] = $change['state'];
            $event['revision']++;
            if ($change['close']) {
                $event['status'] = 'closed';
                $event['closed_at'] = $at;
            }
            if ($change['sms']) {
                $event['sms'][$change['node']] = ['status' => 'success', 'message' => 'SIMULATION: SMS nicht versendet.'];
            }
            foreach (array_merge([$change], $change['checkChanges']) as $entry) {
                $logs[] = ['node_id' => $change['node'], 'actor' => 'Vorschau', 'created_at' => $at,
                    'message' => $change['sms'] ? 'SIMULATION: SMS bestätigt, kein Versand.' : $entry['message']];
            }
        }

        return ['event' => $event, 'logs' => $logs, 'notifications' => [],
            'ready' => EmergencyPlanDefinition::readiness($definition, $event['state']),
            'plan' => ['id' => 0, 'revision' => 0, 'title' => $definition['title'], 'definition' => $definition]];
    }

    private static function timestamp(mixed $value): string
    {
        if (!is_int($value) || $value < 0 || $value > 4102444800) {
            throw new ValidationException(['preview' => 'Ungültiger Vorschauzeitpunkt.']);
        }

        return gmdate('Y-m-d H:i:s', $value);
    }
}
