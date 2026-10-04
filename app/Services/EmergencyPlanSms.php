<?php

declare(strict_types=1);

namespace App\Services;

/**
 * SMS an einzelne Rufnummern im Notfallplan: Textbausteine (Platzhalter) und Längenregeln.
 * Platzhalter werden beim Start eines Ereignisses einmalig in den Snapshot eingesetzt.
 */
final class EmergencyPlanSms
{
    public const MODE_TEMPLATE = 'template';
    public const MODE_NUMBERS = 'numbers';
    public const MAX_LENGTH = 255;
    public const MAX_NUMBERS = 20;
    public const DESCRIPTION = 'Einzelne Rufnummern';

    /** Platzhalter → Bezeichnung im Editor (Reihenfolge = Reihenfolge der Schaltflächen). */
    public const PLACEHOLDERS = [
        '{Notfallplan}' => 'Name des Notfallplans',
        '{Datum}' => 'Ausgelöst am (Datum)',
        '{Uhrzeit}' => 'Ausgelöst um (Uhrzeit)',
        '{Schritt}' => 'Titel des Schritts',
    ];

    /** Setzt die Textbausteine ein; Datum/Uhrzeit in der Zeitzone der Anwendung. */
    public static function render(string $text, string $planTitle, string $stepTitle, int $timestamp): string
    {
        return strtr($text, [
            '{Notfallplan}' => $planTitle,
            '{Datum}' => date('d.m.Y', $timestamp),
            '{Uhrzeit}' => date('H:i', $timestamp),
            '{Schritt}' => $stepTitle,
        ]);
    }

    /** Länge der fertigen SMS; Datum (10) und Uhrzeit (5 Zeichen) haben feste Längen. */
    public static function length(string $text, string $planTitle, string $stepTitle): int
    {
        return mb_strlen(strtr($text, [
            '{Notfallplan}' => $planTitle,
            '{Datum}' => '00.00.0000',
            '{Uhrzeit}' => '00:00',
            '{Schritt}' => $stepTitle,
        ]));
    }

    /** Eingebettete Versanddaten eines SMS-Elements mit einzelnen Rufnummern. */
    public static function alarm(array $node): array
    {
        return [
            // alarm_log.title ist auf 120 Zeichen begrenzt.
            'title' => mb_substr($node['title'], 0, 120),
            'alarm_text' => $node['sms_text'],
            'alarm_group_number' => implode(', ', $node['sms_numbers']),
            'alarm_group_description' => self::DESCRIPTION,
            'alarm_group_type' => 'number',
            'numbers' => $node['sms_numbers'],
            'placeholders' => true,
        ];
    }

    /** Ersetzt im Ereignis-Snapshot die Textbausteine aller SMS-Elemente mit einzelnen Rufnummern. */
    public static function resolve(array $definition, string $startedAtUtc): array
    {
        $timestamp = (int) strtotime($startedAtUtc . ' UTC');
        foreach ($definition['nodes'] as &$node) {
            if (($node['type'] ?? '') === 'sms' && !empty($node['alarm']['placeholders'])) {
                $node['alarm']['alarm_text'] = self::render((string) $node['alarm']['alarm_text'],
                    (string) ($definition['title'] ?? ''), (string) ($node['title'] ?? ''), $timestamp);
                unset($node['alarm']['placeholders']);
            }
        }
        unset($node);

        return $definition;
    }
}
