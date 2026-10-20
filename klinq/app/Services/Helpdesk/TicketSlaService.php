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

    /** @param array<string,mixed> $sla */
    public function addMinutes(array $sla, \DateTimeImmutable $start, int $minutes): \DateTimeImmutable
    {
        if ($minutes <= 0) {
            return $start;
        }
        if (empty($sla['business_hours_only'])) {
            return $start->modify("+{$minutes} minutes");
        }

        $tz = new \DateTimeZone($this->timezone);
        $days = $this->businessDays($sla);
        [$startH, $startM] = $this->time((string) ($sla['business_start'] ?? '08:00:00'));
        [$endH, $endM] = $this->time((string) ($sla['business_end'] ?? '17:00:00'));
        $dayMinutes = ($endH * 60 + $endM) - ($startH * 60 + $startM);
        if ($dayMinutes <= 0 || $days === []) {
            return $start->modify("+{$minutes} minutes");
        }

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
     * @return string STATE_*
     */
    public function evaluate(?string $dueAt, ?string $metAt, \DateTimeImmutable $now, ?string $startedAt, int $warningPercent): string
    {
        if ($dueAt === null) {
            return self::STATE_NONE;
        }
        $due = $this->parse($dueAt);
        if ($metAt !== null) {
            return $this->parse($metAt) <= $due ? self::STATE_MET : self::STATE_BREACHED;
        }
        if ($now > $due) {
            return self::STATE_BREACHED;
        }
        if ($startedAt !== null) {
            $start = $this->parse($startedAt);
            $total = max(1, $due->getTimestamp() - $start->getTimestamp());
            $elapsed = $now->getTimestamp() - $start->getTimestamp();
            if ($elapsed * 100 >= $total * $warningPercent) {
                return self::STATE_WARNING;
            }
        }

        return self::STATE_OK;
    }

    /** Anteil der verbrauchten Zeit in Prozent (0–100+), null ohne Fälligkeit. */
    public function consumedPercent(?string $startedAt, ?string $dueAt, \DateTimeImmutable $now): ?int
    {
        if ($dueAt === null || $startedAt === null) {
            return null;
        }
        $start = $this->parse($startedAt)->getTimestamp();
        $due = $this->parse($dueAt)->getTimestamp();
        $total = max(1, $due - $start);

        return (int) floor(($now->getTimestamp() - $start) * 100 / $total);
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

    /** Restzeit in Minuten (negativ = überfällig). */
    public function remainingMinutes(?string $dueAt, \DateTimeImmutable $now): ?int
    {
        if ($dueAt === null) {
            return null;
        }

        return (int) floor(($this->parse($dueAt)->getTimestamp() - $now->getTimestamp()) / 60);
    }

    /** Menschlich lesbare Restzeit, z. B. „2 h 15 min“ oder „überfällig seit 40 min“. */
    public static function humanRemaining(?int $minutes): string
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

        return $minutes < 0 ? 'überfällig seit ' . $text : 'noch ' . $text;
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
