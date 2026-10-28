<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

/**
 * Statusworkflow des Ticketsystems (rein fachlich, ohne Datenbankzugriff).
 * Systemstatuscodes sind fest; Pausengründe kommen aus dem Statuskatalog (Kategorie „Wartend“)
 * und werden als Codeliste übergeben. Übergänge und Berechtigungen sind hier zentral definiert.
 */
final class TicketWorkflowService
{
    public const NEW = 'new';
    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const WAITING_FEEDBACK = 'waiting_feedback';
    public const WAITING_THIRD_PARTY = 'waiting_third_party';
    public const WAITING_DELIVERY = 'waiting_delivery';
    public const WAITING_INTERNAL = 'waiting_internal';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';

    /** Standard-Pausengründe (entsprechen dem Seeder; der Katalog kann sie ergänzen oder deaktivieren). */
    public const PAUSES = [self::WAITING_FEEDBACK, self::WAITING_THIRD_PARTY, self::WAITING_DELIVERY, self::WAITING_INTERNAL];

    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        self::NEW => [self::OPEN, self::IN_PROGRESS, self::CLOSED, self::CANCELLED],
        self::OPEN => [self::IN_PROGRESS, self::CLOSED, self::CANCELLED],
        self::IN_PROGRESS => [self::RESOLVED, self::CLOSED, self::CANCELLED],
        self::RESOLVED => [self::CLOSED, self::IN_PROGRESS],
        self::CLOSED => [self::IN_PROGRESS],
        self::CANCELLED => [],
    ];

    /** Ausgangsstatus, aus denen pausiert werden darf. */
    private const PAUSE_SOURCES = [self::OPEN, self::IN_PROGRESS];

    /** Übergänge aus einer Pausierung heraus (neben dem Wechsel des Pausengrunds). */
    private const PAUSE_TRANSITIONS = [self::IN_PROGRESS, self::CLOSED, self::CANCELLED];

    /** Zusätzliche Berechtigung je Zielstatus (neben helpdesk.update). @var array<string,string> */
    private const PERMISSIONS = [
        self::RESOLVED => 'helpdesk.close',
        self::CLOSED => 'helpdesk.close',
        self::CANCELLED => 'helpdesk.cancel',
    ];

    /** Statuscodes, in denen ein Ticket fachlich abgeschlossen ist. */
    public const FINAL = [self::CLOSED, self::CANCELLED];

    /**
     * @param array<int,string>|null $pauseCodes Pausengründe aus dem Statuskatalog; null = Standardgründe
     * @return array<int,string>
     */
    public function allowedTransitions(string $fromCode, ?array $pauseCodes = null): array
    {
        $pauseCodes ??= self::PAUSES;
        if (in_array($fromCode, self::PAUSE_SOURCES, true)) {
            return array_values(array_unique(array_merge(self::TRANSITIONS[$fromCode] ?? [], $pauseCodes)));
        }
        if ($this->isPause($fromCode, $pauseCodes)) {
            // Aus einer Pausierung lässt sich direkt in einen anderen Pausengrund wechseln.
            return array_values(array_unique(array_merge(self::PAUSE_TRANSITIONS, array_values(array_diff($pauseCodes, [$fromCode])))));
        }

        return self::TRANSITIONS[$fromCode] ?? [];
    }

    /** @param array<int,string>|null $pauseCodes */
    public function isPause(string $code, ?array $pauseCodes = null): bool
    {
        return in_array($code, $pauseCodes ?? self::PAUSES, true);
    }

    /** Pausierungen benötigen ein Pausenende (Datum und Uhrzeit). @param array<int,string>|null $pauseCodes */
    public function requiresPauseUntil(string $toCode, ?array $pauseCodes = null): bool
    {
        return $this->isPause($toCode, $pauseCodes);
    }

    /** Nur bei „Warten auf Rückmeldung“ beendet eine Meldermitteilung die Pausierung automatisch. */
    public function resumesOnReporterReply(string $code): bool
    {
        return $code === self::WAITING_FEEDBACK;
    }

    /** @param array<int,string>|null $pauseCodes */
    public function canTransition(string $fromCode, string $toCode, ?array $pauseCodes = null): bool
    {
        return in_array($toCode, $this->allowedTransitions($fromCode, $pauseCodes), true);
    }

    public function isReopen(string $fromCode, string $toCode): bool
    {
        return in_array($fromCode, [self::RESOLVED, self::CLOSED], true) && $toCode === self::IN_PROGRESS;
    }

    public function isFinal(string $code): bool
    {
        return in_array($code, self::FINAL, true);
    }

    public function isResolvedOrFinal(string $code): bool
    {
        return $code === self::RESOLVED || $this->isFinal($code);
    }

    /** Benötigte Berechtigung für einen Übergang. */
    public function requiredPermission(string $fromCode, string $toCode): string
    {
        if ($this->isReopen($fromCode, $toCode)) {
            return 'helpdesk.reopen';
        }

        return self::PERMISSIONS[$toCode] ?? 'helpdesk.update';
    }

    /** Übergänge, die der Bearbeitende zusätzlich begründen muss (Lösungstext oder Grund). */
    public function requiresResolution(string $toCode): bool
    {
        return $toCode === self::RESOLVED;
    }

    public function requiresReason(string $toCode): bool
    {
        return $toCode === self::CANCELLED;
    }

    /** Aus einem Zielstatus die passende Ereignisbezeichnung ableiten. @param array<int,string>|null $pauseCodes */
    public function eventFor(string $fromCode, string $toCode, ?array $pauseCodes = null): string
    {
        if ($this->isReopen($fromCode, $toCode)) {
            return 'reopened';
        }
        if ($this->isPause($toCode, $pauseCodes)) {
            return 'paused';
        }
        if ($this->isPause($fromCode, $pauseCodes) && $toCode === self::IN_PROGRESS) {
            return 'resumed';
        }

        return match ($toCode) {
            self::RESOLVED => 'resolved',
            self::CLOSED => 'closed',
            self::CANCELLED => 'cancelled',
            default => 'status_changed',
        };
    }
}
