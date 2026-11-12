<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Repositories\HelpdeskMentionRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Security\CurrentUser;

/**
 * Erwähnungen von Agenten per „@Name“ in internen Ticketnotizen und Aufgabenkommentaren. Erkannt
 * werden Anzeigename und Benutzername aktiver Agenten (Groß-/Kleinschreibung egal, längster Treffer
 * gewinnt); jeder erwähnte Agent erhält je Kommentar genau eine Benachrichtigung auf dem
 * Help-Desk-Dashboard. Selbsterwähnungen und E-Mail-Adressen („max@firma.de“) lösen nichts aus.
 */
final class MentionService
{
    /** Länge des gespeicherten Textauszugs. */
    public const EXCERPT_CHARS = 200;

    public function __construct(
        private readonly HelpdeskMentionRepository $mentions,
        private readonly TicketMasterDataRepository $masterData,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * Ermittelt die IDs der im Text erwähnten Agenten. Ein „@“ zählt nur am Wortanfang; danach muss
     * der Anzeige- oder Benutzername eines Agenten stehen, gefolgt von Textende, Leer- oder Satzzeichen.
     *
     * @param list<array<string,mixed>> $agents Zeilen mit id, username, display_name
     * @return list<int>
     */
    public static function parse(string $body, array $agents): array
    {
        if ($agents === [] || !str_contains($body, '@')) {
            return [];
        }
        $candidates = [];
        foreach ($agents as $agent) {
            foreach ([(string) ($agent['display_name'] ?? ''), (string) ($agent['username'] ?? '')] as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $candidates[] = ['name' => mb_strtolower($name), 'length' => mb_strlen($name), 'id' => (int) $agent['id']];
                }
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $b['length'] <=> $a['length']);

        if (preg_match_all('/(?<![\p{L}\p{N}_.\-])@/u', $body, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $found = [];
        foreach ($matches[0] as [, $offset]) {
            $rest = mb_strtolower(substr($body, $offset + 1, 400));
            foreach ($candidates as $candidate) {
                if (mb_substr($rest, 0, $candidate['length']) !== $candidate['name']) {
                    continue;
                }
                $next = mb_substr($rest, $candidate['length'], 1);
                if ($next !== '' && preg_match('/[\p{L}\p{N}_]/u', $next) === 1) {
                    continue;
                }
                $found[$candidate['id']] = true;
                break;
            }
        }

        return array_keys($found);
    }

    /** Kurzer, einzeiliger Auszug ohne eingebettete Bilddaten für die Dashboard-Anzeige. */
    public static function excerpt(string $body): string
    {
        $text = (string) preg_replace('/!\[[^\]]*\]\(data:[^)]*\)|data:[a-z]+\/[a-z0-9.+-]+;base64,[A-Za-z0-9+\/=]+/i', '[Bild]', $body);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > self::EXCERPT_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::EXCERPT_CHARS - 1)) . '…';
        }

        return $text;
    }

    /**
     * Agenten für die Vorschlagsliste im Kommentarfeld (ohne den angemeldeten Benutzer).
     * @return list<array{id:int,name:string,username:string}>
     */
    public function suggestions(): array
    {
        $self = (int) $this->currentUser->id();
        $out = [];
        foreach ($this->agents() as $agent) {
            if ((int) $agent['id'] === $self) {
                continue;
            }
            $name = trim((string) ($agent['display_name'] ?? ''));
            $out[] = [
                'id' => (int) $agent['id'],
                'name' => $name !== '' ? $name : (string) $agent['username'],
                'username' => (string) $agent['username'],
            ];
        }

        return $out;
    }

    /** Benachrichtigt die in einer internen Ticketnotiz erwähnten Agenten. @return list<int> Empfänger */
    public function notifyTicketComment(int $ticketId, int $commentId, string $body): array
    {
        return $this->notify($ticketId, null, $commentId, $body);
    }

    /** Benachrichtigt die in einem Aufgabenkommentar erwähnten Agenten. @return list<int> Empfänger */
    public function notifyTaskComment(int $taskId, int $commentId, string $body): array
    {
        return $this->notify(null, $taskId, $commentId, $body);
    }

    /** @return list<array<string,mixed>> */
    public function unreadForCurrentUser(): array
    {
        $userId = (int) $this->currentUser->id();

        return $userId > 0 ? $this->mentions->unreadForUser($userId) : [];
    }

    public function markRead(int $mentionId): void
    {
        $this->mentions->markRead($mentionId, (int) $this->currentUser->id());
    }

    public function markAllRead(): void
    {
        $this->mentions->markAllRead((int) $this->currentUser->id());
    }

    /** Öffnet der erwähnte Agent das Ticket, gelten dessen Erwähnungen als gelesen. */
    public function markTicketRead(int $ticketId): void
    {
        $userId = (int) $this->currentUser->id();
        if ($userId > 0) {
            $this->mentions->markTicketRead($ticketId, $userId);
        }
    }

    /** Öffnet der erwähnte Agent die Aufgabe, gelten deren Erwähnungen als gelesen. */
    public function markTaskRead(int $taskId): void
    {
        $userId = (int) $this->currentUser->id();
        if ($userId > 0) {
            $this->mentions->markTaskRead($taskId, $userId);
        }
    }

    /** @return list<int> */
    private function notify(?int $ticketId, ?int $taskId, int $commentId, string $body): array
    {
        $self = (int) $this->currentUser->id();
        $recipients = array_values(array_filter(
            self::parse($body, $this->agents()),
            static fn (int $id): bool => $id !== $self
        ));
        if ($recipients === []) {
            return [];
        }
        $excerpt = mb_substr(self::excerpt($body), 0, 255);
        $byName = mb_substr($this->currentUser->displayName(), 0, 200);
        foreach ($recipients as $userId) {
            $this->mentions->create([
                'user_id' => $userId,
                'ticket_id' => $ticketId,
                'task_id' => $taskId,
                'comment_id' => $commentId,
                'mentioned_by' => $self > 0 ? $self : null,
                'mentioned_by_name' => $byName !== '' ? $byName : 'system',
                'excerpt' => $excerpt,
            ]);
        }

        return $recipients;
    }

    /** @return list<array<string,mixed>> */
    private function agents(): array
    {
        return array_values($this->masterData->agents(TicketService::AGENT_ROLES));
    }
}
