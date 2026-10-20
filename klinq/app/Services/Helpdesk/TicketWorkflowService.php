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
    public const WAITING_USER = 'waiting_user';
    public const WAITING_VENDOR = 'waiting_vendor';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';
    public const CANCELLED = 'cancelled';

    /** @var array<string,array<int,string>> */
    private const TRANSITIONS = [
        self::NEW => [self::OPEN, self::IN_PROGRESS, self::CANCELLED],
        self::OPEN => [self::IN_PROGRESS, self::WAITING_USER, self::CANCELLED],
        self::IN_PROGRESS => [self::WAITING_USER, self::WAITING_VENDOR, self::RESOLVED, self::CANCELLED],
        self::WAITING_USER => [self::IN_PROGRESS, self::CANCELLED],
        self::WAITING_VENDOR => [self::IN_PROGRESS, self::CANCELLED],
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
        return self::TRANSITIONS[$fromCode] ?? [];
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

        return match ($toCode) {
            self::RESOLVED => 'resolved',
            self::CLOSED => 'closed',
            self::CANCELLED => 'cancelled',
            default => 'status_changed',
        };
    }
}
