<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

/**
 * SLA-Berechnung: Fälligkeiten (optional nur Geschäftszeiten), Pausen, Zustandsbewertung.
 * Alle Zeitstempel sind UTC; Geschäftszeiten werden in der App-Zeitzone interpretiert.
 */
final class TicketSlaService
{
    public const STATE_NONE = 'none';
    public const STATE_OK = 'ok';
    public const STATE_WARNING = 'warning';
    public const STATE_BREACHED = 'breached';
    public const STATE_MET = 'met';

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $defaultWarningPercent = 75,
        private readonly int $defaultEscalationPercent = 90
    ) {}

    /**
     * Fälligkeiten für Reaktion und Lösung anhand einer SLA-Regel.
     * @param array<string,mixed> $sla
     * @return array{response_due_at:?string,resolution_due_at:?string}
     */
    public function dueDates(array $sla, \DateTimeImmutable $start): array
    {
        return [
            'response_due_at' => $this->format($this->addMinutes($sla, $start, (int) $sla['response_minutes'])),
            'resolution_due_at' => $this->format($this->addMinutes($sla, $start, (int) $sla['resolution_minutes'])),
        ];
    }

    /** Fälligkeit nach Pause verschieben: pausierte Minuten werden (kalendarisch) angehängt. */
    public function shiftDue(?string $dueAt, int $pausedMinutes): ?string
    {
        if ($dueAt === null || $pausedMinutes <= 0) {
            return $dueAt;
        }

        return $this->format($this->parse($dueAt)->modify("+{$pausedMinutes} minutes"));
    }

    /**
     * Fälligkeit um pausierte Dienstminuten verschieben.
     * Bei Regeln mit Geschäftszeiten wandert die Fälligkeit nur über Dienstzeitfenster.
     * @param array<string,mixed>|null $sla
     */
    public function shiftDueByRule(?string $dueAt, int $pausedMinutes, ?array $sla): ?string
    {
        if ($dueAt === null || $pausedMinutes <= 0) {
            return $dueAt;
        }
        if ($sla === null) {
            return $this->shiftDue($dueAt, $pausedMinutes);
        }

        return $this->format($this->addMinutes($sla, $this->parse($dueAt), $pausedMinutes));
    }

    /** @param array<string,mixed> $sla */
    public function addMinutes(array $sla, \DateTimeImmutable $start, int $minutes): \DateTimeImmutable
    {
        if ($minutes <= 0) {
            return $start;
        }
        $window = $this->window($sla);
        if ($window === null) {
            return $start->modify("+{$minutes} minutes");
        }

        [$tz, $days, $startH, $startM, $endH, $endM] = $window;
        $cursor = $start->setTimezone($tz);
        $remaining = $minutes;
        for ($guard = 0; $guard < 3660; $guard++) {
            $dayStart = $cursor->setTime($startH, $startM);
            $dayEnd = $cursor->setTime($endH, $endM);
            if (!in_array((int) $cursor->format('N'), $days, true) || $cursor >= $dayEnd) {
                $cursor = $cursor->modify('+1 day')->setTime($startH, $startM);
                continue;
            }
            if ($cursor < $dayStart) {
                $cursor = $dayStart;
            }
            $available = (int) floor(($dayEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            if ($remaining <= $available) {
                return $cursor->modify("+{$remaining} minutes")->setTimezone(new \DateTimeZone('UTC'));
            }
            $remaining -= $available;
            $cursor = $cursor->modify('+1 day')->setTime($startH, $startM);
        }

        return $cursor->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Bewertet den Zustand eines Zeitziels.
     * Mit SLA-Regel zählen nur Dienstminuten, sonst die Wanduhr.
     * @param array<string,mixed>|null $sla
     * @return string STATE_*
     */
    public function evaluate(?string $dueAt, ?string $metAt, \DateTimeImmutable $now, ?string $startedAt, int $warningPercent, ?array $sla = null): string
    {
        if ($dueAt === null) {
            return self::STATE_NONE;
        }
        $due = $this->parse($dueAt);
        if ($metAt !== null) {
            return $this->parse($metAt) <= $due ? self::STATE_MET : self::STATE_BREACHED;
        }
        $start = $startedAt !== null ? $this->parse($startedAt) : null;
        $total = $start !== null ? $this->businessMinutesBetween($sla ?? [], $start, $due) : null;
        $elapsed = $start !== null ? $this->businessMinutesBetween($sla ?? [], $start, $now) : null;
        if ($now > $due && ($sla === null || $total === null || $total <= 0 || $elapsed === null || $elapsed >= $total)) {
            return self::STATE_BREACHED;
        }
        if ($total !== null && $elapsed !== null && $this->percent($elapsed, $total) >= $warningPercent) {
            return self::STATE_WARNING;
        }

        return self::STATE_OK;
    }

    /**
     * Anteil der verbrauchten Zeit in Prozent (0–100+), null ohne Fälligkeit.
     * @param array<string,mixed>|null $sla
     */
    public function consumedPercent(?string $startedAt, ?string $dueAt, \DateTimeImmutable $now, ?array $sla = null): ?int
    {
        return $this->progress($sla ?? [], $startedAt, $dueAt, $now);
    }

    /**
     * Fortschritt eines Zeitziels in Prozent (0–100+), geschäftszeitenbewusst.
     * @param array<string,mixed> $sla
     */
    public function progress(array $sla, ?string $startedAt, ?string $dueAt, \DateTimeImmutable $now): ?int
    {
        if ($startedAt === null || $dueAt === null) {
            return null;
        }
        $start = $this->parse($startedAt);

        return $this->percent(
            $this->businessMinutesBetween($sla, $start, $now),
            $this->businessMinutesBetween($sla, $start, $this->parse($dueAt))
        );
    }

    /** @param array<string,mixed> $sla */
    public function warningPercent(array $sla): int
    {
        return (int) ($sla['warning_percent'] ?? 0) > 0 ? (int) $sla['warning_percent'] : $this->defaultWarningPercent;
    }

    /** @param array<string,mixed> $sla */
    public function escalationPercent(array $sla): int
    {
        return (int) ($sla['escalation_percent'] ?? 0) > 0 ? (int) $sla['escalation_percent'] : $this->defaultEscalationPercent;
    }

    /**
     * Restzeit in Minuten (negativ = überfällig).
     * Mit Geschäftszeiten-Regel zählen nur Dienstminuten, sonst Kalenderminuten.
     * @param array<string,mixed>|null $sla
     */
    public function remainingMinutes(?string $dueAt, \DateTimeImmutable $now, ?array $sla = null): ?int
    {
        if ($dueAt === null) {
            return null;
        }
        $due = $this->parse($dueAt);
        if ($sla === null) {
            return (int) floor(($due->getTimestamp() - $now->getTimestamp()) / 60);
        }

        return $this->businessMinutesBetween($sla, $now, $due) - $this->businessMinutesBetween($sla, $due, $now);
    }

    /**
     * Menschlich lesbare Restzeit, z. B. „2 h 15 min“ oder „überfällig seit 40 min“.
     * $overdue erzwingt die Überfällig-Formulierung, auch wenn rechnerisch noch Restzeit besteht.
     */
    public static function humanRemaining(?int $minutes, bool $overdue = false): string
    {
        if ($minutes === null) {
            return '–';
        }
        $abs = abs($minutes);
        $parts = [];
        if ($abs >= 1440) {
            $parts[] = intdiv($abs, 1440) . ' T';
            $abs %= 1440;
        }
        if ($abs >= 60) {
            $parts[] = intdiv($abs, 60) . ' h';
            $abs %= 60;
        }
        if ($abs > 0 || $parts === []) {
            $parts[] = $abs . ' min';
        }
        $text = implode(' ', $parts);

        return $minutes < 0 || $overdue ? 'überfällig seit ' . $text : 'noch ' . $text;
    }

    /**
     * Verstrichene Dienstzeit in Minuten zwischen zwei Zeitpunkten.
     * Ohne Geschäftszeiten-Regel entspricht das der kalendarischen Differenz.
     * @param array<string,mixed> $sla
     */
    public function businessMinutesBetween(array $sla, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        $window = $this->window($sla);
        if ($window === null) {
            return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
        }

        [$tz, $days, $startH, $startM, $endH, $endM] = $window;
        $cursor = $from->setTimezone($tz);
        $end = $to->setTimezone($tz);
        $minutes = 0;
        for ($guard = 0; $guard < 3660 && $cursor < $end; $guard++) {
            $dayStart = $cursor->setTime($startH, $startM);
            $dayEnd = $cursor->setTime($endH, $endM);
            if (!in_array((int) $cursor->format('N'), $days, true) || $cursor >= $dayEnd) {
                $cursor = $cursor->modify('+1 day')->setTime($startH, $startM);
                continue;
            }
            if ($cursor < $dayStart) {
                $cursor = $dayStart;
            }
            $sliceEnd = $dayEnd < $end ? $dayEnd : $end;
            $minutes += (int) floor(($sliceEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            $cursor = $sliceEnd;
        }

        return $minutes;
    }

    /**
     * Daten für den Live-Fortschrittsbalken im Browser (Dienstminuten-Segmente).
     * @param array<string,mixed> $sla
     * @return array{elapsed:int,total:int,days:string,start:string,end:string,tz:string}|null
     */
    public function businessProgress(array $sla, ?string $startedAt, ?string $dueAt, \DateTimeImmutable $now): ?array
    {
        if ($startedAt === null || $dueAt === null || $this->window($sla) === null) {
            return null;
        }
        $start = $this->parse($startedAt);

        return [
            'elapsed' => $this->businessMinutesBetween($sla, $start, $now),
            'total' => $this->businessMinutesBetween($sla, $start, $this->parse($dueAt)),
            'days' => implode(',', $this->businessDays($sla)),
            'start' => sprintf('%02d:%02d', ...$this->time((string) ($sla['business_start'] ?? '08:00:00'))),
            'end' => sprintf('%02d:%02d', ...$this->time((string) ($sla['business_end'] ?? '17:00:00'))),
            'tz' => $this->timezone,
        ];
    }

    /**
     * SLA-Regel aus einer Ticketzeile (Join auf ticket_slas) rekonstruieren.
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function ruleFromRow(array $row): ?array
    {
        if (empty($row['sla_id']) || !array_key_exists('sla_business_hours_only', $row)) {
            return null;
        }

        return [
            'id' => (int) $row['sla_id'],
            'business_hours_only' => (int) $row['sla_business_hours_only'],
            'business_days' => (string) ($row['sla_business_days'] ?? '1,2,3,4,5'),
            'business_start' => (string) ($row['sla_business_start'] ?? '08:00:00'),
            'business_end' => (string) ($row['sla_business_end'] ?? '17:00:00'),
            'warning_percent' => (int) ($row['sla_warning_percent'] ?? 0),
            'escalation_percent' => (int) ($row['sla_escalation_percent'] ?? 0),
        ];
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    private function percent(int $elapsed, int $total): int
    {
        if ($total <= 0) {
            return $elapsed > 0 ? 100 : 0;
        }

        return max(0, (int) floor($elapsed * 100 / $total));
    }

    /**
     * Geschäftszeiten-Fenster einer Regel; null, wenn kalendarisch gerechnet wird.
     * @param array<string,mixed> $sla
     * @return array{0:\DateTimeZone,1:list<int>,2:int,3:int,4:int,5:int}|null
     */
    private function window(array $sla): ?array
    {
        if (empty($sla['business_hours_only'])) {
            return null;
        }
        $days = $this->businessDays($sla);
        [$startH, $startM] = $this->time((string) ($sla['business_start'] ?? '08:00:00'));
        [$endH, $endM] = $this->time((string) ($sla['business_end'] ?? '17:00:00'));
        if ($days === [] || ($endH * 60 + $endM) <= ($startH * 60 + $startM)) {
            return null;
        }

        return [new \DateTimeZone($this->timezone), $days, $startH, $startM, $endH, $endM];
    }

    /** @param array<string,mixed> $sla @return array<int,int> */
    private function businessDays(array $sla): array
    {
        $days = array_map('intval', array_filter(explode(',', (string) ($sla['business_days'] ?? '1,2,3,4,5')), 'is_numeric'));

        return array_values(array_filter($days, static fn (int $d): bool => $d >= 1 && $d <= 7));
    }

    /** @return array{0:int,1:int} */
    private function time(string $value): array
    {
        $parts = explode(':', $value);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    public function parse(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function format(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
