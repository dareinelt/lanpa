<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bündelt Verlaufsereignisse eines Tickets.
 *
 * Handelt ein Benutzer mehrmals kurz hintereinander (Ticket ansehen, kommentieren, erneut
 * ansehen), entstehen im Verlauf viele Einzeleinträge mit identischem Zeitstempel. Diese
 * Klasse fasst aufeinanderfolgende Ereignisse desselben Benutzers innerhalb eines
 * Zeitfensters zu einer Gruppe zusammen. Die Ticketansicht zeigt eine Gruppe als einen
 * Eintrag und klappt die übrigen Ereignisse auf Wunsch auf
 * (siehe resources/views/helpdesk/tickets/show.php).
 */
final class TimelineGrouper
{
    /** Standard-Zeitfenster in Minuten; darin liegende Aktionen gelten als „kurz aufeinanderfolgend“. */
    public const DEFAULT_WINDOW_MINUTES = 5;

    /**
     * Fasst die Ereignisse zu Gruppen zusammen; die Reihenfolge der Ereignisse bleibt erhalten.
     *
     * Eine Gruppe enthält nur Ereignisse desselben Benutzers. Das Zeitfenster wird ab dem
     * ersten Ereignis der Gruppe gemessen, damit eine lange Kette einzelner Aktionen nicht
     * über die gesamte Historie zusammenwächst. Ereignisse ohne lesbaren Zeitstempel bleiben
     * jeweils für sich.
     *
     * @param list<array<string,mixed>> $events chronologisch aufsteigende Ereignisse
     * @return list<list<array<string,mixed>>> Gruppen, jeweils chronologisch aufsteigend
     */
    public static function group(array $events, int $windowMinutes = self::DEFAULT_WINDOW_MINUTES): array
    {
        $windowSeconds = max(0, $windowMinutes) * 60;
        $groups = [];
        $current = [];
        $currentActor = null;
        $currentStart = null;

        foreach ($events as $event) {
            $actor = self::actorKey($event);
            $time = self::timestamp($event);
            $continues = $current !== []
                && $actor === $currentActor
                && $time !== null
                && $currentStart !== null
                && $time - $currentStart <= $windowSeconds;

            if ($current !== [] && !$continues) {
                $groups[] = $current;
                $current = [];
            }
            if ($current === []) {
                $currentActor = $actor;
                $currentStart = $time;
            }
            $current[] = $event;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /** Schlüssel des Handelnden: das Benutzerkonto, sonst der hinterlegte Name. */
    private static function actorKey(array $event): string
    {
        $userId = $event['user_id'] ?? null;

        return $userId !== null ? 'user:' . (int) $userId : 'name:' . (string) ($event['user_name'] ?? '');
    }

    /** Zeitstempel als Unix-Zeit; null, wenn er fehlt oder nicht lesbar ist. */
    private static function timestamp(array $event): ?int
    {
        $createdAt = $event['created_at'] ?? null;
        if (!is_string($createdAt) || trim($createdAt) === '') {
            return null;
        }
        $time = strtotime($createdAt);

        return $time === false ? null : $time;
    }
}
