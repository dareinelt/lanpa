<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

final class KaepDashboard
{
    public const REMINDER_LIMIT = 50;

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
            $due = self::deadline($input['due'] ?? '', 'due');
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
            $until = self::deadline($input['until'] ?? '', 'until');
            $old = $coordination['leadership'][$role] ?? [];
            if ($person === '') {
                unset($coordination['leadership'][$role]);
            } else {
                if (!isset($coordination['leadership'][$role]) && count($coordination['leadership'] ?? []) >= 30) {
                    throw new ValidationException(['role' => 'Höchstens 30 Leitungsbereiche pro Ereignis.']);
                }
                $coordination['leadership'][$role] = compact('person', 'phone', 'until');
            }
            $message = $role . ': ' . ($old['person'] ?? '(unbesetzt)') . ' → ' . ($person ?: '(unbesetzt)')
                . '; Erreichbarkeit: ' . ($old['phone'] ?? '') . ' → ' . $phone
                . '; Ablösung geplant (UTC): ' . ($old['until'] ?? '') . ' → ' . ($until ?: 'offen');
        } elseif ($action === 'situation') {
            $node = '';
            $situation = EmergencyPlanDefinition::text($input['situation'] ?? '', 4000, 'Lageübersicht', true);
            $briefing = self::deadline($input['briefing'] ?? '', 'briefing');
            $coordination['situation'] = $situation;
            $coordination['briefing'] = $briefing;
            $message = 'Lageübersicht: ' . $situation . '; nächste Lagebesprechung (UTC): ' . ($briefing ?: 'nicht festgelegt');
        } elseif ($action === 'reminder') {
            $reminders = array_values($coordination['reminders'] ?? []);
            $key = EmergencyPlanDefinition::text($input['key'] ?? '', 32, 'Wiedervorlage');
            $index = null;
            foreach ($reminders as $i => $reminder) {
                if ($key !== '' && $reminder['key'] === $key) {
                    $index = $i;
                }
            }
            $mode = $input['mode'] ?? 'add';
            if ($mode === 'add') {
                $title = EmergencyPlanDefinition::text($input['title'] ?? '', 190, 'Wiedervorlage', true);
                $due = self::deadline($input['due'] ?? '', 'due');
                if ($due === '') {
                    throw new ValidationException(['due' => 'Bitte einen Zeitpunkt für die Wiedervorlage angeben.']);
                }
                if (count(array_filter($reminders, static fn (array $r) => empty($r['done']))) >= self::REMINDER_LIMIT) {
                    throw new ValidationException(['title' => 'Höchstens ' . self::REMINDER_LIMIT . ' offene Wiedervorlagen pro Einsatz. Bitte erledigte abhaken.']);
                }
                $key = bin2hex(random_bytes(6));
                $reminders[] = ['key' => $key, 'title' => $title, 'due' => $due, 'node' => $node, 'done' => false];
                $message = 'Wiedervorlage angelegt: ' . $title . ' – fällig (UTC) ' . $due;
            } elseif ($index === null) {
                throw new HttpException(422, 'Wiedervorlage nicht gefunden oder bereits entfernt.');
            } elseif ($mode === 'done') {
                if (!empty($reminders[$index]['done'])) {
                    throw new HttpException(409, 'Diese Wiedervorlage ist bereits erledigt.');
                }
                $reminders[$index]['done'] = true;
                $node = (string) ($reminders[$index]['node'] ?? '');
                $message = 'Wiedervorlage erledigt: ' . $reminders[$index]['title'];
            } elseif ($mode === 'remove') {
                $node = (string) ($reminders[$index]['node'] ?? '');
                $message = 'Wiedervorlage entfernt: ' . $reminders[$index]['title'] . ' (fällig (UTC) ' . $reminders[$index]['due'] . ')';
                array_splice($reminders, $index, 1);
            } else {
                throw new HttpException(422, 'Unbekannte Wiedervorlage-Aktion.');
            }
            $coordination['reminders'] = array_values($reminders);
        } else {
            throw new HttpException(422, 'Unbekannte Dashboard-Aktion.');
        }
        if ($comment !== '' && !in_array($action, ['journal', 'handover'], true)) {
            $message .= '; ' . $comment;
        }

        return compact('coordination', 'node', 'action', 'message');
    }

    /** Zeitpunkte werden vom Client bereits in UTC übertragen (Format Y-m-d\TH:i). */
    private static function deadline(mixed $value, string $field): string
    {
        $value = EmergencyPlanDefinition::text($value, 20, 'Zeitpunkt');
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
            throw new ValidationException([$field => 'Bitte Datum und Uhrzeit vollständig angeben.']);
        }

        return $date->format('Y-m-d H:i:s');
    }
}
