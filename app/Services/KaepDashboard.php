<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

final class KaepDashboard
{
    public static function change(array $event, array $input): array
    {
        if ($event['status'] !== 'active' || (int) ($input['revision'] ?? 0) !== (int) $event['revision']) {
            throw new HttpException(409, 'Stand geändert oder Ereignis abgeschlossen. Eingaben bleiben erhalten. Aktuellen Stand prüfen und bewusst erneut bearbeiten.');
        }
        $coordination = $event['coordination'] ?? [];
        $action = $input['action'] ?? '';
        $node = EmergencyPlanDefinition::text($input['node'] ?? '', 64, 'Element');
        if ($node !== '' && !in_array($node, array_column($event['snapshot']['nodes'], 'id'), true)) {
            throw new HttpException(422, 'Maßnahme nicht gefunden.');
        }
        $comment = EmergencyPlanDefinition::text($input['comment'] ?? '', 2000, 'Notiz');
        if (in_array($action, ['journal', 'handover'], true)) {
            if ($comment === '') {
                throw new ValidationException(['comment' => 'Bitte eine Notiz oder Schichtübergabe eingeben.']);
            }
            $message = ($action === 'handover' ? 'Schichtübergabe: ' : 'Notiz: ') . $comment;
        } elseif ($action === 'assignment') {
            if ($node === '') {
                throw new HttpException(422, 'Bitte eine Maßnahme auswählen.');
            }
            $owner = EmergencyPlanDefinition::text($input['owner'] ?? '', 190, 'Zuständigkeit');
            $priority = $input['priority'] ?? '';
            if (!in_array($priority, ['normal', 'high', 'critical'], true)) {
                throw new HttpException(422, 'Ungültige Priorität.');
            }
            $due = self::deadline($input['due'] ?? '');
            $old = $coordination['assignments'][$node] ?? [];
            $coordination['assignments'][$node] = compact('owner', 'priority', 'due');
            $message = 'Zuständigkeit: ' . ($old['owner'] ?? '(Planvorgabe)') . ' → ' . ($owner ?: '(Planvorgabe)')
                . '; Priorität: ' . ($old['priority'] ?? 'normal') . ' → ' . $priority
                . '; Zielzeit (UTC): ' . ($old['due'] ?? '(Planvorgabe)') . ' → ' . ($due ?: '(Planvorgabe)');
        } elseif ($action === 'leadership') {
            $node = '';
            $role = EmergencyPlanDefinition::text($input['role'] ?? '', 100, 'Leitungsbereich', true);
            $person = EmergencyPlanDefinition::text($input['person'] ?? '', 190, 'Leitung');
            $phone = EmergencyPlanDefinition::text($input['phone'] ?? '', 100, 'Erreichbarkeit');
            $old = $coordination['leadership'][$role] ?? [];
            if ($person === '') {
                unset($coordination['leadership'][$role]);
            } else {
                if (!isset($coordination['leadership'][$role]) && count($coordination['leadership'] ?? []) >= 30) {
                    throw new ValidationException(['role' => 'Höchstens 30 Leitungsbereiche pro Ereignis.']);
                }
                $coordination['leadership'][$role] = compact('person', 'phone');
            }
            $message = $role . ': ' . ($old['person'] ?? '(unbesetzt)') . ' → ' . ($person ?: '(unbesetzt)')
                . '; Erreichbarkeit: ' . ($old['phone'] ?? '') . ' → ' . $phone;
        } elseif ($action === 'situation') {
            $node = '';
            $situation = EmergencyPlanDefinition::text($input['situation'] ?? '', 4000, 'Lageübersicht', true);
            $briefing = self::deadline($input['briefing'] ?? '');
            $coordination['situation'] = $situation;
            $coordination['briefing'] = $briefing;
            $message = 'Lageübersicht: ' . $situation . '; nächste Lagebesprechung (UTC): ' . ($briefing ?: 'nicht festgelegt');
        } else {
            throw new HttpException(422, 'Unbekannte Dashboard-Aktion.');
        }
        if ($comment !== '' && !in_array($action, ['journal', 'handover'], true)) {
            $message .= '; ' . $comment;
        }

        return compact('coordination', 'node', 'action', 'message');
    }

    private static function deadline(mixed $value): string
    {
        $value = EmergencyPlanDefinition::text($value, 20, 'Zeitpunkt');
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
            throw new ValidationException(['due' => 'Bitte Datum und Uhrzeit (UTC) vollständig angeben.']);
        }

        return $date->format('Y-m-d H:i:s');
    }
}
