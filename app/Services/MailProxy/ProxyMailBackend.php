<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

use App\Contracts\MailProxyTransportInterface;
use App\Contracts\OrvantaMailBackendInterface;
use App\Core\Logger;
use App\Repositories\MailProxyRepository;
use App\Services\Orvanta\MailHtmlSanitizer;
use App\Services\Orvanta\OrvantaAiService;
use App\Services\Orvanta\OrvantaException;

/**
 * Orvanta-Mail-Backend fuer Proxy-Postfaecher (IMAP lesen, SMTP senden).
 *
 * Die Protokollarbeit erledigt der Container mail-proxy; dieses Backend
 * uebersetzt nur zwischen den Orvanta-Formaten (wie OrvantaExchangeService)
 * und den Proxy-Operationen. Ein Backend ist fest an genau ein Postfach
 * gebunden: Kennungen eines anderen Postfachs und eine abweichende
 * Postfachadresse werden abgewiesen (kein Zugriff auf fremde Postfaecher).
 *
 * Kennungen:
 *  - Ordner:   mpx.f.<b64url("name:<IMAP-Name>")>; Systemordner zusaetzlich
 *              ueber kind (inbox, drafts, sentitems, deleteditems, junkemail)
 *  - Nachricht: mpx.<Postfach>.<b64url(Ordner)>.<UIDVALIDITY>.<UID>
 *  - Anhang:    <Nachricht>.<Index>
 * EWS-Kennungen enthalten nie einen Punkt, Verwechslungen sind ausgeschlossen.
 */
final class ProxyMailBackend implements OrvantaMailBackendInterface
{
    public const KINDS = ['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail'];
    private const MESSAGE_ID = '/^mpx\.(\d{1,10})\.([A-Za-z0-9_-]{1,1400})\.(\d{1,10})\.(\d{1,10})$/';
    private const ATTACHMENT_ID = '/^mpx\.(\d{1,10})\.([A-Za-z0-9_-]{1,1400})\.(\d{1,10})\.(\d{1,10})\.(\d{1,4})$/';
    private const MAX_IDS = 500;

    private ?MailProxyAccount $account = null;
    private bool $recorded = false;

    /**
     * @param \Closure(): MailProxyAccount $accountProvider liefert die
     *        entschluesselten Zugangsdaten erst bei der ersten Operation
     */
    public function __construct(
        private readonly MailProxyRoute $route,
        private readonly \Closure $accountProvider,
        private readonly MailProxyTransportInterface $transport,
        private readonly ?MailProxyRepository $repository = null,
        private readonly ?Logger $logger = null
    ) {
        if (!$route->isProxy()) {
            throw new \InvalidArgumentException('ProxyMailBackend benötigt eine Proxy-Zuordnung.');
        }
    }

    public function backendName(): string
    {
        return 'proxy';
    }

    public function capabilities(): array
    {
        return [
            self::CAPABILITY_MAIL => true,
            self::CAPABILITY_CALENDAR => false,
            self::CAPABILITY_CONTACTS => false,
            self::CAPABILITY_TASKS => false,
            self::CAPABILITY_NOTES => false,
            self::CAPABILITY_REMINDERS => false,
            self::CAPABILITY_ARCHIVE => false,
        ];
    }

    public function route(): MailProxyRoute
    {
        return $this->route;
    }

    /** Postfachadresse, unter der Orvanta den Benutzer anspricht. */
    public function email(): string
    {
        return $this->route->email;
    }

    public static function isProxyId(string $id): bool
    {
        return str_starts_with($id, 'mpx.');
    }

    /** Gehoert die Nachrichten-/Anhangkennung zum gebundenen Postfach? */
    public function ownsId(string $id): bool
    {
        return str_starts_with($id, 'mpx.' . $this->route->mailboxId . '.')
            && (preg_match(self::MESSAGE_ID, $id) === 1 || preg_match(self::ATTACHMENT_ID, $id) === 1);
    }

    // ------------------------------------------------------------------
    // Ordner
    // ------------------------------------------------------------------

    public function folders(string $user): array
    {
        $data = $this->call('imap.folders', $user, []);
        $list = [];
        foreach ((array) ($data['folders'] ?? []) as $folder) {
            if (!is_array($folder) || !empty($folder['noselect'])) {
                continue;
            }
            $raw = (string) ($folder['raw'] ?? '');
            if ($raw === '') {
                continue;
            }
            $kind = (string) ($folder['kind'] ?? 'folder');
            $parent = (string) ($folder['parent_raw'] ?? '');
            $list[] = [
                'id' => self::folderId($raw),
                'name' => (string) ($folder['name'] ?? $raw),
                'parent' => $parent !== '' ? self::folderId($parent) : '',
                'total' => max(0, (int) ($folder['total'] ?? 0)),
                'unread' => max(0, (int) ($folder['unread'] ?? 0)),
                'kind' => in_array($kind, self::KINDS, true) ? $kind : 'folder',
                'class' => 'IPF.Note',
            ];
        }
        usort($list, static function (array $a, array $b): int {
            $order = array_flip(self::KINDS);

            return ($order[$a['kind']] ?? 100) <=> ($order[$b['kind']] ?? 100) ?: strcasecmp($a['name'], $b['name']);
        });

        return $list;
    }

    public function createFolder(string $user, string $parent, string $name): array
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new OrvantaException('Bitte einen Ordnernamen angeben (höchstens 255 Zeichen).', 422);
        }
        $data = $this->call('imap.create_folder', $user, ['parent' => $parent !== '' ? self::folderSpec($parent) : '', 'name' => $name]);
        $raw = (string) ($data['raw'] ?? '');
        if ($raw === '') {
            throw new OrvantaException('Der Ordner konnte nicht angelegt werden.', 502);
        }

        return ['id' => self::folderId($raw), 'name' => $name];
    }

    public function markFolderRead(string $user, string $folder): void
    {
        $this->call('imap.mark_folder_read', $user, ['folder' => self::folderSpec($folder)]);
    }

    public function folderProperties(string $user, string $folder): array
    {
        $spec = self::folderSpec($folder);
        $data = $this->call('imap.folder_status', $user, ['folder' => $spec]);
        $raw = (string) ($data['raw'] ?? '');

        return [
            'id' => $raw !== '' ? self::folderId($raw) : $folder,
            'name' => (string) ($data['name'] ?? ''),
            'total' => max(0, (int) ($data['total'] ?? 0)),
            'unread' => max(0, (int) ($data['unread'] ?? 0)),
            'subfolders' => max(0, (int) ($data['subfolders'] ?? 0)),
            'size' => max(0, (int) ($data['size'] ?? 0)),
            'total_with_subfolders' => max(0, (int) ($data['total_with_subfolders'] ?? 0)),
            'size_with_subfolders' => max(0, (int) ($data['size_with_subfolders'] ?? 0)),
        ];
    }

    // ------------------------------------------------------------------
    // Nachrichten
    // ------------------------------------------------------------------

    public function messages(string $user, string $folder, int $offset = 0, int $limit = 50, string $search = ''): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $spec = self::folderSpec($folder);
        $data = $this->call('imap.messages', $user, [
            'folder' => $spec,
            'offset' => $offset,
            'limit' => $limit,
            'search' => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $search)), 0, 200),
        ]);
        $uidValidity = (int) ($data['uidvalidity'] ?? 0);
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = $this->summary($spec, $uidValidity, $item);
            }
        }
        $total = max(0, (int) ($data['total'] ?? count($items)));

        return [
            'items' => $items,
            'total' => $total,
            'offset' => $offset,
            'has_more' => $offset + count($items) < $total,
        ];
    }

    public function message(string $user, string $id): array
    {
        $ref = $this->messageRef($id);
        $data = $this->call('imap.message', $user, $ref);
        $message = $this->summary($ref['folder'], $ref['uidvalidity'], $data);
        $message['id'] = $id;
        $html = (string) ($data['html'] ?? '');
        if ($html !== '') {
            $clean = MailHtmlSanitizer::clean($html);
            $message['body_html'] = $clean['html'];
            $message['blocked_images'] = $clean['blocked_images'];
        } else {
            $message['body_html'] = nl2br(htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES, 'UTF-8'));
            $message['blocked_images'] = 0;
        }
        $message['cc'] = self::boxes($data['cc'] ?? []);
        $message['bcc'] = self::boxes($data['bcc'] ?? []);
        $message['reply_to'] = self::boxes($data['reply_to'] ?? []);
        $message['sender'] = self::box($data['sender'] ?? []);
        $message['internet_message_id'] = (string) ($data['message_id'] ?? '');
        $attachments = [];
        foreach ((array) ($data['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $attachments[] = [
                'id' => $id . '.' . (int) ($attachment['index'] ?? 0),
                'name' => (string) ($attachment['name'] ?? 'anhang'),
                'content_type' => (string) ($attachment['content_type'] ?? 'application/octet-stream'),
                'content_id' => trim((string) ($attachment['content_id'] ?? ''), '<>'),
                'size' => max(0, (int) ($attachment['size'] ?? 0)),
                'inline' => !empty($attachment['inline']),
                'is_item' => false,
            ];
        }
        $message['attachments'] = $attachments;
        $message['has_attachments'] = $message['has_attachments'] || $attachments !== [];

        return $message;
    }

    public function messageHeaders(string $user, string $id): array
    {
        $data = $this->call('imap.headers', $user, $this->messageRef($id));
        $headers = rtrim((string) ($data['headers'] ?? ''));
        if ($headers === '') {
            throw new OrvantaException('Für diese Nachricht liegen keine Kopfzeilen vor.', 404);
        }

        return ['id' => $id, 'subject' => (string) ($data['subject'] ?? ''), 'headers' => $headers, 'source' => 'mime'];
    }

    // ------------------------------------------------------------------
    // Senden und Entwuerfe
    // ------------------------------------------------------------------

    public function send(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        $reference = $this->reference($mail);
        $hasRecipients = ($mail['to'] ?? []) !== [] || ($mail['cc'] ?? []) !== [] || ($mail['bcc'] ?? []) !== [];
        if (!$hasRecipients) {
            throw new OrvantaException($reference !== null && $reference['mode'] === 'forward' ? 'Bitte einen Empfänger für die Weiterleitung angeben.' : 'Bitte mindestens einen Empfänger angeben.', 422);
        }
        $payload = ['message' => $this->outgoing($mail), 'sent_folder' => 'sentitems'];
        if ($reference !== null) {
            $payload['reference'] = $reference;
        }
        if ($draftId !== '') {
            $payload['draft'] = $this->messageRef($draftId);
        }
        $data = $this->call('smtp.send', $user, $payload);
        $this->logger?->info('mail-proxy message sent', [
            'mailbox_id' => $this->route->mailboxId,
            'recipients' => count($payload['message']['to']) + count($payload['message']['cc']) + count($payload['message']['bcc']),
            'mode' => $reference['mode'] ?? 'new',
        ]);

        return ['id' => $this->idFromResult('sentitems', $data)];
    }

    public function saveDraft(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        $payload = ['message' => $this->outgoing($mail)];
        $reference = $this->reference($mail);
        if ($reference !== null) {
            $payload['reference'] = $reference;
        }
        if ($draftId !== '') {
            $payload['draft'] = $this->messageRef($draftId);
        }
        $data = $this->call('imap.save_draft', $user, $payload);
        $id = $this->idFromResult('drafts', $data);
        if ($id === '') {
            throw new OrvantaException('Der Entwurf konnte nicht gespeichert werden.', 502);
        }

        return ['id' => $id, 'change_key' => ''];
    }

    public function respond(string $user, string $id, string $mode, array $mail): array
    {
        return $this->send($user, ['reference' => ['id' => $id, 'mode' => $mode]] + $mail);
    }

    // ------------------------------------------------------------------
    // Aktionen
    // ------------------------------------------------------------------

    public function markRead(string $user, array $ids, bool $read): void
    {
        foreach ($this->groups($ids) as $group) {
            $this->call('imap.flags', $user, $group + ['add' => $read ? ['\\Seen'] : [], 'remove' => $read ? [] : ['\\Seen']]);
        }
    }

    public function flag(string $user, array $ids, bool $flagged): void
    {
        foreach ($this->groups($ids) as $group) {
            $this->call('imap.flags', $user, $group + ['add' => $flagged ? ['\\Flagged'] : [], 'remove' => $flagged ? [] : ['\\Flagged']]);
        }
    }

    public function move(string $user, array $ids, string $folder): void
    {
        $target = self::folderSpec($folder);
        foreach ($this->groups($ids) as $group) {
            $this->call('imap.move', $user, $group + ['target' => $target]);
        }
    }

    public function delete(string $user, array $ids, bool $permanent = false): void
    {
        foreach ($this->groups($ids) as $group) {
            $this->call('imap.delete', $user, $group + ['permanent' => $permanent]);
        }
    }

    public function attachment(string $user, string $attachmentId): array
    {
        if (preg_match(self::ATTACHMENT_ID, $attachmentId, $match) !== 1) {
            throw new OrvantaException('Der Anhang wurde nicht gefunden.', 404);
        }
        $this->assertMailbox((int) $match[1]);
        $data = $this->call('imap.attachment', $user, [
            'folder' => self::decodeSpec($match[2]),
            'uidvalidity' => (int) $match[3],
            'uid' => (int) $match[4],
            'index' => (int) $match[5],
        ]);
        $content = base64_decode((string) ($data['content'] ?? ''), true);
        if ($content === false) {
            throw new OrvantaException('Der Anhang konnte nicht dekodiert werden.', 502);
        }
        $name = (string) ($data['name'] ?? '');
        $type = (string) ($data['content_type'] ?? '');

        return [
            'name' => $name !== '' ? $name : 'anhang',
            'content_type' => $type !== '' ? $type : 'application/octet-stream',
            'content' => $content,
            'size' => strlen($content),
        ];
    }

    public function mailboxUsage(string $user, ?array $directory = null): array
    {
        $data = $this->call('imap.quota', $user, []);
        $used = max(0, (int) ($data['used'] ?? 0));
        $limit = max(0, (int) ($data['limit'] ?? 0));
        $source = $limit > 0 ? 'imap' : '';
        $warning = 0;
        $receive = 0;
        if ($limit === 0 && $directory !== null) {
            $warning = max(0, $directory['warning']);
            $receive = max(0, $directory['receive']);
            $limit = max(0, $directory['send']) ?: ($receive ?: $warning);
            $source = $limit > 0 ? 'directory' : '';
        }

        return [
            'used' => $used,
            'quota' => $limit,
            'warning' => $warning,
            'receive_limit' => $receive,
            'limit' => $limit,
            'percent' => $limit > 0 ? (int) min(100, round($used * 100 / $limit)) : 0,
            'source' => $source,
        ];
    }

    // ------------------------------------------------------------------
    // Kennungen
    // ------------------------------------------------------------------

    public static function folderId(string $raw): string
    {
        return 'mpx.f.' . self::encode('name:' . $raw);
    }

    /**
     * Ordnerangabe von Orvanta (Systemordner-Kennung oder mpx.f.-Kennung)
     * in die Proxy-Form ('inbox', … oder 'name:<IMAP-Name>').
     */
    public static function folderSpec(string $folder): string
    {
        if (in_array($folder, self::KINDS, true)) {
            return $folder;
        }
        if (str_starts_with($folder, 'mpx.f.') && preg_match('/^[A-Za-z0-9_-]{1,1400}$/', substr($folder, 6)) === 1) {
            return self::decodeSpec(substr($folder, 6));
        }

        throw new OrvantaException('Der Ordner wurde nicht gefunden.', 404);
    }

    private static function decodeSpec(string $encoded): string
    {
        $spec = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (!is_string($spec) || !mb_check_encoding($spec, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $spec) === 1) {
            throw new OrvantaException('Der Ordner wurde nicht gefunden.', 404);
        }
        if (in_array($spec, self::KINDS, true)) {
            return $spec;
        }
        if (str_starts_with($spec, 'name:') && strlen($spec) > 5 && strlen($spec) <= 1000) {
            return $spec;
        }

        throw new OrvantaException('Der Ordner wurde nicht gefunden.', 404);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function messageIdFor(string $spec, int $uidValidity, int $uid): string
    {
        return 'mpx.' . $this->route->mailboxId . '.' . self::encode($spec) . '.' . $uidValidity . '.' . $uid;
    }

    /**
     * @return array{folder:string,uidvalidity:int,uid:int}
     */
    private function messageRef(string $id): array
    {
        if (preg_match(self::MESSAGE_ID, $id, $match) !== 1) {
            throw new OrvantaException('Die Nachricht wurde nicht gefunden.', 404);
        }
        $this->assertMailbox((int) $match[1]);

        return ['folder' => self::decodeSpec($match[2]), 'uidvalidity' => (int) $match[3], 'uid' => (int) $match[4]];
    }

    private function assertMailbox(int $mailboxId): void
    {
        if ($mailboxId !== $this->route->mailboxId) {
            throw new OrvantaException('Dieses Element gehört nicht zu Ihrem Postfach.', 403);
        }
    }

    /**
     * Kennungen nach Ordner/UIDVALIDITY gruppieren (eine Proxy-Anfrage je Gruppe).
     *
     * @param list<string> $ids
     * @return list<array{folder:string,uidvalidity:int,uids:list<int>}>
     */
    private function groups(array $ids): array
    {
        if (count($ids) > self::MAX_IDS) {
            throw new OrvantaException('Zu viele Nachrichten auf einmal ausgewählt.', 422);
        }
        $groups = [];
        foreach ($ids as $id) {
            $ref = $this->messageRef((string) $id);
            $key = $ref['folder'] . "\n" . $ref['uidvalidity'];
            $groups[$key] ??= ['folder' => $ref['folder'], 'uidvalidity' => $ref['uidvalidity'], 'uids' => []];
            $groups[$key]['uids'][] = $ref['uid'];
        }

        return array_values($groups);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function idFromResult(string $folder, array $data): string
    {
        $uid = (int) ($data['uid'] ?? 0);
        $uidValidity = (int) ($data['uidvalidity'] ?? 0);

        return $uid > 0 && $uidValidity > 0 ? $this->messageIdFor((string) ($data['folder'] ?? $folder), $uidValidity, $uid) : '';
    }

    // ------------------------------------------------------------------
    // Abbildung
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function summary(string $spec, int $uidValidity, array $item): array
    {
        $uid = (int) ($item['uid'] ?? 0);
        $received = (int) ($item['received'] ?? 0);
        $sent = (int) ($item['date'] ?? 0);
        $importance = (string) ($item['importance'] ?? 'Normal');

        return [
            'id' => $this->messageIdFor($spec, $uidValidity, $uid),
            'change_key' => '',
            'subject' => (string) ($item['subject'] ?? ''),
            'preview' => (string) ($item['preview'] ?? ''),
            'from' => self::box($item['from'] ?? []),
            'to' => self::boxes($item['to'] ?? []),
            'received' => $received > 0 ? $received : $sent,
            'sent' => $sent,
            'is_read' => !empty($item['seen']),
            'has_attachments' => !empty($item['has_attachments']),
            'size' => max(0, (int) ($item['size'] ?? 0)),
            'importance' => in_array($importance, ['High', 'Normal', 'Low'], true) ? $importance : 'Normal',
            'flagged' => !empty($item['flagged']),
            'categories' => [],
            'item_class' => 'IPM.Note',
            'is_meeting_request' => false,
        ];
    }

    /**
     * @return array{name:string,email:string}
     */
    private static function box(mixed $box): array
    {
        return is_array($box)
            ? ['name' => (string) ($box['name'] ?? ''), 'email' => (string) ($box['email'] ?? '')]
            : ['name' => '', 'email' => ''];
    }

    /**
     * @return list<array{name:string,email:string}>
     */
    private static function boxes(mixed $boxes): array
    {
        $list = [];
        foreach (is_array($boxes) ? $boxes : [] as $box) {
            $entry = self::box($box);
            if ($entry['email'] !== '' || $entry['name'] !== '') {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /**
     * @param array<string,mixed> $mail
     * @return array{folder:string,uidvalidity:int,uid:int,mode:string}|null
     */
    private function reference(array $mail): ?array
    {
        $reference = $mail['reference'] ?? null;
        if (!is_array($reference) || trim((string) ($reference['id'] ?? '')) === '') {
            return null;
        }
        $mode = (string) ($reference['mode'] ?? 'reply');
        if (!in_array($mode, ['reply', 'replyall', 'forward'], true)) {
            $mode = 'reply';
        }

        return $this->messageRef((string) $reference['id']) + ['mode' => $mode];
    }

    /**
     * Ausgehende Nachricht fuer den Proxy. Absender ist immer das gebundene
     * Postfach (vom Proxy erzwungen), der Text wird wie bei EWS bereinigt.
     *
     * @param array<string,mixed> $mail
     * @return array{to:list<string>,cc:list<string>,bcc:list<string>,subject:string,body:string,html:bool,importance:string,attachments:list<array{name:string,content_type:string,content:string}>}
     */
    private function outgoing(array $mail): array
    {
        $html = (bool) ($mail['html'] ?? true);
        $body = OrvantaAiService::stripMarkers((string) ($mail['body'] ?? ''));
        $attachments = [];
        foreach ((array) ($mail['attachments'] ?? []) as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $attachments[] = [
                'name' => (string) ($attachment['name'] ?? 'anhang'),
                'content_type' => (string) ($attachment['content_type'] ?? 'application/octet-stream'),
                'content' => base64_encode((string) ($attachment['content'] ?? '')),
            ];
        }
        $importance = (string) ($mail['importance'] ?? 'Normal');
        $list = static fn (mixed $value): array => array_values(array_filter(array_map('strval', is_array($value) ? $value : []), static fn (string $a): bool => $a !== ''));

        return [
            'to' => $list($mail['to'] ?? []),
            'cc' => $list($mail['cc'] ?? []),
            'bcc' => $list($mail['bcc'] ?? []),
            'subject' => (string) ($mail['subject'] ?? ''),
            'body' => $html ? MailHtmlSanitizer::clean($body, false)['html'] : $body,
            'html' => $html,
            'importance' => in_array($importance, ['High', 'Normal', 'Low'], true) ? $importance : 'Normal',
            'attachments' => $attachments,
        ];
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function call(string $operation, string $user, array $payload): array
    {
        if (strcasecmp(trim($user), $this->route->email) !== 0) {
            throw new OrvantaException('Kein Zugriff auf dieses Postfach.', 403);
        }
        try {
            $this->account ??= ($this->accountProvider)();
            $payload['account'] = $this->account->payload();
            $result = $this->transport->request($operation, $payload);
        } catch (OrvantaException $exception) {
            $authFailed = $exception->reason() === OrvantaException::MAIL_AUTH;
            if ($exception->status() >= 500 || $authFailed || in_array($exception->status(), [403, 413], true)) {
                $this->logger?->warning('mail-proxy operation failed', [
                    'operation' => $operation,
                    'mailbox_id' => $this->route->mailboxId,
                    'identity_source' => $this->route->sourceId,
                    'status' => $exception->status(),
                    'error' => $exception->getMessage(),
                ]);
                if ($exception->status() >= 500 || $authFailed) {
                    $this->state(fn (MailProxyRepository $repository) => $repository->recordError($exception->getMessage()));
                }
            }
            throw $exception;
        } finally {
            unset($payload);
        }
        if (!$this->recorded) {
            $this->recorded = true;
            $this->state(fn (MailProxyRepository $repository) => $repository->recordSuccess());
        }

        return $result;
    }

    /**
     * @param \Closure(MailProxyRepository): void $write
     */
    private function state(\Closure $write): void
    {
        if ($this->repository === null) {
            return;
        }
        try {
            $write($this->repository);
        } catch (\Throwable) {
            // Diagnosezustand ist nachrangig.
        }
    }
}
