<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/** Gemeinsame, nebenwirkungsfreie Zustandsübergänge für Einsatz und Vorschau. */
final class EmergencyPlanRuntime
{
    public static function change(array $event, string $actor, array $input, ?string $at = null): array
    {
        if ($event['status'] !== 'active' || (int) ($input['revision'] ?? 0) !== (int) $event['revision']) {
            throw new HttpException(409, 'Stand veraltet oder Ereignis abgeschlossen. Bitte aktualisieren.');
        }
        $nodeId = (string) ($input['node'] ?? '');
        $action = (string) ($input['action'] ?? '');
        $comment = EmergencyPlanDefinition::text($input['comment'] ?? '', 2000, 'Kommentar');
        $change = ['state' => $event['state'], 'node' => $nodeId, 'action' => $action, 'message' => $comment,
            'close' => false, 'sms' => false, 'checkChanges' => []];
        if ($action === 'close') {
            $ready = EmergencyPlanDefinition::readiness($event['snapshot'], $event['state']);
            foreach ($ready as $id => $availability) {
                if ($availability !== 'skipped' && ($event['state'][$id]['status'] ?? 'open') !== 'done' && $comment === '') {
                    throw new ValidationException(['close' => 'Für den Abschluss mit offenen Maßnahmen ist eine Begründung erforderlich.']);
                }
            }
            foreach ($event['sms'] as $sms) {
                if ($sms['status'] === 'pending' && $comment === '') {
                    throw new ValidationException(['close' => 'Ungeklärter SMS-Versand: vor Abschluss prüfen und im Abschlusskommentar dokumentieren.']);
                }
            }

            return array_replace($change, ['node' => '', 'action' => 'closed', 'message' => $comment ?: 'Ereignis abgeschlossen.', 'close' => true]);
        }
        $node = null;
        foreach ($event['snapshot']['nodes'] as $candidate) {
            if ($candidate['id'] === $nodeId) {
                $node = $candidate;
            }
        }
        if ($node === null) {
            throw new HttpException(422, 'Maßnahme nicht gefunden.');
        }
        if ($action === 'comment') {
            if ($comment === '') {
                throw new ValidationException(['comment' => 'Bitte einen Kommentar eingeben.']);
            }

            return $change;
        }
        $ready = EmergencyPlanDefinition::readiness($event['snapshot'], $event['state']);
        if ($ready[$nodeId] !== 'ready') {
            throw new HttpException(409, 'Die Voraussetzungen dieser Maßnahme sind noch nicht erfüllt oder der Zweig entfällt.');
        }
        if ($action === 'sms') {
            if ($node['type'] !== 'sms' || isset($event['sms'][$nodeId]) || ($event['state'][$nodeId]['status'] ?? '') === 'done') {
                throw new HttpException(409, 'SMS wurde bereits angefordert oder ist hier nicht vorgesehen. Versandstatus prüfen; keine erneute Auslösung.');
            }

            return array_replace($change, ['action' => 'sms_requested', 'message' => 'SMS-Versand separat bestätigt.', 'sms' => true, 'alarm' => $node['alarm']]);
        }
        if ($action !== 'status' || !in_array($input['status'] ?? '', EmergencyPlanDefinition::STATUSES, true)) {
            throw new HttpException(422, 'Ungültige Statusänderung.');
        }
        $state = $event['state'];
        $old = $state[$nodeId] ?? ['status' => 'open'];
        if ($old['status'] === 'done') {
            throw new HttpException(409, 'Erledigte Maßnahmen bleiben unverändert. Korrekturen bitte als Kommentar dokumentieren.');
        }
        $new = ['status' => $input['status'], 'answer' => '', 'checks' => [], 'check_details' => []];
        $checkChanges = [];
        if ($node['type'] === 'decision' && $new['status'] === 'done') {
            if (!in_array($input['answer'] ?? '', ['yes', 'no'], true)) {
                throw new ValidationException(['answer' => 'Bitte die Entscheidung mit Ja oder Nein beantworten.']);
            }
            $new['answer'] = $input['answer'];
        }
        if ($node['type'] === 'checklist') {
            $checked = $input['checks'] ?? [];
            if (!is_array($checked) || count($checked) > count($node['checks'])) {
                throw new HttpException(422, 'Ungültige Prüfpunkte.');
            }
            foreach ($checked as $index) {
                if (!is_scalar($index) || !ctype_digit((string) $index) || !isset($node['checks'][(int) $index])) {
                    throw new HttpException(422, 'Ungültiger Prüfpunkt.');
                }
                $new['checks'][] = (int) $index;
            }
            $new['checks'] = array_values(array_unique($new['checks']));
            foreach ($node['checks'] as $index => $label) {
                $wasChecked = in_array($index, $old['checks'] ?? [], true);
                $isChecked = in_array($index, $new['checks'], true);
                if ($isChecked) {
                    $new['check_details'][$index] = $wasChecked && isset($old['check_details'][$index])
                        ? $old['check_details'][$index] : ['actor' => $actor, 'at' => $at ?? gmdate('Y-m-d H:i:s')];
                }
                if ($wasChecked !== $isChecked) {
                    $checkChanges[] = [
                        'action' => $isChecked ? 'check_done' : 'check_reopened',
                        'message' => 'Prüfpunkt ' . ($index + 1) . ': ' . $label . ' – ' . ($isChecked ? 'bestätigt' : 'Bestätigung zurückgenommen'),
                    ];
                }
            }
            if ($new['status'] === 'done' && count($new['checks']) !== count($node['checks'])) {
                throw new ValidationException(['checks' => 'Vor „Erledigt“ bitte alle Prüfpunkte bestätigen.']);
            }
        }
        if ($node['type'] === 'sms' && $new['status'] === 'done' && ($event['sms'][$nodeId]['status'] ?? '') !== 'success' && $comment === '') {
            throw new ValidationException(['sms' => 'Keine Gateway-Bestätigung: Ersatzalarmierung bitte im Kommentar dokumentieren.']);
        }
        $state[$nodeId] = $new;
        $labels = ['open' => 'Offen', 'in_progress' => 'In Arbeit', 'blocked' => 'Blockiert', 'done' => 'Erledigt'];
        $message = $labels[$old['status']] . ' → ' . $labels[$new['status']]
            . ($new['answer'] !== '' ? '; Entscheidung: ' . ($new['answer'] === 'yes' ? 'Ja' : 'Nein') : '')
            . ($node['type'] === 'checklist' ? '; Prüfpunkte: ' . implode(', ', array_map(static fn ($i) => $node['checks'][$i], $new['checks'])) : '')
            . ($comment !== '' ? '; ' . $comment : '');

        return array_replace($change, ['state' => $state, 'message' => $message, 'checkChanges' => $checkChanges]);
    }
}
