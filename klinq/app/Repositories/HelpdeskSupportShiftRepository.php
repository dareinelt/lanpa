<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Tagesweise Zuständigkeit für 1st-/2nd-Level-Support (Help-Desk-Dashboard „Zuständigkeit“).
 * Je Datum/Level ist höchstens ein Eintrag aktiv; frühere Einträge bleiben zu Auswertungszwecken erhalten.
 */
final class HelpdeskSupportShiftRepository extends BaseRepository
{
    private const SELECT = "SELECT s.*, u.display_name AS user_display_name
        FROM helpdesk_support_shifts s
        JOIN users u ON u.id = s.user_id";

    /** Aktiver Eintrag für Datum/Level (1 = 1st Level, 2 = 2nd Level). @return array<string,mixed>|null */
    public function active(string $shiftDate, int $level): ?array
    {
        return $this->fetchOne(
            self::SELECT . ' WHERE s.shift_date = :d AND s.level = :l AND s.is_active = 1 ORDER BY s.claimed_at DESC LIMIT 1',
            ['d' => $shiftDate, 'l' => $level]
        );
    }

    /** Aktive Einträge für ein Datum (Level 1 und 2). @return array<int,array<string,mixed>> */
    public function activeForDate(string $shiftDate): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE s.shift_date = :d AND s.is_active = 1 ORDER BY s.level', ['d' => $shiftDate]);
    }

    /**
     * Neuester 2nd-Level-Eintrag für ein Datum (unabhängig vom Aktivstatus). Die Zuständigkeit endet
     * zwar um 19:00 Uhr (is_active = 0), die Kommentar-Erinnerung gilt aber bis 23:59 Uhr – daher wird
     * hier bewusst nicht nach is_active gefiltert. @return array<string,mixed>|null
     */
    public function secondLevelEntryForDate(string $shiftDate): ?array
    {
        return $this->fetchOne(
            self::SELECT . ' WHERE s.shift_date = :d AND s.level = 2 ORDER BY s.claimed_at DESC, s.id DESC LIMIT 1',
            ['d' => $shiftDate]
        );
    }

    /** 2nd-Level-Einträge, deren Tagesabschluss-Erinnerung noch nicht verarbeitet wurde. @return array<int,array<string,mixed>> */
    public function unfinalizedSecondLevelShifts(): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE s.level = 2 AND s.reminder_finalized_at IS NULL ORDER BY s.shift_date, s.claimed_at, s.id');
    }

    /** Markiert alle 2nd-Level-Einträge eines Datums als erledigt; liefert die Anzahl betroffener Zeilen. */
    public function finalizeReminder(string $shiftDate, \DateTimeImmutable $nowUtc): int
    {
        return $this->execute(
            'UPDATE helpdesk_support_shifts SET reminder_finalized_at = :now WHERE shift_date = :d AND level = 2 AND reminder_finalized_at IS NULL',
            ['now' => $nowUtc->format('Y-m-d H:i:s'), 'd' => $shiftDate]
        );
    }

    /**
     * Übernimmt die Zuständigkeit: beendet einen ggf. aktiven Eintrag für Datum/Level und legt einen neuen an.
     * @return array<string,mixed> Der neue (aktive) Eintrag
     */
    public function claim(string $shiftDate, int $level, int $userId, \DateTimeImmutable $claimedAtUtc, \DateTimeImmutable $endsAtUtc): array
    {
        return $this->transaction(function () use ($shiftDate, $level, $userId, $claimedAtUtc, $endsAtUtc): array {
            $this->execute(
                'UPDATE helpdesk_support_shifts SET is_active = 0, ended_at = :now WHERE shift_date = :d AND level = :l AND is_active = 1',
                ['now' => $claimedAtUtc->format('Y-m-d H:i:s'), 'd' => $shiftDate, 'l' => $level]
            );
            $id = $this->insertRow('helpdesk_support_shifts', [
                'shift_date' => $shiftDate,
                'level' => $level,
                'user_id' => $userId,
                'claimed_at' => $claimedAtUtc->format('Y-m-d H:i:s'),
                'ends_at' => $endsAtUtc->format('Y-m-d H:i:s'),
                'is_active' => 1,
            ]);

            return $this->fetchOne(self::SELECT . ' WHERE s.id = :id', ['id' => $id]) ?? [];
        });
    }

    /** Beendet abgelaufene Zuständigkeiten (ends_at erreicht); liefert die Anzahl beendeter Einträge. */
    public function endExpired(\DateTimeImmutable $nowUtc): int
    {
        return $this->execute(
            'UPDATE helpdesk_support_shifts SET is_active = 0, ended_at = :now WHERE is_active = 1 AND ends_at <= :now',
            ['now' => $nowUtc->format('Y-m-d H:i:s')]
        );
    }
}
