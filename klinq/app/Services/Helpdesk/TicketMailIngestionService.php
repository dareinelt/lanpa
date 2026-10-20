<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\DocumentService;
use App\Services\Helpdesk\Mail\InboundMail;
use App\Services\Helpdesk\Mail\MailboxClientInterface;
use App\Services\Helpdesk\Mail\MimeMessageParser;

/**
 * E-Mail-Eingang des Help Desks: holt Nachrichten aus dem Postfach (IMAP oder .eml-Verzeichnis),
 * ordnet Antworten per Threading einem bestehenden Ticket zu und legt sonst ein neues Ticket an.
 *
 * Zuordnungsreihenfolge einer eingehenden Mail:
 *  1. In-Reply-To/References → Message-ID einer eigenen Benachrichtigung („<ticket-{id}.…@domain>“)
 *     oder einer bereits verarbeiteten Eingangsmail (tickets/ticket_comments.mail_message_id)
 *  2. Ticketnummer im Betreff (z. B. „Re: [HD-2026-000012] Drucker“)
 *  3. sonst neues Ticket
 *
 * Schutzmechanismen: Duplikaterkennung über Message-ID, Erkennung automatischer Nachrichten
 * (Auto-Submitted, Precedence, Bounces), eigene Absenderadresse wird ignoriert, Anhänge werden über
 * den Dokumentendienst (Whitelist, Größenlimit) gespeichert.
 */
final class TicketMailIngestionService
{
    public const ACTION_CREATED = 'created';
    public const ACTION_COMMENT = 'comment';
    public const ACTION_IGNORED = 'ignored';
    public const ACTION_FAILED = 'failed';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly CurrentUser $currentUser,
        private readonly Permissions $permissions,
        private readonly TicketRepository $tickets,
        private readonly TicketRuleRepository $rules,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketAttachmentRepository $attachments,
        private readonly EmployeeRepository $employees,
        private readonly UserRepository $users,
        private readonly TicketService $ticketService,
        private readonly DocumentService $documents,
        private readonly MimeMessageParser $parser,
        private readonly MailboxSettingsService $settings,
        private readonly \Closure $mailboxFactory
    ) {}

    public function enabled(): bool
    {
        return $this->settings->enabled();
    }

    /**
     * Postfach abholen und alle neuen Nachrichten verarbeiten (Scheduler/CLI).
     * @return array{source:string,fetched:int,created:int,comments:int,ignored:int,failed:int,items:list<array{uid:string,action:string,ticket:?string,detail:?string}>}
     */
    public function pull(?int $limit = null): array
    {
        $limit ??= max(1, (int) $this->settings->get('batch_size', 50));
        $this->loginSystemUser();

        /** @var MailboxClientInterface $mailbox */
        $mailbox = ($this->mailboxFactory)();
        $result = ['source' => $mailbox->describe(), 'fetched' => 0, 'created' => 0, 'comments' => 0, 'ignored' => 0, 'failed' => 0, 'items' => []];
        try {
            $messages = $mailbox->fetchUnprocessed($limit);
            $result['fetched'] = count($messages);
            foreach ($messages as $uid => $raw) {
                $uid = (string) $uid;
                $outcome = $this->ingestRaw($raw, $uid);
                $result['items'][] = ['uid' => $uid, 'action' => $outcome['action'], 'ticket' => $outcome['ticket_number'], 'detail' => $outcome['detail']];
                match ($outcome['action']) {
                    self::ACTION_CREATED => $result['created']++,
                    self::ACTION_COMMENT => $result['comments']++,
                    self::ACTION_IGNORED => $result['ignored']++,
                    default => $result['failed']++,
                };
                try {
                    if ($outcome['action'] === self::ACTION_FAILED) {
                        $mailbox->markFailed($uid);
                    } else {
                        $mailbox->markProcessed($uid);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Mail-Eingang: Nachricht konnte nicht bestätigt werden', ['uid' => $uid, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            $mailbox->close();
        }
        $this->logger->info('Mail-Eingang verarbeitet', array_diff_key($result, ['items' => true]));
        $this->settings->recordRun(array_diff_key($result, ['items' => true]));

        return $result;
    }

    /**
     * Rohe RFC822-Nachricht verarbeiten (Parser + Ingest + Protokoll); wirft nie, sondern liefert „failed“.
     * @return array{action:string,ticket_id:?int,ticket_number:?string,comment_id:?int,detail:?string}
     */
    public function ingestRaw(string $raw, string $uid = ''): array
    {
        try {
            $mail = $this->parser->parse($raw)->withUid($uid);
        } catch (\Throwable $e) {
            $this->logger->error('Mail-Eingang: Nachricht nicht lesbar', ['uid' => $uid, 'error' => $e->getMessage()]);
            $this->safeLog(['message_id' => '<unparsable-' . sha1($raw) . '@generated.local>', 'mailbox_uid' => $uid, 'action' => self::ACTION_FAILED, 'matched_by' => 'none', 'detail' => 'Nicht lesbar: ' . $e->getMessage()]);

            return ['action' => self::ACTION_FAILED, 'ticket_id' => null, 'ticket_number' => null, 'comment_id' => null, 'detail' => $e->getMessage()];
        }

        return $this->ingest($mail);
    }

    /**
     * Bereits geparste Nachricht verarbeiten. Der Aufrufer muss angemeldet sein (pull() übernimmt das).
     * @return array{action:string,ticket_id:?int,ticket_number:?string,comment_id:?int,detail:?string}
     */
    public function ingest(InboundMail $mail): array
    {
        $log = [
            'message_id' => $mail->messageId,
            'mailbox_uid' => $mail->uid !== '' ? mb_substr($mail->uid, 0, 100) : null,
            'from_address' => $mail->fromAddress,
            'subject' => $mail->subject,
            'received_at' => $mail->date?->format('Y-m-d H:i:s'),
            'matched_by' => 'none',
        ];

        if ($this->rules->inboundMailExists($mail->messageId)) {
            return $this->finish($log, self::ACTION_IGNORED, null, null, 'Duplikat (Message-ID bereits verarbeitet)', false);
        }
        if ($mail->isAutomatic()) {
            return $this->finish($log, self::ACTION_IGNORED, null, null, 'Automatische Nachricht (Auto-Reply/Bounce/Liste)');
        }
        if ($this->isOwnAddress($mail->fromAddress)) {
            return $this->finish($log, self::ACTION_IGNORED, null, null, 'Absender ist die eigene Systemadresse');
        }

        try {
            [$ticket, $matchedBy] = $this->resolveTicket($mail);
            $log['matched_by'] = $matchedBy;
            $author = $this->resolveAuthor($mail, $ticket);

            if ($ticket !== null) {
                $comment = $this->ticketService->addInboundMailComment((int) $ticket['id'], $this->commentBody($mail), $author, $mail->messageId, $mail->fromAddress);
                $targetId = (int) ($comment['ticket_id'] ?? $ticket['id']);
                $this->storeAttachments($mail, $targetId, (int) ($comment['id'] ?? 0), $author);
                $target = $this->tickets->find($targetId) ?? $ticket;

                return $this->finish($log, self::ACTION_COMMENT, (int) $target['id'], (int) ($comment['id'] ?? 0), sprintf('Kommentar von %s (%s)', $author['email'], $matchedBy), true, (string) $target['number']);
            }

            if ($author['user_id'] === null && $author['employee_id'] === null && !(bool) $this->settings->get('allow_unknown_senders', true)) {
                return $this->finish($log, self::ACTION_IGNORED, null, null, 'Unbekannter Absender (allow_unknown_senders = false)');
            }

            $created = $this->ticketService->create($this->ticketInput($mail, $author), 'email');
            $this->storeAttachments($mail, (int) $created['id'], null, $author);

            return $this->finish($log, self::ACTION_CREATED, (int) $created['id'], null, sprintf('Neues Ticket von %s', $author['email']), true, (string) $created['number']);
        } catch (ValidationException $e) {
            $detail = implode('; ', array_map(static fn ($m): string => is_array($m) ? implode(', ', $m) : (string) $m, $e->errors()));
            $this->logger->warning('Mail-Eingang: Validierung fehlgeschlagen', ['message_id' => $mail->messageId, 'errors' => $detail]);

            return $this->finish($log, self::ACTION_FAILED, null, null, 'Validierung: ' . $detail);
        } catch (\Throwable $e) {
            $this->logger->error('Mail-Eingang: Verarbeitung fehlgeschlagen', ['message_id' => $mail->messageId, 'error' => $e->getMessage()]);

            return $this->finish($log, self::ACTION_FAILED, null, null, get_class($e) . ': ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ Zuordnung

    /**
     * @return array{0:?array<string,mixed>,1:string} [Ticket oder null, matched_by]
     */
    private function resolveTicket(InboundMail $mail): array
    {
        foreach ($mail->referencedIds() as $ref) {
            $id = TicketNotificationService::ticketIdFromMessageId($ref)
                ?? $this->rules->ticketIdByMessageId($ref)
                ?? $this->tickets->idByMailMessageId($ref);
            if ($id !== null) {
                $ticket = $this->followMerge($this->tickets->find($id));
                if ($ticket !== null) {
                    return [$ticket, 'reference'];
                }
            }
        }
        $number = self::extractTicketNumber($mail->subject);
        if ($number !== null) {
            $ticket = $this->followMerge($this->tickets->findByNumber($number));
            if ($ticket !== null) {
                return [$ticket, 'subject'];
            }
        }

        return [null, 'none'];
    }

    /** @param array<string,mixed>|null $ticket @return array<string,mixed>|null */
    private function followMerge(?array $ticket): ?array
    {
        $guard = 0;
        while ($ticket !== null && !empty($ticket['merged_into_ticket_id']) && $guard++ < 10) {
            $ticket = $this->tickets->find((int) $ticket['merged_into_ticket_id']);
        }

        return $ticket;
    }

    /** Ticketnummer aus Betreff ermitteln (z. B. „Re: [HD-2026-000012] Drucker“). */
    public static function extractTicketNumber(string $subject): ?string
    {
        if (preg_match('/\b([A-Z0-9]{1,10}-\d{4}-\d{6,})\b/i', $subject, $m) === 1) {
            return TicketNumberService::normalize($m[1]);
        }

        return null;
    }

    /**
     * Absender einem Benutzer/Mitarbeiter zuordnen und Rolle im Ticket bestimmen.
     * @param array<string,mixed>|null $ticket
     * @return array{user_id:?int,employee_id:?int,name:string,email:string,is_requester:bool,is_agent:bool}
     */
    private function resolveAuthor(InboundMail $mail, ?array $ticket): array
    {
        $email = $mail->fromAddress;
        $user = $email !== '' ? $this->users->findActiveByEmail($email) : null;
        $employee = $email !== '' ? $this->employees->findByEmail($email) : null;
        if ($employee === null && $user !== null && !empty($user['employee_id'])) {
            $employee = $this->employees->find((int) $user['employee_id']);
        }
        $name = $mail->fromName;
        if ($user !== null) {
            $name = (string) ($user['display_name'] ?? $name);
        } elseif ($employee !== null) {
            $name = trim((string) ($employee['first_name'] ?? '') . ' ' . (string) ($employee['last_name'] ?? '')) ?: (string) ($employee['display_name'] ?? $name);
        }
        $isAgent = $user !== null && $this->permissions->roleHas((string) $user['role'], 'helpdesk.view');

        $isRequester = false;
        if ($ticket !== null) {
            $isRequester = ($user !== null && (int) ($ticket['requester_user_id'] ?? 0) === (int) $user['id'])
                || ($employee !== null && (int) ($ticket['requester_employee_id'] ?? 0) === (int) $employee['id'])
                || ($email !== '' && mb_strtolower((string) ($ticket['requester_email'] ?? '')) === $email);
            if (!$isRequester && !$isAgent) {
                // Unbekannte Dritte werden wie der Melder behandelt (öffentliche Rückmeldung)
                $isRequester = $user === null;
            }
        }

        return [
            'user_id' => $user !== null ? (int) $user['id'] : null,
            'employee_id' => $employee !== null ? (int) $employee['id'] : null,
            'name' => mb_substr($name !== '' ? $name : $email, 0, 150),
            'email' => $email,
            'is_requester' => $isRequester,
            'is_agent' => $isAgent && !$isRequester,
        ];
    }

    /** @param array{user_id:?int,employee_id:?int,name:string,email:string} $author @return array<string,mixed> */
    private function ticketInput(InboundMail $mail, array $author): array
    {
        $typeCode = (string) $this->settings->get('default_type', 'incident');
        $type = $this->masterData->typeByCode($typeCode) ?? $this->masterData->typeByCode('incident');
        if ($type === null) {
            throw ValidationException::single('ticket_type_id', 'Kein Tickettyp für den E-Mail-Eingang konfiguriert.');
        }
        $subject = $mail->subject !== '' ? preg_replace('/^\s*((re|aw|wg|fwd?|antw)\s*:\s*)+/iu', '', $mail->subject) ?? $mail->subject : '';
        $subject = trim($subject) !== '' ? mb_substr(trim($subject), 0, 255) : 'E-Mail ohne Betreff';
        $body = trim($mail->text);
        if ($body === '') {
            $body = $mail->attachments !== [] ? '(Nur Anhänge, kein Text)' : $subject;
        }
        $description = mb_substr($body, 0, 60000) . "\n\n— Eingegangen per E-Mail von " . ($author['name'] !== '' && $author['name'] !== $author['email'] ? $author['name'] . ' <' . $author['email'] . '>' : $author['email']);

        $input = [
            'ticket_type_id' => (int) $type['id'],
            'subject' => $subject,
            'description' => $description,
            'impact' => 2,
            'urgency' => 2,
            'requester_email' => $author['email'],
            'mail_message_id' => $mail->messageId,
            'mail_from_address' => $mail->fromAddress,
            'mail_from_name' => $mail->fromName,
        ];
        if ($author['employee_id'] !== null) {
            $input['requester_employee_id'] = $author['employee_id'];
        } elseif ($author['user_id'] !== null) {
            $input['requester_user_id'] = $author['user_id'];
        } else {
            $input['external_requester'] = true;
        }

        return $input;
    }

    private function commentBody(InboundMail $mail): string
    {
        $body = trim($mail->text);
        if ($body === '') {
            $body = $mail->attachments !== [] ? '(Nur Anhänge, kein Text)' : ($mail->subject !== '' ? $mail->subject : '(Leere Nachricht)');
        }

        return $body;
    }

    /** @param array{user_id:?int,name:string,email:string,is_agent:bool} $author */
    private function storeAttachments(InboundMail $mail, int $ticketId, ?int $commentId, array $author): void
    {
        foreach ($mail->attachments as $attachment) {
            $tmp = tempnam(sys_get_temp_dir(), 'hdm');
            if ($tmp === false || file_put_contents($tmp, $attachment['content']) === false) {
                $this->logger->warning('Mail-Eingang: Anhang konnte nicht zwischengespeichert werden', ['ticket' => $ticketId, 'file' => $attachment['filename']]);
                continue;
            }
            try {
                $documentId = $this->documents->store('ticket', $ticketId, 'attachment', [
                    'name' => $attachment['filename'],
                    'tmp_name' => $tmp,
                    'size' => strlen($attachment['content']),
                    'error' => UPLOAD_ERR_OK,
                ], 'Aus E-Mail von ' . $author['email']);
                $this->attachments->link($documentId, $ticketId, $commentId !== null && $commentId > 0 ? $commentId : null, false);
                $this->tickets->addEvent($ticketId, 'attachment_added', $author['user_id'], $author['name'], 'attachment', null, $attachment['filename'], ['document_id' => $documentId, 'internal' => false, 'via' => 'email']);
            } catch (ValidationException $e) {
                $this->logger->info('Mail-Eingang: Anhang abgelehnt', ['ticket' => $ticketId, 'file' => $attachment['filename'], 'errors' => $e->errors()]);
            } catch (\Throwable $e) {
                $this->logger->warning('Mail-Eingang: Anhang konnte nicht gespeichert werden', ['ticket' => $ticketId, 'file' => $attachment['filename'], 'error' => $e->getMessage()]);
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }
    }

    // ------------------------------------------------------------------ Hilfen

    /** Systembenutzer des Mail-Eingangs anmelden (muss helpdesk.create besitzen). */
    public function loginSystemUser(): void
    {
        $username = (string) $this->settings->get('system_user', 'admin');
        $user = $this->users->findByUsername($username);
        if ($user === null || empty($user['is_active'])) {
            throw new \RuntimeException(sprintf('Systembenutzer „%s“ für den E-Mail-Eingang fehlt oder ist inaktiv (Administration → E-Mail-Postfach).', $username));
        }
        if (!$this->permissions->roleHas((string) $user['role'], 'helpdesk.create')) {
            throw new \RuntimeException(sprintf('Systembenutzer „%s“ hat keine Berechtigung helpdesk.create (Rolle %s).', $username, (string) $user['role']));
        }
        $this->currentUser->login($user);
    }

    private function isOwnAddress(string $email): bool
    {
        if ($email === '') {
            return false;
        }
        $own = array_filter([
            mb_strtolower(trim((string) $this->config->get('mail.from_address', ''))),
            mb_strtolower(trim((string) $this->settings->get('username', ''))),
        ]);

        return in_array($email, $own, true);
    }

    /**
     * @param array<string,mixed> $log
     * @return array{action:string,ticket_id:?int,ticket_number:?string,comment_id:?int,detail:?string}
     */
    private function finish(array $log, string $action, ?int $ticketId, ?int $commentId, ?string $detail, bool $persist = true, ?string $ticketNumber = null): array
    {
        if ($persist) {
            $this->safeLog($log + ['action' => $action, 'ticket_id' => $ticketId, 'comment_id' => $commentId, 'detail' => $detail]);
        }

        return ['action' => $action, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber, 'comment_id' => $commentId, 'detail' => $detail];
    }

    /** @param array<string,mixed> $data */
    private function safeLog(array $data): void
    {
        try {
            $this->rules->logInboundMail($data);
        } catch (\Throwable $e) {
            $this->logger->warning('Mail-Eingang: Protokolleintrag fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }
}
