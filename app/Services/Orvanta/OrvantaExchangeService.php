<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\ExchangeTransportInterface;
use DOMElement;
use DOMXPath;

/**
 * Schnittstelle zu Microsoft Exchange On-Premise (ab 2016/2019) ueber
 * Exchange Web Services (SOAP). Jede Operation arbeitet per Impersonation im
 * Postfach des angemeldeten SSO-Benutzers; der Transport authentifiziert sich
 * mit Negotiate/Kerberos, NTLM oder einem Dienstkonto.
 *
 * Alle Methoden liefern einfache PHP-Arrays fuer die JSON-API und werfen bei
 * Fehlern eine OrvantaException mit anzeigbarer Meldung.
 */
final class OrvantaExchangeService
{
    public const MAIL_FOLDERS = ['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'outbox'];

    private const MESSAGE_FIELDS = '<t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="item:DateTimeReceived"/><t:FieldURI FieldURI="item:DateTimeSent"/>'
        . '<t:FieldURI FieldURI="item:HasAttachments"/><t:FieldURI FieldURI="item:Size"/><t:FieldURI FieldURI="item:Importance"/><t:FieldURI FieldURI="item:ItemClass"/>'
        . '<t:FieldURI FieldURI="message:From"/><t:FieldURI FieldURI="message:IsRead"/><t:FieldURI FieldURI="message:ToRecipients"/><t:FieldURI FieldURI="item:Preview"/>'
        . '<t:FieldURI FieldURI="item:Categories"/><t:FieldURI FieldURI="item:Flag"/>';

    private const CALENDAR_FIELDS = '<t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="calendar:Start"/><t:FieldURI FieldURI="calendar:End"/>'
        . '<t:FieldURI FieldURI="calendar:IsAllDayEvent"/><t:FieldURI FieldURI="calendar:Location"/><t:FieldURI FieldURI="calendar:Organizer"/>'
        . '<t:FieldURI FieldURI="calendar:LegacyFreeBusyStatus"/><t:FieldURI FieldURI="calendar:CalendarItemType"/><t:FieldURI FieldURI="item:ReminderIsSet"/>'
        . '<t:FieldURI FieldURI="item:ReminderMinutesBeforeStart"/><t:FieldURI FieldURI="calendar:IsMeeting"/><t:FieldURI FieldURI="calendar:MyResponseType"/><t:FieldURI FieldURI="item:Categories"/>';

    public function __construct(
        private readonly ExchangeTransportInterface $transport,
        private readonly OrvantaConfigService $config
    ) {
    }

    // ------------------------------------------------------------------
    // Verbindung
    // ------------------------------------------------------------------

    /**
     * Prueft die Verbindung: Posteingang des Dienstkontos bzw. des Benutzers.
     *
     * @return array{ok:bool,message:string,server_version:string}
     */
    public function testConnection(string $impersonate = ''): array
    {
        $xpath = $this->call(
            '<m:GetFolder><m:FolderShape><t:BaseShape>Default</t:BaseShape></m:FolderShape><m:FolderIds><t:DistinguishedFolderId Id="inbox"/></m:FolderIds></m:GetFolder>',
            $impersonate
        );
        $name = EwsXml::text($xpath, '//t:Folder/t:DisplayName');
        $total = EwsXml::text($xpath, '//t:Folder/t:TotalCount');
        $version = EwsXml::attr($xpath, '//t:ServerVersionInfo', 'Version');
        if ($version === '') {
            $version = trim(EwsXml::attr($xpath, '//t:ServerVersionInfo', 'MajorVersion') . '.' . EwsXml::attr($xpath, '//t:ServerVersionInfo', 'MinorVersion'), '.');
        }

        return [
            'ok' => true,
            'message' => sprintf('Verbindung erfolgreich: Ordner „%s“ mit %s Elementen erreichbar.', $name !== '' ? $name : 'Posteingang', $total !== '' ? $total : '0'),
            'server_version' => $version,
        ];
    }

    // ------------------------------------------------------------------
    // Ordner
    // ------------------------------------------------------------------

    /**
     * Ordnerbaum des Postfachs (E-Mail-Ordner) mit bekannten Systemordnern.
     *
     * @return list<array{id:string,name:string,parent:string,total:int,unread:int,kind:string,class:string}>
     */
    public function folders(string $user): array
    {
        $known = [];
        $knownXml = '';
        foreach (self::MAIL_FOLDERS as $folder) {
            $knownXml .= '<t:DistinguishedFolderId Id="' . $folder . '"/>';
        }
        $xpath = $this->call('<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape></m:FolderShape><m:FolderIds>' . $knownXml . '</m:FolderIds></m:GetFolder>', $user, false);
        $responses = EwsXml::elements($xpath, '//m:GetFolderResponseMessage');
        foreach ($responses as $index => $response) {
            $id = EwsXml::attr($xpath, './/t:FolderId', 'Id', $response);
            if ($id !== '' && isset(self::MAIL_FOLDERS[$index])) {
                $known[$id] = self::MAIL_FOLDERS[$index];
            }
        }

        $xpath = $this->call(
            '<m:FindFolder Traversal="Deep"><m:FolderShape><t:BaseShape>Default</t:BaseShape><t:AdditionalProperties><t:FieldURI FieldURI="folder:FolderClass"/><t:FieldURI FieldURI="folder:ParentFolderId"/></t:AdditionalProperties></m:FolderShape>'
            . '<m:IndexedPageFolderView MaxEntriesReturned="500" Offset="0" BasePoint="Beginning"/>'
            . '<m:ParentFolderIds><t:DistinguishedFolderId Id="msgfolderroot"/></m:ParentFolderIds></m:FindFolder>',
            $user
        );
        $list = [];
        foreach (EwsXml::elements($xpath, '//t:Folders/t:Folder') as $folder) {
            $class = EwsXml::text($xpath, 't:FolderClass', $folder);
            if ($class !== '' && !str_starts_with($class, 'IPF.Note')) {
                continue;
            }
            $id = EwsXml::attr($xpath, 't:FolderId', 'Id', $folder);
            $list[] = [
                'id' => $id,
                'name' => EwsXml::text($xpath, 't:DisplayName', $folder),
                'parent' => EwsXml::attr($xpath, 't:ParentFolderId', 'Id', $folder),
                'total' => (int) EwsXml::text($xpath, 't:TotalCount', $folder),
                'unread' => (int) EwsXml::text($xpath, 't:UnreadCount', $folder),
                'kind' => $known[$id] ?? 'folder',
                'class' => $class,
            ];
        }
        usort($list, static function (array $a, array $b): int {
            $order = array_flip(self::MAIL_FOLDERS);
            $ra = $order[$a['kind']] ?? 100;
            $rb = $order[$b['kind']] ?? 100;

            return $ra <=> $rb ?: strcasecmp($a['name'], $b['name']);
        });

        return $list;
    }

    // ------------------------------------------------------------------
    // E-Mail
    // ------------------------------------------------------------------

    /**
     * Nachrichtenliste eines Ordners, neueste zuerst.
     *
     * @return array{items:list<array<string,mixed>>,total:int,offset:int,has_more:bool}
     */
    public function messages(string $user, string $folder, int $offset = 0, int $limit = 50, string $search = ''): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $query = $search !== '' ? '<m:QueryString>' . EwsXml::escape($search) . '</m:QueryString>' : '';
        $xpath = $this->call(
            '<m:FindItem Traversal="Shallow"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . self::MESSAGE_FIELDS . '</t:AdditionalProperties></m:ItemShape>'
            . '<m:IndexedPageItemView MaxEntriesReturned="' . $limit . '" Offset="' . $offset . '" BasePoint="Beginning"/>'
            . '<m:SortOrder><t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>'
            . '<m:ParentFolderIds>' . EwsXml::folderId($folder) . '</m:ParentFolderIds>' . $query . '</m:FindItem>',
            $user
        );
        $root = EwsXml::elements($xpath, '//m:RootFolder')[0] ?? null;
        $items = [];
        foreach (EwsXml::elements($xpath, '//t:Items/*') as $item) {
            $items[] = $this->messageSummary($xpath, $item);
        }

        return [
            'items' => $items,
            'total' => $root !== null ? (int) $root->getAttribute('TotalItemsInView') : count($items),
            'offset' => $offset,
            'has_more' => $root !== null && $root->getAttribute('IncludesLastItemInRange') === 'false',
        ];
    }

    /**
     * Vollstaendige Nachricht mit bereinigtem HTML-Text und Anhangsliste.
     *
     * @return array<string,mixed>
     */
    public function message(string $user, string $id): array
    {
        $xpath = $this->call(
            '<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>HTML</t:BodyType><t:AdditionalProperties>' . self::MESSAGE_FIELDS
            . '<t:FieldURI FieldURI="item:Body"/><t:FieldURI FieldURI="item:Attachments"/><t:FieldURI FieldURI="message:CcRecipients"/><t:FieldURI FieldURI="message:BccRecipients"/>'
            . '<t:FieldURI FieldURI="message:Sender"/><t:FieldURI FieldURI="message:ReplyTo"/><t:FieldURI FieldURI="message:InternetMessageId"/><t:FieldURI FieldURI="item:Sensitivity"/>'
            . '</t:AdditionalProperties></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>',
            $user
        );
        $item = EwsXml::elements($xpath, '//m:Items/*')[0] ?? null;
        if ($item === null) {
            throw new OrvantaException('Die Nachricht wurde nicht gefunden.', 404);
        }
        $data = $this->messageSummary($xpath, $item);
        $body = EwsXml::text($xpath, 't:Body', $item);
        $bodyType = EwsXml::attr($xpath, 't:Body', 'BodyType', $item);
        if ($bodyType === 'Text') {
            $data['body_html'] = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
            $data['blocked_images'] = 0;
        } else {
            $clean = MailHtmlSanitizer::clean($body);
            $data['body_html'] = $clean['html'];
            $data['blocked_images'] = $clean['blocked_images'];
        }
        $data['cc'] = EwsXml::mailboxes($xpath, 't:CcRecipients', $item);
        $data['bcc'] = EwsXml::mailboxes($xpath, 't:BccRecipients', $item);
        $data['reply_to'] = EwsXml::mailboxes($xpath, 't:ReplyTo', $item);
        $data['sender'] = EwsXml::mailbox($xpath, 't:Sender', $item);
        $data['internet_message_id'] = EwsXml::text($xpath, 't:InternetMessageId', $item);
        $data['attachments'] = $this->attachmentList($xpath, $item);

        return $data;
    }

    /**
     * Neue E-Mail senden (mit Kopie in „Gesendete Elemente“).
     *
     * @param array{to:list<string>,cc?:list<string>,bcc?:list<string>,subject:string,body:string,html?:bool,importance?:string,attachments?:list<array{name:string,content_type:string,content:string}>} $mail
     * @return array{id:string}
     */
    public function send(string $user, array $mail): array
    {
        if (($mail['to'] ?? []) === [] && ($mail['cc'] ?? []) === [] && ($mail['bcc'] ?? []) === []) {
            throw new OrvantaException('Bitte mindestens einen Empfänger angeben.', 422);
        }
        $attachments = $mail['attachments'] ?? [];
        $message = $this->messageXml($mail);
        if ($attachments === []) {
            $xpath = $this->call('<m:CreateItem MessageDisposition="SendAndSaveCopy"><m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId><m:Items>' . $message . '</m:Items></m:CreateItem>', $user);

            return ['id' => EwsXml::attr($xpath, '//m:Items/t:Message/t:ItemId', 'Id')];
        }

        $xpath = $this->call('<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId><t:DistinguishedFolderId Id="drafts"/></m:SavedItemFolderId><m:Items>' . $message . '</m:Items></m:CreateItem>', $user);
        $id = EwsXml::itemId($xpath, EwsXml::elements($xpath, '//m:Items/t:Message')[0] ?? $xpath->document);
        $changeKey = $this->addAttachments($user, $id, $attachments);
        $this->call(
            '<m:SendItem SaveItemToFolder="true">' . EwsXml::itemIds([['id' => $id['id'], 'change_key' => $changeKey]])
            . '<m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId></m:SendItem>',
            $user
        );

        return ['id' => $id['id']];
    }

    /**
     * Entwurf speichern.
     *
     * @param array<string,mixed> $mail
     * @return array{id:string}
     */
    public function saveDraft(string $user, array $mail): array
    {
        $xpath = $this->call('<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId><t:DistinguishedFolderId Id="drafts"/></m:SavedItemFolderId><m:Items>' . $this->messageXml($mail) . '</m:Items></m:CreateItem>', $user);

        return ['id' => EwsXml::attr($xpath, '//m:Items/t:Message/t:ItemId', 'Id')];
    }

    /**
     * Antwort oder Weiterleitung auf eine vorhandene Nachricht.
     *
     * @param 'reply'|'replyall'|'forward' $mode
     * @param list<string> $to
     */
    public function respond(string $user, string $id, string $mode, string $body, array $to = [], bool $html = true): void
    {
        $element = match ($mode) {
            'reply' => 'ReplyToItem',
            'replyall' => 'ReplyAllToItem',
            'forward' => 'ForwardItem',
            default => throw new OrvantaException('Unbekannte Antwortart.', 422),
        };
        if ($mode === 'forward' && $to === []) {
            throw new OrvantaException('Bitte einen Empfänger für die Weiterleitung angeben.', 422);
        }
        $xml = '<t:' . $element . '><t:ReferenceItemId Id="' . EwsXml::escape($id) . '"/>'
            . '<t:NewBodyContent BodyType="' . ($html ? 'HTML' : 'Text') . '">' . EwsXml::escape($body) . '</t:NewBodyContent>'
            . EwsXml::recipients('ToRecipients', $to)
            . '</t:' . $element . '>';
        $this->call('<m:CreateItem MessageDisposition="SendAndSaveCopy"><m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId><m:Items>' . $xml . '</m:Items></m:CreateItem>', $user);
    }

    /**
     * @param list<string> $ids
     */
    public function markRead(string $user, array $ids, bool $read): void
    {
        if ($ids === []) {
            return;
        }
        $changes = '';
        foreach ($ids as $id) {
            $changes .= '<t:ItemChange><t:ItemId Id="' . EwsXml::escape($id) . '"/><t:Updates><t:SetItemField><t:FieldURI FieldURI="message:IsRead"/><t:Message><t:IsRead>' . ($read ? 'true' : 'false') . '</t:IsRead></t:Message></t:SetItemField></t:Updates></t:ItemChange>';
        }
        $this->call('<m:UpdateItem ConflictResolution="AlwaysOverwrite" MessageDisposition="SaveOnly" SuppressReadReceipts="true"><m:ItemChanges>' . $changes . '</m:ItemChanges></m:UpdateItem>', $user);
    }

    /**
     * @param list<string> $ids
     */
    public function flag(string $user, array $ids, bool $flagged): void
    {
        if ($ids === []) {
            return;
        }
        $changes = '';
        foreach ($ids as $id) {
            $changes .= '<t:ItemChange><t:ItemId Id="' . EwsXml::escape($id) . '"/><t:Updates><t:SetItemField><t:FieldURI FieldURI="item:Flag"/><t:Message><t:Flag><t:FlagStatus>' . ($flagged ? 'Flagged' : 'NotFlagged') . '</t:FlagStatus></t:Flag></t:Message></t:SetItemField></t:Updates></t:ItemChange>';
        }
        $this->call('<m:UpdateItem ConflictResolution="AlwaysOverwrite" MessageDisposition="SaveOnly"><m:ItemChanges>' . $changes . '</m:ItemChanges></m:UpdateItem>', $user);
    }

    /**
     * @param list<string> $ids
     */
    public function move(string $user, array $ids, string $folder): void
    {
        if ($ids === []) {
            return;
        }
        $this->call('<m:MoveItem><m:ToFolderId>' . EwsXml::folderId($folder) . '</m:ToFolderId>' . EwsXml::itemIds(array_map(static fn (string $id): array => ['id' => $id], $ids)) . '</m:MoveItem>', $user);
    }

    /**
     * Loeschen: standardmaessig in „Gelöschte Elemente“ verschieben.
     *
     * @param list<string> $ids
     */
    public function delete(string $user, array $ids, bool $permanent = false): void
    {
        if ($ids === []) {
            return;
        }
        $type = $permanent ? 'HardDelete' : 'MoveToDeletedItems';
        $this->call('<m:DeleteItem DeleteType="' . $type . '" SendMeetingCancellations="SendToNone" AffectedTaskOccurrences="AllOccurrences">' . EwsXml::itemIds(array_map(static fn (string $id): array => ['id' => $id], $ids)) . '</m:DeleteItem>', $user);
    }

    /**
     * Anhang abrufen (Datei-Anhang mit Inhalt).
     *
     * @return array{name:string,content_type:string,content:string,size:int}
     */
    public function attachment(string $user, string $attachmentId): array
    {
        $xpath = $this->call('<m:GetAttachment><m:AttachmentIds><t:AttachmentId Id="' . EwsXml::escape($attachmentId) . '"/></m:AttachmentIds></m:GetAttachment>', $user);
        $file = EwsXml::elements($xpath, '//m:Attachments/t:FileAttachment')[0] ?? null;
        if ($file === null) {
            $item = EwsXml::elements($xpath, '//m:Attachments/t:ItemAttachment')[0] ?? null;
            if ($item === null) {
                throw new OrvantaException('Der Anhang wurde nicht gefunden.', 404);
            }
            $name = EwsXml::text($xpath, 't:Name', $item);
            $content = EwsXml::text($xpath, './/t:Body', $item);

            return ['name' => $name . '.html', 'content_type' => 'text/html', 'content' => $content, 'size' => strlen($content)];
        }
        $content = base64_decode(EwsXml::text($xpath, 't:Content', $file), true);
        if ($content === false) {
            throw new OrvantaException('Der Anhang konnte nicht dekodiert werden.', 502);
        }
        $name = EwsXml::text($xpath, 't:Name', $file);
        $type = EwsXml::text($xpath, 't:ContentType', $file);

        return ['name' => $name !== '' ? $name : 'anhang', 'content_type' => $type !== '' ? $type : 'application/octet-stream', 'content' => $content, 'size' => strlen($content)];
    }

    // ------------------------------------------------------------------
    // Kalender
    // ------------------------------------------------------------------

    /**
     * Termine im Zeitraum (inkl. aufgeloester Serienelemente).
     *
     * @return list<array<string,mixed>>
     */
    public function calendar(string $user, int $start, int $end, string $folder = 'calendar'): array
    {
        $xpath = $this->call(
            '<m:FindItem Traversal="Shallow"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . self::CALENDAR_FIELDS . '</t:AdditionalProperties></m:ItemShape>'
            . '<m:CalendarView MaxEntriesReturned="500" StartDate="' . EwsXml::dateTime($start) . '" EndDate="' . EwsXml::dateTime($end) . '"/>'
            . '<m:ParentFolderIds>' . EwsXml::folderId($folder) . '</m:ParentFolderIds></m:FindItem>',
            $user
        );
        $items = [];
        foreach (EwsXml::elements($xpath, '//t:Items/t:CalendarItem') as $item) {
            $items[] = $this->calendarSummary($xpath, $item);
        }
        usort($items, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    public function event(string $user, string $id): array
    {
        $xpath = $this->call(
            '<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:BodyType>HTML</t:BodyType><t:AdditionalProperties>' . self::CALENDAR_FIELDS
            . '<t:FieldURI FieldURI="item:Body"/><t:FieldURI FieldURI="calendar:RequiredAttendees"/><t:FieldURI FieldURI="calendar:OptionalAttendees"/><t:FieldURI FieldURI="item:Attachments"/><t:FieldURI FieldURI="calendar:Recurrence"/>'
            . '</t:AdditionalProperties></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>',
            $user
        );
        $item = EwsXml::elements($xpath, '//m:Items/t:CalendarItem')[0] ?? null;
        if ($item === null) {
            throw new OrvantaException('Der Termin wurde nicht gefunden.', 404);
        }
        $data = $this->calendarSummary($xpath, $item);
        $data['body_html'] = MailHtmlSanitizer::clean(EwsXml::text($xpath, 't:Body', $item))['html'];
        $data['required'] = $this->attendees($xpath, 't:RequiredAttendees', $item);
        $data['optional'] = $this->attendees($xpath, 't:OptionalAttendees', $item);
        $data['attachments'] = $this->attachmentList($xpath, $item);
        $data['recurring'] = EwsXml::elements($xpath, 't:Recurrence', $item) !== [];

        return $data;
    }

    /**
     * @param array{subject:string,start:int,end:int,all_day?:bool,location?:string,body?:string,reminder?:int,free_busy?:string,required?:list<string>,optional?:list<string>} $event
     * @return array{id:string,change_key:string}
     */
    public function createEvent(string $user, array $event): array
    {
        $this->assertEvent($event);
        $hasAttendees = ($event['required'] ?? []) !== [] || ($event['optional'] ?? []) !== [];
        $xml = '<t:CalendarItem>'
            . '<t:Subject>' . EwsXml::escape($event['subject']) . '</t:Subject>'
            . '<t:Body BodyType="HTML">' . EwsXml::escape(MailHtmlSanitizer::clean((string) ($event['body'] ?? ''))['html']) . '</t:Body>'
            . '<t:ReminderIsSet>' . (($event['reminder'] ?? -1) >= 0 ? 'true' : 'false') . '</t:ReminderIsSet>'
            . '<t:ReminderMinutesBeforeStart>' . max(0, (int) ($event['reminder'] ?? 15)) . '</t:ReminderMinutesBeforeStart>'
            . '<t:Start>' . EwsXml::dateTime($event['start']) . '</t:Start><t:End>' . EwsXml::dateTime($event['end']) . '</t:End>'
            . '<t:IsAllDayEvent>' . (!empty($event['all_day']) ? 'true' : 'false') . '</t:IsAllDayEvent>'
            . '<t:LegacyFreeBusyStatus>' . self::freeBusy((string) ($event['free_busy'] ?? 'Busy')) . '</t:LegacyFreeBusyStatus>'
            . '<t:Location>' . EwsXml::escape((string) ($event['location'] ?? '')) . '</t:Location>'
            . $this->attendeesXml('RequiredAttendees', $event['required'] ?? [])
            . $this->attendeesXml('OptionalAttendees', $event['optional'] ?? [])
            . '</t:CalendarItem>';
        $xpath = $this->call('<m:CreateItem SendMeetingInvitations="' . ($hasAttendees ? 'SendToAllAndSaveCopy' : 'SendToNone') . '"><m:SavedItemFolderId><t:DistinguishedFolderId Id="calendar"/></m:SavedItemFolderId><m:Items>' . $xml . '</m:Items></m:CreateItem>', $user);
        $node = EwsXml::elements($xpath, '//m:Items/t:CalendarItem')[0] ?? null;

        return $node !== null ? EwsXml::itemId($xpath, $node) : ['id' => '', 'change_key' => ''];
    }

    /**
     * @param array<string,mixed> $event
     */
    public function updateEvent(string $user, string $id, array $event, string $changeKey = ''): void
    {
        $this->assertEvent($event);
        $set = static fn (string $field, string $inner): string => '<t:SetItemField><t:FieldURI FieldURI="' . $field . '"/><t:CalendarItem>' . $inner . '</t:CalendarItem></t:SetItemField>';
        $updates = $set('item:Subject', '<t:Subject>' . EwsXml::escape((string) $event['subject']) . '</t:Subject>')
            . $set('calendar:Start', '<t:Start>' . EwsXml::dateTime((int) $event['start']) . '</t:Start>')
            . $set('calendar:End', '<t:End>' . EwsXml::dateTime((int) $event['end']) . '</t:End>')
            . $set('calendar:IsAllDayEvent', '<t:IsAllDayEvent>' . (!empty($event['all_day']) ? 'true' : 'false') . '</t:IsAllDayEvent>')
            . $set('calendar:Location', '<t:Location>' . EwsXml::escape((string) ($event['location'] ?? '')) . '</t:Location>')
            . $set('calendar:LegacyFreeBusyStatus', '<t:LegacyFreeBusyStatus>' . self::freeBusy((string) ($event['free_busy'] ?? 'Busy')) . '</t:LegacyFreeBusyStatus>')
            . $set('item:ReminderIsSet', '<t:ReminderIsSet>' . (($event['reminder'] ?? -1) >= 0 ? 'true' : 'false') . '</t:ReminderIsSet>')
            . $set('item:ReminderMinutesBeforeStart', '<t:ReminderMinutesBeforeStart>' . max(0, (int) ($event['reminder'] ?? 15)) . '</t:ReminderMinutesBeforeStart>');
        if (array_key_exists('body', $event)) {
            $updates .= $set('item:Body', '<t:Body BodyType="HTML">' . EwsXml::escape(MailHtmlSanitizer::clean((string) $event['body'])['html']) . '</t:Body>');
        }
        $this->call(
            '<m:UpdateItem ConflictResolution="AlwaysOverwrite" SendMeetingInvitationsOrCancellations="SendToChangedAndSaveCopy"><m:ItemChanges><t:ItemChange>'
            . '<t:ItemId Id="' . EwsXml::escape($id) . '"' . ($changeKey !== '' ? ' ChangeKey="' . EwsXml::escape($changeKey) . '"' : '') . '/>'
            . '<t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>',
            $user
        );
    }

    public function deleteEvent(string $user, string $id): void
    {
        $this->call('<m:DeleteItem DeleteType="MoveToDeletedItems" SendMeetingCancellations="SendToAllAndSaveCopy">' . EwsXml::itemIds([['id' => $id]]) . '</m:DeleteItem>', $user);
    }

    /**
     * Antwort auf eine Besprechungsanfrage.
     *
     * @param 'accept'|'tentative'|'decline' $response
     */
    public function respondToMeeting(string $user, string $id, string $response): void
    {
        $element = match ($response) {
            'accept' => 'AcceptItem',
            'tentative' => 'TentativelyAcceptItem',
            'decline' => 'DeclineItem',
            default => throw new OrvantaException('Unbekannte Antwort.', 422),
        };
        $this->call('<m:CreateItem MessageDisposition="SendAndSaveCopy"><m:Items><t:' . $element . '><t:ReferenceItemId Id="' . EwsXml::escape($id) . '"/></t:' . $element . '></m:Items></m:CreateItem>', $user);
    }

    /**
     * Anstehende Termine mit gesetzter Erinnerung fuer den Erinnerungsdienst.
     *
     * @return list<array{id:string,subject:string,location:string,start:int,end:int,remind_at:int}>
     */
    public function upcomingReminders(string $user, int $from, int $hours = 48): array
    {
        $result = [];
        foreach ($this->calendar($user, $from - 3600, $from + $hours * 3600) as $event) {
            if (!$event['reminder_set']) {
                continue;
            }
            $result[] = [
                'id' => $event['id'],
                'subject' => $event['subject'],
                'location' => $event['location'],
                'start' => $event['start'],
                'end' => $event['end'],
                'remind_at' => $event['start'] - $event['reminder_minutes'] * 60,
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Kontakte
    // ------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public function contacts(string $user, string $search = '', int $limit = 200): array
    {
        $query = $search !== '' ? '<m:QueryString>' . EwsXml::escape($search) . '</m:QueryString>' : '';
        $xpath = $this->call(
            '<m:FindItem Traversal="Shallow"><m:ItemShape><t:BaseShape>AllProperties</t:BaseShape></m:ItemShape>'
            . '<m:IndexedPageItemView MaxEntriesReturned="' . max(1, min(500, $limit)) . '" Offset="0" BasePoint="Beginning"/>'
            . '<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="contacts:FileAs"/></t:FieldOrder></m:SortOrder>'
            . '<m:ParentFolderIds><t:DistinguishedFolderId Id="contacts"/></m:ParentFolderIds>' . $query . '</m:FindItem>',
            $user
        );
        $items = [];
        foreach (EwsXml::elements($xpath, '//t:Items/t:Contact') as $item) {
            $items[] = $this->contactData($xpath, $item);
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    public function contact(string $user, string $id): array
    {
        $xpath = $this->call('<m:GetItem><m:ItemShape><t:BaseShape>AllProperties</t:BaseShape><t:BodyType>Text</t:BodyType></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>', $user);
        $item = EwsXml::elements($xpath, '//m:Items/t:Contact')[0] ?? null;
        if ($item === null) {
            throw new OrvantaException('Der Kontakt wurde nicht gefunden.', 404);
        }

        return $this->contactData($xpath, $item);
    }

    /**
     * @param array<string,mixed> $contact
     * @return array{id:string,change_key:string}
     */
    public function createContact(string $user, array $contact): array
    {
        $xml = '<t:Contact>' . $this->contactFieldsXml($contact) . '</t:Contact>';
        $xpath = $this->call('<m:CreateItem><m:SavedItemFolderId><t:DistinguishedFolderId Id="contacts"/></m:SavedItemFolderId><m:Items>' . $xml . '</m:Items></m:CreateItem>', $user);
        $node = EwsXml::elements($xpath, '//m:Items/t:Contact')[0] ?? null;

        return $node !== null ? EwsXml::itemId($xpath, $node) : ['id' => '', 'change_key' => ''];
    }

    /**
     * @param array<string,mixed> $contact
     */
    public function updateContact(string $user, string $id, array $contact): void
    {
        $set = static fn (string $field, string $inner): string => '<t:SetItemField>' . $field . '<t:Contact>' . $inner . '</t:Contact></t:SetItemField>';
        $updates = $set('<t:FieldURI FieldURI="contacts:GivenName"/>', '<t:GivenName>' . EwsXml::escape((string) ($contact['given_name'] ?? '')) . '</t:GivenName>')
            . $set('<t:FieldURI FieldURI="contacts:Surname"/>', '<t:Surname>' . EwsXml::escape((string) ($contact['surname'] ?? '')) . '</t:Surname>')
            . $set('<t:FieldURI FieldURI="contacts:CompanyName"/>', '<t:CompanyName>' . EwsXml::escape((string) ($contact['company'] ?? '')) . '</t:CompanyName>')
            . $set('<t:FieldURI FieldURI="contacts:JobTitle"/>', '<t:JobTitle>' . EwsXml::escape((string) ($contact['job_title'] ?? '')) . '</t:JobTitle>')
            . $set('<t:FieldURI FieldURI="contacts:Department"/>', '<t:Department>' . EwsXml::escape((string) ($contact['department'] ?? '')) . '</t:Department>')
            . $set('<t:IndexedFieldURI FieldURI="contacts:EmailAddress" FieldIndex="EmailAddress1"/>', '<t:EmailAddresses><t:Entry Key="EmailAddress1">' . EwsXml::escape((string) ($contact['email'] ?? '')) . '</t:Entry></t:EmailAddresses>')
            . $set('<t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="BusinessPhone"/>', '<t:PhoneNumbers><t:Entry Key="BusinessPhone">' . EwsXml::escape((string) ($contact['phone'] ?? '')) . '</t:Entry></t:PhoneNumbers>')
            . $set('<t:IndexedFieldURI FieldURI="contacts:PhoneNumber" FieldIndex="MobilePhone"/>', '<t:PhoneNumbers><t:Entry Key="MobilePhone">' . EwsXml::escape((string) ($contact['mobile'] ?? '')) . '</t:Entry></t:PhoneNumbers>')
            . $set('<t:FieldURI FieldURI="item:Body"/>', '<t:Body BodyType="Text">' . EwsXml::escape((string) ($contact['notes'] ?? '')) . '</t:Body>');
        $this->call('<m:UpdateItem ConflictResolution="AlwaysOverwrite"><m:ItemChanges><t:ItemChange><t:ItemId Id="' . EwsXml::escape($id) . '"/><t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>', $user);
    }

    // ------------------------------------------------------------------
    // Aufgaben
    // ------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public function tasks(string $user, bool $includeCompleted = true): array
    {
        $restriction = $includeCompleted ? '' : '<m:Restriction><t:IsNotEqualTo><t:FieldURI FieldURI="task:Status"/><t:FieldURIOrConstant><t:Constant Value="Completed"/></t:FieldURIOrConstant></t:IsNotEqualTo></m:Restriction>';
        $xpath = $this->call(
            '<m:FindItem Traversal="Shallow"><m:ItemShape><t:BaseShape>AllProperties</t:BaseShape></m:ItemShape>'
            . '<m:IndexedPageItemView MaxEntriesReturned="500" Offset="0" BasePoint="Beginning"/>' . $restriction
            . '<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="task:DueDate"/></t:FieldOrder></m:SortOrder>'
            . '<m:ParentFolderIds><t:DistinguishedFolderId Id="tasks"/></m:ParentFolderIds></m:FindItem>',
            $user
        );
        $items = [];
        foreach (EwsXml::elements($xpath, '//t:Items/t:Task') as $item) {
            $items[] = $this->taskData($xpath, $item);
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    public function task(string $user, string $id): array
    {
        $xpath = $this->call('<m:GetItem><m:ItemShape><t:BaseShape>AllProperties</t:BaseShape><t:BodyType>Text</t:BodyType></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>', $user);
        $item = EwsXml::elements($xpath, '//m:Items/t:Task')[0] ?? null;
        if ($item === null) {
            throw new OrvantaException('Die Aufgabe wurde nicht gefunden.', 404);
        }

        return $this->taskData($xpath, $item);
    }

    /**
     * @param array{subject:string,body?:string,due?:int,start?:int,status?:string,percent?:int,importance?:string,reminder?:int} $task
     * @return array{id:string,change_key:string}
     */
    public function createTask(string $user, array $task): array
    {
        if (trim($task['subject']) === '') {
            throw new OrvantaException('Bitte einen Betreff für die Aufgabe angeben.', 422);
        }
        $xml = '<t:Task><t:Subject>' . EwsXml::escape($task['subject']) . '</t:Subject>'
            . '<t:Body BodyType="Text">' . EwsXml::escape((string) ($task['body'] ?? '')) . '</t:Body>'
            . '<t:Importance>' . self::importance((string) ($task['importance'] ?? 'Normal')) . '</t:Importance>'
            . (($task['reminder'] ?? 0) > 0 ? '<t:ReminderIsSet>true</t:ReminderIsSet><t:ReminderDueBy>' . EwsXml::dateTime((int) $task['reminder']) . '</t:ReminderDueBy>' : '')
            . (!empty($task['due']) ? '<t:DueDate>' . EwsXml::dateTime((int) $task['due']) . '</t:DueDate>' : '')
            . '<t:PercentComplete>' . max(0, min(100, (int) ($task['percent'] ?? 0))) . '</t:PercentComplete>'
            . (!empty($task['start']) ? '<t:StartDate>' . EwsXml::dateTime((int) $task['start']) . '</t:StartDate>' : '')
            . '<t:Status>' . self::taskStatus((string) ($task['status'] ?? 'NotStarted')) . '</t:Status>'
            . '</t:Task>';
        $xpath = $this->call('<m:CreateItem><m:SavedItemFolderId><t:DistinguishedFolderId Id="tasks"/></m:SavedItemFolderId><m:Items>' . $xml . '</m:Items></m:CreateItem>', $user);
        $node = EwsXml::elements($xpath, '//m:Items/t:Task')[0] ?? null;

        return $node !== null ? EwsXml::itemId($xpath, $node) : ['id' => '', 'change_key' => ''];
    }

    /**
     * @param array<string,mixed> $task
     */
    public function updateTask(string $user, string $id, array $task): void
    {
        $set = static fn (string $field, string $inner): string => '<t:SetItemField><t:FieldURI FieldURI="' . $field . '"/><t:Task>' . $inner . '</t:Task></t:SetItemField>';
        $delete = static fn (string $field): string => '<t:DeleteItemField><t:FieldURI FieldURI="' . $field . '"/></t:DeleteItemField>';
        $updates = '';
        if (isset($task['subject'])) {
            $updates .= $set('item:Subject', '<t:Subject>' . EwsXml::escape((string) $task['subject']) . '</t:Subject>');
        }
        if (array_key_exists('body', $task)) {
            $updates .= $set('item:Body', '<t:Body BodyType="Text">' . EwsXml::escape((string) $task['body']) . '</t:Body>');
        }
        if (isset($task['importance'])) {
            $updates .= $set('item:Importance', '<t:Importance>' . self::importance((string) $task['importance']) . '</t:Importance>');
        }
        if (array_key_exists('due', $task)) {
            $updates .= !empty($task['due']) ? $set('task:DueDate', '<t:DueDate>' . EwsXml::dateTime((int) $task['due']) . '</t:DueDate>') : $delete('task:DueDate');
        }
        if (array_key_exists('start', $task)) {
            $updates .= !empty($task['start']) ? $set('task:StartDate', '<t:StartDate>' . EwsXml::dateTime((int) $task['start']) . '</t:StartDate>') : $delete('task:StartDate');
        }
        if (isset($task['status'])) {
            $status = self::taskStatus((string) $task['status']);
            $percent = $status === 'Completed' ? 100 : ($status === 'NotStarted' ? 0 : max(0, min(100, (int) ($task['percent'] ?? 50))));
            $updates .= $set('task:PercentComplete', '<t:PercentComplete>' . $percent . '</t:PercentComplete>');
            $updates .= $set('task:Status', '<t:Status>' . $status . '</t:Status>');
        } elseif (isset($task['percent'])) {
            $updates .= $set('task:PercentComplete', '<t:PercentComplete>' . max(0, min(100, (int) $task['percent'])) . '</t:PercentComplete>');
        }
        if ($updates === '') {
            return;
        }
        $this->call('<m:UpdateItem ConflictResolution="AlwaysOverwrite"><m:ItemChanges><t:ItemChange><t:ItemId Id="' . EwsXml::escape($id) . '"/><t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>', $user);
    }

    // ------------------------------------------------------------------
    // Notizen (IPM.StickyNote)
    // ------------------------------------------------------------------

    /**
     * @return list<array{id:string,change_key:string,subject:string,preview:string,modified:int,color:string}>
     */
    public function notes(string $user): array
    {
        $xpath = $this->call(
            '<m:FindItem Traversal="Shallow"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
            . '<t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="item:LastModifiedTime"/><t:FieldURI FieldURI="item:Preview"/><t:FieldURI FieldURI="item:Categories"/>'
            . '<t:ExtendedFieldURI PropertyTag="0x8B00" PropertyType="Integer"/>'
            . '</t:AdditionalProperties></m:ItemShape><m:IndexedPageItemView MaxEntriesReturned="500" Offset="0" BasePoint="Beginning"/>'
            . '<m:SortOrder><t:FieldOrder Order="Descending"><t:FieldURI FieldURI="item:LastModifiedTime"/></t:FieldOrder></m:SortOrder>'
            . '<m:ParentFolderIds><t:DistinguishedFolderId Id="notes"/></m:ParentFolderIds></m:FindItem>',
            $user
        );
        $items = [];
        foreach (EwsXml::elements($xpath, '//t:Items/*') as $item) {
            $items[] = [
                'id' => EwsXml::attr($xpath, 't:ItemId', 'Id', $item),
                'change_key' => EwsXml::attr($xpath, 't:ItemId', 'ChangeKey', $item),
                'subject' => EwsXml::text($xpath, 't:Subject', $item),
                'preview' => EwsXml::text($xpath, 't:Preview', $item),
                'modified' => EwsXml::timestamp(EwsXml::text($xpath, 't:LastModifiedTime', $item)),
                'color' => self::noteColor(EwsXml::text($xpath, 't:ExtendedProperty/t:Value', $item)),
            ];
        }

        return $items;
    }

    /**
     * @return array{id:string,change_key:string,subject:string,body:string,modified:int}
     */
    public function note(string $user, string $id): array
    {
        $xpath = $this->call('<m:GetItem><m:ItemShape><t:BaseShape>Default</t:BaseShape><t:BodyType>Text</t:BodyType><t:AdditionalProperties><t:FieldURI FieldURI="item:LastModifiedTime"/></t:AdditionalProperties></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>', $user);
        $item = EwsXml::elements($xpath, '//m:Items/*')[0] ?? null;
        if ($item === null) {
            throw new OrvantaException('Die Notiz wurde nicht gefunden.', 404);
        }

        return [
            'id' => EwsXml::attr($xpath, 't:ItemId', 'Id', $item),
            'change_key' => EwsXml::attr($xpath, 't:ItemId', 'ChangeKey', $item),
            'subject' => EwsXml::text($xpath, 't:Subject', $item),
            'body' => EwsXml::text($xpath, 't:Body', $item),
            'modified' => EwsXml::timestamp(EwsXml::text($xpath, 't:LastModifiedTime', $item)),
        ];
    }

    /**
     * @return array{id:string,change_key:string}
     */
    public function createNote(string $user, string $body): array
    {
        $subject = self::noteSubject($body);
        $xml = '<t:Item><t:ItemClass>IPM.StickyNote</t:ItemClass><t:Subject>' . EwsXml::escape($subject) . '</t:Subject><t:Body BodyType="Text">' . EwsXml::escape($body) . '</t:Body>'
            . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x8B00" PropertyType="Integer"/><t:Value>3</t:Value></t:ExtendedProperty></t:Item>';
        $xpath = $this->call('<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId><t:DistinguishedFolderId Id="notes"/></m:SavedItemFolderId><m:Items>' . $xml . '</m:Items></m:CreateItem>', $user);
        $node = EwsXml::elements($xpath, '//m:Items/*')[0] ?? null;

        return $node !== null ? EwsXml::itemId($xpath, $node) : ['id' => '', 'change_key' => ''];
    }

    public function updateNote(string $user, string $id, string $body): void
    {
        $subject = self::noteSubject($body);
        $this->call(
            '<m:UpdateItem ConflictResolution="AlwaysOverwrite" MessageDisposition="SaveOnly"><m:ItemChanges><t:ItemChange><t:ItemId Id="' . EwsXml::escape($id) . '"/><t:Updates>'
            . '<t:SetItemField><t:FieldURI FieldURI="item:Subject"/><t:Item><t:Subject>' . EwsXml::escape($subject) . '</t:Subject></t:Item></t:SetItemField>'
            . '<t:SetItemField><t:FieldURI FieldURI="item:Body"/><t:Item><t:Body BodyType="Text">' . EwsXml::escape($body) . '</t:Body></t:Item></t:SetItemField>'
            . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>',
            $user
        );
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /**
     * SOAP-Aufruf mit Fehlerbehandlung. $strict=false toleriert Teilfehler
     * (z. B. nicht vorhandene Systemordner).
     */
    private function call(string $body, string $impersonate, bool $strict = true): DOMXPath
    {
        $url = $this->config->ewsUrl();
        if ($url === '') {
            throw new OrvantaException('Es ist kein Exchange-Server konfiguriert. Bitte im Adminbereich unter Office → Orvanta eintragen.', 503);
        }
        $xml = EwsXml::envelope($body, $this->config->get('exchange_version'), $impersonate);
        $response = $this->transport->post($url, $xml, $this->config->transportOptions());
        if (($response['error'] ?? '') !== '') {
            throw new OrvantaException('Exchange ist nicht erreichbar: ' . $response['error'], 502);
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new OrvantaException('Exchange hat die Anmeldung abgelehnt (HTTP ' . $response['status'] . '). Bitte Anmeldeverfahren und Dienstkonto prüfen.', 502);
        }
        $xpath = EwsXml::parse($response['body']);
        if ($xpath === null) {
            throw new OrvantaException('Exchange hat eine ungültige Antwort geliefert (HTTP ' . $response['status'] . ').', 502);
        }
        $error = EwsXml::error($xpath);
        if ($error !== null && ($strict || str_contains($response['body'], 'soap:Fault'))) {
            $all = EwsXml::elements($xpath, '//m:ResponseMessages/*');
            $failed = EwsXml::elements($xpath, '//m:ResponseMessages/*[@ResponseClass="Error"]');
            if ($strict || $all === [] || count($failed) === count($all)) {
                throw new OrvantaException(self::translate($error), 502);
            }
        }

        return $xpath;
    }

    private static function translate(string $error): string
    {
        return match (true) {
            str_contains($error, 'ErrorImpersonateUserDenied'), str_contains($error, 'ErrorImpersonationDenied') => 'Dem Dienstkonto fehlt die Berechtigung „ApplicationImpersonation“ für dieses Postfach.',
            str_contains($error, 'ErrorNonExistentMailbox') => 'Für die SSO-Identität wurde kein Exchange-Postfach gefunden.',
            str_contains($error, 'ErrorItemNotFound') => 'Das Element wurde nicht gefunden (möglicherweise bereits verschoben oder gelöscht).',
            str_contains($error, 'ErrorFolderNotFound') => 'Der Ordner wurde nicht gefunden.',
            str_contains($error, 'ErrorAccessDenied') => 'Zugriff verweigert: ' . $error,
            str_contains($error, 'ErrorSchemaValidation') => 'Exchange hat die Anfrage abgelehnt (Schemafehler): ' . $error,
            default => 'Exchange-Fehler: ' . $error,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function messageSummary(DOMXPath $xpath, DOMElement $item): array
    {
        $id = EwsXml::itemId($xpath, $item);
        $received = EwsXml::timestamp(EwsXml::text($xpath, 't:DateTimeReceived', $item));
        $sent = EwsXml::timestamp(EwsXml::text($xpath, 't:DateTimeSent', $item));
        $class = EwsXml::text($xpath, 't:ItemClass', $item);
        $categories = [];
        foreach (EwsXml::elements($xpath, 't:Categories/t:String', $item) as $category) {
            $categories[] = trim($category->textContent);
        }

        return [
            'id' => $id['id'],
            'change_key' => $id['change_key'],
            'subject' => EwsXml::text($xpath, 't:Subject', $item),
            'preview' => EwsXml::text($xpath, 't:Preview', $item),
            'from' => EwsXml::mailbox($xpath, 't:From', $item),
            'to' => EwsXml::mailboxes($xpath, 't:ToRecipients', $item),
            'received' => $received > 0 ? $received : $sent,
            'sent' => $sent,
            'is_read' => EwsXml::bool($xpath, 't:IsRead', $item),
            'has_attachments' => EwsXml::bool($xpath, 't:HasAttachments', $item),
            'size' => (int) EwsXml::text($xpath, 't:Size', $item),
            'importance' => EwsXml::text($xpath, 't:Importance', $item) ?: 'Normal',
            'flagged' => EwsXml::text($xpath, 't:Flag/t:FlagStatus', $item) === 'Flagged',
            'categories' => $categories,
            'item_class' => $class,
            'is_meeting_request' => str_starts_with($class, 'IPM.Schedule.Meeting.Request'),
        ];
    }

    /**
     * @return list<array{id:string,name:string,content_type:string,size:int,inline:bool,is_item:bool}>
     */
    private function attachmentList(DOMXPath $xpath, DOMElement $item): array
    {
        $list = [];
        foreach (EwsXml::elements($xpath, 't:Attachments/*', $item) as $attachment) {
            $list[] = [
                'id' => EwsXml::attr($xpath, 't:AttachmentId', 'Id', $attachment),
                'name' => EwsXml::text($xpath, 't:Name', $attachment),
                'content_type' => EwsXml::text($xpath, 't:ContentType', $attachment),
                'size' => (int) EwsXml::text($xpath, 't:Size', $attachment),
                'inline' => EwsXml::bool($xpath, 't:IsInline', $attachment),
                'is_item' => $attachment->localName === 'ItemAttachment',
            ];
        }

        return $list;
    }

    /**
     * @return array<string,mixed>
     */
    private function calendarSummary(DOMXPath $xpath, DOMElement $item): array
    {
        $id = EwsXml::itemId($xpath, $item);
        $categories = [];
        foreach (EwsXml::elements($xpath, 't:Categories/t:String', $item) as $category) {
            $categories[] = trim($category->textContent);
        }

        return [
            'id' => $id['id'],
            'change_key' => $id['change_key'],
            'subject' => EwsXml::text($xpath, 't:Subject', $item),
            'start' => EwsXml::timestamp(EwsXml::text($xpath, 't:Start', $item)),
            'end' => EwsXml::timestamp(EwsXml::text($xpath, 't:End', $item)),
            'all_day' => EwsXml::bool($xpath, 't:IsAllDayEvent', $item),
            'location' => EwsXml::text($xpath, 't:Location', $item),
            'organizer' => EwsXml::mailbox($xpath, 't:Organizer', $item),
            'free_busy' => EwsXml::text($xpath, 't:LegacyFreeBusyStatus', $item) ?: 'Busy',
            'type' => EwsXml::text($xpath, 't:CalendarItemType', $item) ?: 'Single',
            'reminder_set' => EwsXml::bool($xpath, 't:ReminderIsSet', $item),
            'reminder_minutes' => (int) EwsXml::text($xpath, 't:ReminderMinutesBeforeStart', $item),
            'is_meeting' => EwsXml::bool($xpath, 't:IsMeeting', $item),
            'my_response' => EwsXml::text($xpath, 't:MyResponseType', $item),
            'categories' => $categories,
        ];
    }

    /**
     * @return list<array{name:string,email:string,response:string}>
     */
    private function attendees(DOMXPath $xpath, string $query, DOMElement $item): array
    {
        $list = [];
        foreach (EwsXml::elements($xpath, $query . '/t:Attendee', $item) as $attendee) {
            $list[] = [
                'name' => EwsXml::text($xpath, 't:Mailbox/t:Name', $attendee),
                'email' => EwsXml::text($xpath, 't:Mailbox/t:EmailAddress', $attendee),
                'response' => EwsXml::text($xpath, 't:ResponseType', $attendee),
            ];
        }

        return $list;
    }

    /**
     * @param list<string> $addresses
     */
    private function attendeesXml(string $element, array $addresses): string
    {
        if ($addresses === []) {
            return '';
        }
        $xml = '<t:' . $element . '>';
        foreach ($addresses as $address) {
            $xml .= '<t:Attendee><t:Mailbox><t:EmailAddress>' . EwsXml::escape($address) . '</t:EmailAddress></t:Mailbox></t:Attendee>';
        }

        return $xml . '</t:' . $element . '>';
    }

    /**
     * @return array<string,mixed>
     */
    private function contactData(DOMXPath $xpath, DOMElement $item): array
    {
        $id = EwsXml::itemId($xpath, $item);
        $emails = [];
        foreach (EwsXml::elements($xpath, 't:EmailAddresses/t:Entry', $item) as $entry) {
            $value = trim($entry->textContent);
            if ($value !== '') {
                $emails[] = preg_replace('/^(?:smtp|sip):/i', '', $value);
            }
        }
        $phones = [];
        foreach (EwsXml::elements($xpath, 't:PhoneNumbers/t:Entry', $item) as $entry) {
            $value = trim($entry->textContent);
            if ($value !== '') {
                $phones[$entry->getAttribute('Key')] = $value;
            }
        }
        $address = EwsXml::elements($xpath, 't:PhysicalAddresses/t:Entry', $item)[0] ?? null;
        $display = EwsXml::text($xpath, 't:DisplayName', $item);

        return [
            'id' => $id['id'],
            'change_key' => $id['change_key'],
            'display_name' => $display !== '' ? $display : trim(EwsXml::text($xpath, 't:GivenName', $item) . ' ' . EwsXml::text($xpath, 't:Surname', $item)),
            'file_as' => EwsXml::text($xpath, 't:FileAs', $item),
            'given_name' => EwsXml::text($xpath, 't:GivenName', $item),
            'surname' => EwsXml::text($xpath, 't:Surname', $item),
            'company' => EwsXml::text($xpath, 't:CompanyName', $item),
            'job_title' => EwsXml::text($xpath, 't:JobTitle', $item),
            'department' => EwsXml::text($xpath, 't:Department', $item),
            'email' => $emails[0] ?? '',
            'emails' => $emails,
            'phone' => $phones['BusinessPhone'] ?? ($phones['HomePhone'] ?? ''),
            'mobile' => $phones['MobilePhone'] ?? '',
            'phones' => $phones,
            'address' => $address !== null ? trim(implode(', ', array_filter([
                EwsXml::text($xpath, 't:Street', $address),
                trim(EwsXml::text($xpath, 't:PostalCode', $address) . ' ' . EwsXml::text($xpath, 't:City', $address)),
                EwsXml::text($xpath, 't:CountryOrRegion', $address),
            ]))) : '',
            'notes' => EwsXml::text($xpath, 't:Body', $item),
        ];
    }

    /**
     * @param array<string,mixed> $contact
     */
    private function contactFieldsXml(array $contact): string
    {
        $given = trim((string) ($contact['given_name'] ?? ''));
        $surname = trim((string) ($contact['surname'] ?? ''));
        if ($given === '' && $surname === '' && trim((string) ($contact['company'] ?? '')) === '') {
            throw new OrvantaException('Bitte mindestens Vorname, Nachname oder Firma angeben.', 422);
        }
        $xml = '<t:Body BodyType="Text">' . EwsXml::escape((string) ($contact['notes'] ?? '')) . '</t:Body>'
            . '<t:FileAsMapping>LastCommaFirst</t:FileAsMapping>'
            . '<t:GivenName>' . EwsXml::escape($given) . '</t:GivenName>'
            . '<t:CompanyName>' . EwsXml::escape((string) ($contact['company'] ?? '')) . '</t:CompanyName>';
        if (trim((string) ($contact['email'] ?? '')) !== '') {
            $xml .= '<t:EmailAddresses><t:Entry Key="EmailAddress1">' . EwsXml::escape(trim((string) $contact['email'])) . '</t:Entry></t:EmailAddresses>';
        }
        $phones = '';
        if (trim((string) ($contact['phone'] ?? '')) !== '') {
            $phones .= '<t:Entry Key="BusinessPhone">' . EwsXml::escape(trim((string) $contact['phone'])) . '</t:Entry>';
        }
        if (trim((string) ($contact['mobile'] ?? '')) !== '') {
            $phones .= '<t:Entry Key="MobilePhone">' . EwsXml::escape(trim((string) $contact['mobile'])) . '</t:Entry>';
        }
        if ($phones !== '') {
            $xml .= '<t:PhoneNumbers>' . $phones . '</t:PhoneNumbers>';
        }
        $xml .= '<t:Department>' . EwsXml::escape((string) ($contact['department'] ?? '')) . '</t:Department>'
            . '<t:JobTitle>' . EwsXml::escape((string) ($contact['job_title'] ?? '')) . '</t:JobTitle>'
            . '<t:Surname>' . EwsXml::escape($surname) . '</t:Surname>';

        return $xml;
    }

    /**
     * @return array<string,mixed>
     */
    private function taskData(DOMXPath $xpath, DOMElement $item): array
    {
        $id = EwsXml::itemId($xpath, $item);

        return [
            'id' => $id['id'],
            'change_key' => $id['change_key'],
            'subject' => EwsXml::text($xpath, 't:Subject', $item),
            'body' => EwsXml::text($xpath, 't:Body', $item),
            'due' => EwsXml::timestamp(EwsXml::text($xpath, 't:DueDate', $item)),
            'start' => EwsXml::timestamp(EwsXml::text($xpath, 't:StartDate', $item)),
            'completed_at' => EwsXml::timestamp(EwsXml::text($xpath, 't:CompleteDate', $item)),
            'status' => EwsXml::text($xpath, 't:Status', $item) ?: 'NotStarted',
            'percent' => (int) EwsXml::text($xpath, 't:PercentComplete', $item),
            'importance' => EwsXml::text($xpath, 't:Importance', $item) ?: 'Normal',
            'is_complete' => EwsXml::bool($xpath, 't:IsComplete', $item),
            'reminder' => EwsXml::bool($xpath, 't:ReminderIsSet', $item) ? EwsXml::timestamp(EwsXml::text($xpath, 't:ReminderDueBy', $item)) : 0,
        ];
    }

    /**
     * @param array<string,mixed> $mail
     */
    private function messageXml(array $mail): string
    {
        $html = $mail['html'] ?? true;
        $body = (string) ($mail['body'] ?? '');
        if ($html) {
            $body = MailHtmlSanitizer::clean($body)['html'];
        }

        return '<t:Message>'
            . '<t:Subject>' . EwsXml::escape((string) ($mail['subject'] ?? '')) . '</t:Subject>'
            . '<t:Body BodyType="' . ($html ? 'HTML' : 'Text') . '">' . EwsXml::escape($body) . '</t:Body>'
            . '<t:Importance>' . self::importance((string) ($mail['importance'] ?? 'Normal')) . '</t:Importance>'
            . EwsXml::recipients('ToRecipients', $mail['to'] ?? [])
            . EwsXml::recipients('CcRecipients', $mail['cc'] ?? [])
            . EwsXml::recipients('BccRecipients', $mail['bcc'] ?? [])
            . '</t:Message>';
    }

    /**
     * Haengt Dateien an einen gespeicherten Entwurf an, liefert neuen ChangeKey.
     *
     * @param array{id:string,change_key:string} $item
     * @param list<array{name:string,content_type:string,content:string}> $attachments
     */
    private function addAttachments(string $user, array $item, array $attachments): string
    {
        $xml = '';
        foreach ($attachments as $attachment) {
            $xml .= '<t:FileAttachment><t:Name>' . EwsXml::escape($attachment['name']) . '</t:Name><t:ContentType>' . EwsXml::escape($attachment['content_type']) . '</t:ContentType>'
                . '<t:Content>' . base64_encode($attachment['content']) . '</t:Content></t:FileAttachment>';
        }
        $xpath = $this->call('<m:CreateAttachment><m:ParentItemId Id="' . EwsXml::escape($item['id']) . '"' . ($item['change_key'] !== '' ? ' ChangeKey="' . EwsXml::escape($item['change_key']) . '"' : '') . '/><m:Attachments>' . $xml . '</m:Attachments></m:CreateAttachment>', $user);
        $keys = EwsXml::elements($xpath, '//t:AttachmentId');
        $last = $keys !== [] ? $keys[count($keys) - 1]->getAttribute('RootItemChangeKey') : '';

        return $last !== '' ? $last : $item['change_key'];
    }

    /**
     * @param array<string,mixed> $event
     */
    private function assertEvent(array $event): void
    {
        if (trim((string) ($event['subject'] ?? '')) === '') {
            throw new OrvantaException('Bitte einen Betreff für den Termin angeben.', 422);
        }
        if ((int) ($event['start'] ?? 0) <= 0 || (int) ($event['end'] ?? 0) <= 0) {
            throw new OrvantaException('Bitte Beginn und Ende des Termins angeben.', 422);
        }
        if ((int) $event['end'] < (int) $event['start']) {
            throw new OrvantaException('Das Ende des Termins liegt vor dem Beginn.', 422);
        }
    }

    private static function freeBusy(string $value): string
    {
        return in_array($value, ['Free', 'Tentative', 'Busy', 'OOF', 'WorkingElsewhere', 'NoData'], true) ? $value : 'Busy';
    }

    private static function importance(string $value): string
    {
        return in_array($value, ['Low', 'Normal', 'High'], true) ? $value : 'Normal';
    }

    private static function taskStatus(string $value): string
    {
        return in_array($value, ['NotStarted', 'InProgress', 'Completed', 'WaitingOnOthers', 'Deferred'], true) ? $value : 'NotStarted';
    }

    private static function noteColor(string $value): string
    {
        return match ($value) {
            '0' => 'blue',
            '1' => 'green',
            '2' => 'pink',
            '4' => 'white',
            default => 'yellow',
        };
    }

    private static function noteSubject(string $body): string
    {
        $first = trim((string) strtok(trim($body), "\r\n"));
        if ($first === '') {
            return 'Notiz';
        }

        return mb_strlen($first) > 80 ? mb_substr($first, 0, 77) . '…' : $first;
    }
}
