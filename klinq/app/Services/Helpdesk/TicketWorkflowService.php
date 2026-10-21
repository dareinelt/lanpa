<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

/**
 * Statusworkflow des Ticketsystems (rein fachlich, ohne Datenbankzugriff).
 * Statuscodes sind fest; Übergänge und benötigte Berechtigungen sind hier zentral definiert.
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

    /** Statuscodes der Pausierung („Pausieren“ mit Pausenende). */
    public const PAUSES = [self::WAITING_FEEDBACK, self::WAITING_THIRD_PARTY, self::WAITING_DELIVERY, self::WAITING_INTERNAL];

    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        self::NEW => [self::OPEN, self::IN_PROGRESS, self::CANCELLED],
        self::OPEN => [self::IN_PROGRESS, self::WAITING_FEEDBACK, self::WAITING_THIRD_PARTY, self::WAITING_DELIVERY, self::WAITING_INTERNAL, self::CANCELLED],
        self::IN_PROGRESS => [self::WAITING_FEEDBACK, self::WAITING_THIRD_PARTY, self::WAITING_DELIVERY, self::WAITING_INTERNAL, self::RESOLVED, self::CANCELLED],
        self::WAITING_FEEDBACK => [self::IN_PROGRESS, self::CANCELLED],
        self::WAITING_THIRD_PARTY => [self::IN_PROGRESS, self::CANCELLED],
        self::WAITING_DELIVERY => [self::IN_PROGRESS, self::CANCELLED],
        self::WAITING_INTERNAL => [self::IN_PROGRESS, self::CANCELLED],
        self::RESOLVED => [self::CLOSED, self::IN_PROGRESS],
        self::CLOSED => [self::IN_PROGRESS],
        self::CANCELLED => [],
    ];

    /** Zusätzliche Berechtigung je Zielstatus (neben helpdesk.update). @var array<string,string> */
    private const PERMISSIONS = [
        self::RESOLVED => 'helpdesk.close',
        self::CLOSED => 'helpdesk.close',
        self::CANCELLED => 'helpdesk.close',
    ];

    /** Statuscodes, in denen ein Ticket fachlich abgeschlossen ist. */
    public const FINAL = [self::CLOSED, self::CANCELLED];

    /** @return array<int,string> */
    public function allowedTransitions(string $fromCode): array
    {
        $allowed = self::TRANSITIONS[$fromCode] ?? [];
        // Aus einer Pausierung lässt sich direkt in einen anderen Pausengrund wechseln.
        if ($this->isPause($fromCode)) {
            foreach (self::PAUSES as $pause) {
                if ($pause !== $fromCode) {
                    $allowed[] = $pause;
                }
            }
        }

        return $allowed;
    }

    public function isPause(string $code): bool
    {
        return in_array($code, self::PAUSES, true);
    }

    /** Pausierungen benötigen ein Pausenende (Datum und Uhrzeit). */
    public function requiresPauseUntil(string $toCode): bool
    {
        return $this->isPause($toCode);
    }

    /** Nur bei „Warten auf Rückmeldung“ beendet eine Meldermitteilung die Pausierung automatisch. */
    public function resumesOnReporterReply(string $code): bool
    {
        return $code === self::WAITING_FEEDBACK;
    }

    public function canTransition(string $fromCode, string $toCode): bool
    {
        return in_array($toCode, $this->allowedTransitions($fromCode), true);
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

    /** Aus einem Zielstatus die passende Ereignisbezeichnung ableiten. */
    public function eventFor(string $fromCode, string $toCode): string
    {
        if ($this->isReopen($fromCode, $toCode)) {
            return 'reopened';
        }
        if ($this->isPause($toCode)) {
            return 'paused';
        }
        if ($this->isPause($fromCode) && $toCode === self::IN_PROGRESS) {
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
