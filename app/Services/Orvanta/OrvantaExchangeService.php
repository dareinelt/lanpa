<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\ExchangeTransportInterface;
use App\Contracts\OrvantaMailBackendInterface;
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
final class OrvantaExchangeService implements OrvantaMailBackendInterface
{
    public const MAIL_FOLDERS = ['inbox', 'drafts', 'sentitems', 'deleteditems', 'junkemail', 'outbox'];

    /** Kennungs-Praefix fuer Anhaenge aus dem Inhalt S/MIME-signierter Nachrichten. */
    public const SIGNED_ATTACHMENT_PREFIX = 'orvanta-signed:';

    private const MESSAGE_FIELDS = '<t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="item:DateTimeReceived"/><t:FieldURI FieldURI="item:DateTimeSent"/>'
        . '<t:FieldURI FieldURI="item:HasAttachments"/><t:FieldURI FieldURI="item:Size"/><t:FieldURI FieldURI="item:Importance"/><t:FieldURI FieldURI="item:ItemClass"/>'
        . '<t:FieldURI FieldURI="message:From"/><t:FieldURI FieldURI="message:IsRead"/><t:FieldURI FieldURI="message:ToRecipients"/><t:FieldURI FieldURI="item:Preview"/>'
        . '<t:FieldURI FieldURI="item:Categories"/><t:FieldURI FieldURI="item:Flag"/>';

    private const CALENDAR_FIELDS = '<t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="calendar:Start"/><t:FieldURI FieldURI="calendar:End"/>'
        . '<t:FieldURI FieldURI="calendar:IsAllDayEvent"/><t:FieldURI FieldURI="calendar:Location"/><t:FieldURI FieldURI="calendar:Organizer"/>'
        . '<t:FieldURI FieldURI="calendar:LegacyFreeBusyStatus"/><t:FieldURI FieldURI="calendar:CalendarItemType"/><t:FieldURI FieldURI="item:ReminderIsSet"/>'
        . '<t:FieldURI FieldURI="item:ReminderMinutesBeforeStart"/><t:FieldURI FieldURI="calendar:IsMeeting"/><t:FieldURI FieldURI="calendar:MyResponseType"/><t:FieldURI FieldURI="item:Categories"/>';

    /** @var array<string,array{primary:string,at:int}>|null */
    private ?array $primaryAddresses = null;

    /**
     * @param string|null $primaryCacheFile Datei fuer die gelernte Zuordnung
     *                                      Alias-Adresse -> primaere SMTP-Adresse
     * @param OrvantaExchangePool|null $pool Lastverteilung und Failover ueber die
     *                                      Hosts einer Exchange-DAG (null: nur
     *                                      der konfigurierte Server)
     */
    public function __construct(
        private readonly ExchangeTransportInterface $transport,
        private readonly OrvantaConfigService $config,
        private readonly ?string $primaryCacheFile = null,
        private readonly ?OrvantaExchangePool $pool = null
    ) {
    }

    public function backendName(): string
    {
        return 'exchange';
    }

    /**
     * Exchange stellt alle Orvanta-Module bereit (Standard-Backend).
     *
     * @return array<string,bool>
     */
    public function capabilities(): array
    {
        return [
            self::CAPABILITY_MAIL => true,
            self::CAPABILITY_CALENDAR => true,
            self::CAPABILITY_CONTACTS => true,
            self::CAPABILITY_TASKS => true,
            self::CAPABILITY_NOTES => true,
            self::CAPABILITY_REMINDERS => true,
            self::CAPABILITY_ARCHIVE => true,
        ];
    }

    // ------------------------------------------------------------------
    // Verbindung
    // ------------------------------------------------------------------

    /**
     * Prueft die Verbindung: Posteingang des Dienstkontos bzw. des Benutzers.
     * $url erzwingt einen bestimmten Endpunkt (Verbindungstest eines einzelnen
     * Hosts der DAG im Dashboard); ohne Angabe waehlt die Lastverteilung.
     *
     * @return array{ok:bool,message:string,server_version:string}
     */
    public function testConnection(string $impersonate = '', ?string $url = null): array
    {
        $xpath = $this->call(
            '<m:GetFolder><m:FolderShape><t:BaseShape>Default</t:BaseShape></m:FolderShape><m:FolderIds><t:DistinguishedFolderId Id="inbox"/></m:FolderIds></m:GetFolder>',
            $impersonate,
            true,
            false,
            $url
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

    /**
     * Belegung des Postfachs auf dem Exchange: Summe der Groessen
     * (PR_MESSAGE_SIZE_EXTENDED) des Stammordners und aller Unterordner
     * (PR_MESSAGE_SIZE_EXTENDED gilt je Ordner nur fuer dessen eigene
     * Elemente) ohne Suchordner und ohne „Wiederherstellbare Elemente“
     * (eigenes Kontingent, wie TotalItemSize von Get-MailboxStatistics),
     * dazu die vom Server gesetzten Grenzen „Warnung“
     * (PR_STORAGE_QUOTA_LIMIT), „Senden verbieten“ (PR_PROHIBIT_SEND_QUOTA)
     * und „Empfang verbieten“ (PR_PROHIBIT_RECEIVE_QUOTA), jeweils in Byte.
     * 0 = keine Grenze bekannt. Liefert Exchange keine Grenze (die
     * Quota-Eigenschaften des Postfachspeichers sind ueber EWS meist nicht
     * lesbar), gelten die aus dem AD gelesenen Grenzen $directory
     * (LdapClient::mailboxQuota()), danach die im Adminbereich eingetragene
     * Postfachgroesse (mailbox_quota_mb) als Grenze `limit`. `source` nennt
     * die Herkunft der Grenzen (exchange, directory, setting, leer).
     *
     * @param array{warning:int,send:int,receive:int}|null $directory
     *
     * @return array{used:int,quota:int,warning:int,receive_limit:int,limit:int,percent:int,source:string}
     */
    public function mailboxUsage(string $user, ?array $directory = null): array
    {
        $xpath = $this->call(
            '<m:GetFolder><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
            . '<t:ExtendedFieldURI PropertyTag="0x0E08" PropertyType="Long"/>'
            . '<t:ExtendedFieldURI PropertyTag="0x3FF5" PropertyType="Integer"/>'
            . '<t:ExtendedFieldURI PropertyTag="0x666E" PropertyType="Integer"/>'
            . '<t:ExtendedFieldURI PropertyTag="0x666A" PropertyType="Integer"/>'
            . '</t:AdditionalProperties></m:FolderShape><m:FolderIds><t:DistinguishedFolderId Id="root"/><t:DistinguishedFolderId Id="recoverableitemsroot"/></m:FolderIds></m:GetFolder>',
            $user,
            false
        );
        $messages = EwsXml::elements($xpath, '//m:GetFolderResponseMessage');
        $rootMessage = $messages[0] ?? null;
        if ($rootMessage === null || $rootMessage->getAttribute('ResponseClass') === 'Error') {
            throw new OrvantaException(self::translate(EwsXml::text($xpath, 'm:ResponseCode', $rootMessage) ?: 'ErrorFolderNotFound'), 502);
        }
        $values = [];
        foreach (EwsXml::elements($xpath, './/t:Folder/t:ExtendedProperty', $rootMessage) as $property) {
            $values[self::propertyTag($xpath, $property)] = max(0, (int) EwsXml::text($xpath, 't:Value', $property));
        }
        $recoverable = isset($messages[1]) && $messages[1]->getAttribute('ResponseClass') !== 'Error'
            ? EwsXml::attr($xpath, './/t:FolderId', 'Id', $messages[1])
            : '';
        $used = ($values[0x0E08] ?? 0) + $this->subfolderSize($user, $recoverable);
        // Quota-Werte liefert Exchange in Kilobyte
        $warning = ($values[0x3FF5] ?? 0) * 1024;
        $quota = ($values[0x666E] ?? 0) * 1024;
        $receive = ($values[0x666A] ?? 0) * 1024;
        $source = 'exchange';
        if ($quota === 0 && $receive === 0 && $warning === 0 && $directory !== null) {
            $warning = max(0, $directory['warning']);
            $quota = max(0, $directory['send']);
            $receive = max(0, $directory['receive']);
            $source = 'directory';
        }
        $limit = $quota > 0 ? $quota : ($receive > 0 ? $receive : $warning);
        if ($limit === 0) {
            $limit = $this->config->mailboxQuotaBytes();
            $source = $limit > 0 ? 'setting' : '';
        }

        return [
            'used' => $used,
            'quota' => $quota,
            'warning' => $warning,
            'receive_limit' => $receive,
            'limit' => $limit,
            'percent' => $limit > 0 ? (int) min(100, round($used * 100 / $limit)) : 0,
            'source' => $source,
        ];
    }

    /**
     * Summe von PR_MESSAGE_SIZE_EXTENDED aller Ordner unterhalb des
     * Stammordners (seitenweise), ohne Suchordner und ohne den Teilbaum
     * $excludedRoot (Wiederherstellbare Elemente).
     */
    private function subfolderSize(string $user, string $excludedRoot): int
    {
        $folders = [];
        $offset = 0;
        for ($page = 0; $page < 20; $page++) {
            $xpath = $this->call(
                '<m:FindFolder Traversal="Deep"><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
                . '<t:FieldURI FieldURI="folder:ParentFolderId"/><t:ExtendedFieldURI PropertyTag="0x0E08" PropertyType="Long"/>'
                . '</t:AdditionalProperties></m:FolderShape>'
                . '<m:IndexedPageFolderView MaxEntriesReturned="1000" Offset="' . $offset . '" BasePoint="Beginning"/>'
                . '<m:ParentFolderIds><t:DistinguishedFolderId Id="root"/></m:ParentFolderIds></m:FindFolder>',
                $user
            );
            $root = EwsXml::elements($xpath, '//m:RootFolder')[0] ?? null;
            if ($root === null) {
                break;
            }
            // Folder, CalendarFolder, ContactsFolder, TasksFolder – Suchordner
            // enthalten nur Verweise und werden nicht gezaehlt.
            $entries = EwsXml::elements($xpath, 't:Folders/*[local-name() != "SearchFolder"]', $root);
            foreach ($entries as $folder) {
                $size = 0;
                foreach (EwsXml::elements($xpath, 't:ExtendedProperty', $folder) as $property) {
                    if (self::propertyTag($xpath, $property) === 0x0E08) {
                        $size = max(0, (int) EwsXml::text($xpath, 't:Value', $property));
                    }
                }
                $id = EwsXml::attr($xpath, 't:FolderId', 'Id', $folder);
                $folders[$id !== '' ? $id : 'folder-' . count($folders)] = [
                    'parent' => EwsXml::attr($xpath, 't:ParentFolderId', 'Id', $folder),
                    'size' => $size,
                ];
            }
            $offset += count($entries);
            if ($entries === [] || $root->getAttribute('IncludesLastItemInRange') !== 'false') {
                break;
            }
        }

        $total = 0;
        foreach ($folders as $id => $folder) {
            if ($excludedRoot !== '' && $this->isWithin($folders, (string) $id, $excludedRoot)) {
                continue;
            }
            $total += $folder['size'];
        }

        return $total;
    }

    /**
     * @param array<string,array{parent:string,size:int}> $folders
     */
    private function isWithin(array $folders, string $id, string $ancestor): bool
    {
        for ($depth = 0; $depth < 64 && $id !== ''; $depth++) {
            if ($id === $ancestor) {
                return true;
            }
            $id = $folders[$id]['parent'] ?? '';
        }

        return false;
    }

    /**
     * Tag einer erweiterten Eigenschaft als Zahl; Exchange schreibt Tags
     * ohne fuehrende Nullen (z. B. "0xe08").
     */
    private static function propertyTag(DOMXPath $xpath, DOMElement $property): int
    {
        return (int) hexdec(ltrim(strtolower(EwsXml::attr($xpath, 't:ExtendedFieldURI', 'PropertyTag', $property)), 'x0'));
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

    /**
     * Neuen E-Mail-Ordner (IPF.Note) unterhalb von $parent anlegen
     * ('' = oberste Ebene des Postfachs).
     *
     * @return array{id:string,name:string}
     */
    public function createFolder(string $user, string $parent, string $name): array
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new OrvantaException('Bitte einen Ordnernamen angeben (höchstens 255 Zeichen).', 422);
        }
        $xpath = $this->call(
            '<m:CreateFolder><m:ParentFolderId>' . EwsXml::folderId($parent !== '' ? $parent : 'msgfolderroot') . '</m:ParentFolderId>'
            . '<m:Folders><t:Folder><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>' . EwsXml::escape($name) . '</t:DisplayName></t:Folder></m:Folders></m:CreateFolder>',
            $user
        );

        return ['id' => EwsXml::attr($xpath, '//m:Folders/*/t:FolderId', 'Id'), 'name' => $name];
    }

    /**
     * Alle Nachrichten eines Ordners als gelesen markieren (ohne Lesebestaetigungen).
     */
    public function markFolderRead(string $user, string $folder): void
    {
        // ReadFlag/SuppressReadReceipts sind laut EWS-Schema Kindelemente, keine
        // Attribute; als Attribute ignoriert Exchange sie und markiert als ungelesen.
        $this->call(
            '<m:MarkAllItemsAsRead><m:ReadFlag>true</m:ReadFlag><m:SuppressReadReceipts>true</m:SuppressReadReceipts>'
            . '<m:FolderIds>' . EwsXml::folderId($folder) . '</m:FolderIds></m:MarkAllItemsAsRead>',
            $user
        );
    }

    /**
     * Eigenschaften eines Ordners: Anzahl der Elemente und Groesse
     * (PR_MESSAGE_SIZE_EXTENDED), jeweils fuer den Ordner selbst und
     * zusammen mit allen Unterordnern (ohne Suchordner).
     *
     * @return array{id:string,name:string,total:int,unread:int,subfolders:int,size:int,total_with_subfolders:int,size_with_subfolders:int}
     */
    public function folderProperties(string $user, string $folder): array
    {
        $xpath = $this->call(
            '<m:GetFolder><m:FolderShape><t:BaseShape>Default</t:BaseShape><t:AdditionalProperties>'
            . '<t:ExtendedFieldURI PropertyTag="0x0E08" PropertyType="Long"/>'
            . '</t:AdditionalProperties></m:FolderShape><m:FolderIds>' . EwsXml::folderId($folder) . '</m:FolderIds></m:GetFolder>',
            $user
        );
        $node = EwsXml::elements($xpath, '//m:Folders/*')[0] ?? null;
        if ($node === null) {
            throw new OrvantaException('Der Ordner wurde nicht gefunden.', 404);
        }
        $result = [
            'id' => EwsXml::attr($xpath, 't:FolderId', 'Id', $node),
            'name' => EwsXml::text($xpath, 't:DisplayName', $node),
            'total' => (int) EwsXml::text($xpath, 't:TotalCount', $node),
            'unread' => (int) EwsXml::text($xpath, 't:UnreadCount', $node),
            'subfolders' => 0,
            'size' => self::extendedSize($xpath, $node),
            'total_with_subfolders' => 0,
            'size_with_subfolders' => 0,
        ];
        $result['total_with_subfolders'] = $result['total'];
        $result['size_with_subfolders'] = $result['size'];

        $xpath = $this->call(
            '<m:FindFolder Traversal="Deep"><m:FolderShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
            . '<t:FieldURI FieldURI="folder:TotalCount"/><t:ExtendedFieldURI PropertyTag="0x0E08" PropertyType="Long"/>'
            . '</t:AdditionalProperties></m:FolderShape>'
            . '<m:IndexedPageFolderView MaxEntriesReturned="1000" Offset="0" BasePoint="Beginning"/>'
            . '<m:ParentFolderIds>' . EwsXml::folderId($folder) . '</m:ParentFolderIds></m:FindFolder>',
            $user,
            false
        );
        foreach (EwsXml::elements($xpath, '//m:RootFolder/t:Folders/*[local-name() != "SearchFolder"]') as $child) {
            $result['subfolders']++;
            $result['total_with_subfolders'] += (int) EwsXml::text($xpath, 't:TotalCount', $child);
            $result['size_with_subfolders'] += self::extendedSize($xpath, $child);
        }

        return $result;
    }

    private static function extendedSize(DOMXPath $xpath, DOMElement $folder): int
    {
        foreach (EwsXml::elements($xpath, 't:ExtendedProperty', $folder) as $property) {
            if (self::propertyTag($xpath, $property) === 0x0E08) {
                return max(0, (int) EwsXml::text($xpath, 't:Value', $property));
            }
        }

        return 0;
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
        $data['signed'] = false;
        if (str_starts_with(strtolower((string) $data['item_class']), 'ipm.note.smime')) {
            $data = $this->unwrapSigned($user, $data);
        }

        return $data;
    }

    /**
     * S/MIME-signierte Nachricht wie eine normale E-Mail darstellen: Exchange
     * liefert dafuer nur einen leeren Text und die Originalnachricht als
     * Anhang (smime.p7m). Text und Anhaenge werden deshalb aus dem
     * MIME-Inhalt gelesen; die Signatur selbst wird nur als Kennzeichen
     * 'signed' gemeldet (keine kryptografische Pruefung). Verschluesselte
     * oder nicht lesbare Nachrichten bleiben unveraendert.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function unwrapSigned(string $user, array $data): array
    {
        try {
            $mime = $this->messageMime($user, (string) $data['id'])['mime'];
        } catch (OrvantaException) {
            return $data;
        }
        if ($mime === '') {
            return $data;
        }
        $parsed = (new MimeMessageParser())->parse($mime);
        if (!$parsed['signed']) {
            return $data;
        }
        if ($parsed['html'] !== '') {
            $clean = MailHtmlSanitizer::clean($parsed['html']);
            $data['body_html'] = $clean['html'];
            $data['blocked_images'] = $clean['blocked_images'];
        } else {
            $data['body_html'] = nl2br(htmlspecialchars($parsed['text'], ENT_QUOTES, 'UTF-8'));
            $data['blocked_images'] = 0;
        }
        $attachments = [];
        foreach ($parsed['attachments'] as $index => $attachment) {
            $attachments[] = [
                'id' => self::SIGNED_ATTACHMENT_PREFIX . $index . ':' . $data['id'],
                'name' => $attachment['name'],
                'content_type' => $attachment['content_type'],
                'content_id' => $attachment['content_id'],
                'size' => strlen($attachment['content']),
                'inline' => $attachment['inline'],
                'is_item' => false,
            ];
        }
        $data['attachments'] = $attachments;
        $data['has_attachments'] = array_filter($attachments, static fn (array $a): bool => !$a['inline']) !== [];
        $data['signed'] = true;

        return $data;
    }

    /**
     * Anhang aus dem signierten Inhalt einer S/MIME-Nachricht
     * (Kennung SIGNED_ATTACHMENT_PREFIX . Index . ':' . ItemId).
     *
     * @return array{name:string,content_type:string,content:string,size:int}
     */
    private function signedAttachment(string $user, string $attachmentId): array
    {
        $parts = explode(':', substr($attachmentId, strlen(self::SIGNED_ATTACHMENT_PREFIX)), 2);
        $itemId = $parts[1] ?? '';
        if ($itemId === '' || !ctype_digit($parts[0])) {
            throw new OrvantaException('Der Anhang wurde nicht gefunden.', 404);
        }
        $attachment = (new MimeMessageParser())->parse($this->messageMime($user, $itemId)['mime'])['attachments'][(int) $parts[0]] ?? null;
        if ($attachment === null) {
            throw new OrvantaException('Der Anhang wurde nicht gefunden.', 404);
        }

        return [
            'name' => $attachment['name'],
            'content_type' => $attachment['content_type'],
            'content' => $attachment['content'],
            'size' => strlen($attachment['content']),
        ];
    }

    /**
     * Unveraenderter Internet-Kopf (RFC 5322) einer Nachricht. Bevorzugt den
     * Kopfblock des MIME-Inhalts; fehlt dieser, werden die von Exchange
     * zerlegten InternetMessageHeaders wieder zu Kopfzeilen zusammengesetzt.
     *
     * @return array{id:string,subject:string,headers:string,source:string}
     */
    public function messageHeaders(string $user, string $id): array
    {
        $xpath = $this->call(
            '<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
            . '<t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="item:MimeContent"/><t:FieldURI FieldURI="item:InternetMessageHeaders"/>'
            . '</t:AdditionalProperties></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>',
            $user
        );
        $item = EwsXml::elements($xpath, '//m:Items/*')[0] ?? null;
        if ($item === null) {
            throw new OrvantaException('Die Nachricht wurde nicht gefunden.', 404);
        }
        $headers = self::mimeHeaderBlock(EwsXml::text($xpath, 't:MimeContent', $item));
        $source = 'mime';
        if ($headers === '') {
            $source = 'exchange';
            foreach (EwsXml::elements($xpath, 't:InternetMessageHeaders/t:InternetMessageHeader', $item) as $header) {
                $name = trim($header->getAttribute('HeaderName'));
                if ($name !== '') {
                    $headers .= $name . ': ' . trim($header->textContent) . "\r\n";
                }
            }
        }
        if ($headers === '') {
            throw new OrvantaException('Für diese Nachricht liegen keine Kopfzeilen vor.', 404);
        }

        return ['id' => $id, 'subject' => EwsXml::text($xpath, 't:Subject', $item), 'headers' => rtrim($headers), 'source' => $source];
    }

    /**
     * Kopfblock (bis zur ersten Leerzeile) aus Base64-kodiertem MIME-Inhalt.
     */
    private static function mimeHeaderBlock(string $base64): string
    {
        $mime = base64_decode(trim($base64), true);
        if ($mime === false || $mime === '') {
            return '';
        }
        $parts = preg_split('/\r?\n\r?\n/', ltrim($mime, "\r\n"), 2) ?: [];
        $block = $parts[0] ?? '';
        if (!preg_match('/^[!-9;-~]+:/', $block)) {
            return '';
        }
        if (!mb_check_encoding($block, 'UTF-8')) {
            $block = mb_convert_encoding($block, 'UTF-8', 'ISO-8859-1');
        }

        return $block;
    }

    /**
     * Archivierungs-Kandidaten eines Ordners: Nachrichten, deren Empfangs-
     * datum vor $before (Unix-Zeit) liegt, aelteste zuerst. Fuer das
     * Langzeitarchiv wird zusaetzlich die InternetMessageId geliefert
     * (dauerhafte Identitaet, unabhaengig vom veraenderlichen ChangeKey).
     *
     * @return array{items:list<array<string,mixed>>,total:int,has_more:bool}
     */
    public function archiveCandidates(string $user, string $folder, int $before, int $offset = 0, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $xpath = $this->call(
            '<m:FindItem Traversal="Shallow"><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>' . self::MESSAGE_FIELDS
            . '<t:FieldURI FieldURI="message:InternetMessageId"/></t:AdditionalProperties></m:ItemShape>'
            . '<m:IndexedPageItemView MaxEntriesReturned="' . $limit . '" Offset="' . max(0, $offset) . '" BasePoint="Beginning"/>'
            . '<m:Restriction><t:IsLessThanOrEqualTo><t:FieldURI FieldURI="item:DateTimeReceived"/>'
            . '<t:FieldURIOrConstant><t:Constant Value="' . EwsXml::dateTime($before) . '"/></t:FieldURIOrConstant></t:IsLessThanOrEqualTo></m:Restriction>'
            . '<m:SortOrder><t:FieldOrder Order="Ascending"><t:FieldURI FieldURI="item:DateTimeReceived"/></t:FieldOrder></m:SortOrder>'
            . '<m:ParentFolderIds>' . EwsXml::folderId($folder) . '</m:ParentFolderIds></m:FindItem>',
            $user
        );
        $root = EwsXml::elements($xpath, '//m:RootFolder')[0] ?? null;
        $items = [];
        foreach (EwsXml::elements($xpath, '//t:Items/*') as $item) {
            $summary = $this->messageSummary($xpath, $item);
            $summary['internet_message_id'] = EwsXml::text($xpath, 't:InternetMessageId', $item);
            $items[] = $summary;
        }

        return [
            'items' => $items,
            'total' => $root !== null ? (int) $root->getAttribute('TotalItemsInView') : count($items),
            'has_more' => $root !== null && $root->getAttribute('IncludesLastItemInRange') === 'false',
        ];
    }

    /**
     * Unveraenderter MIME-Inhalt (RFC 5322) einer Nachricht fuer das
     * Langzeitarchiv, inklusive der dauerhaften InternetMessageId zur
     * Identitaetspruefung vor der Loeschung.
     *
     * @return array{id:string,change_key:string,mime:string,internet_message_id:string,subject:string}
     */
    public function messageMime(string $user, string $id): array
    {
        $xpath = $this->call(
            '<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:IncludeMimeContent>true</t:IncludeMimeContent><t:AdditionalProperties>'
            . '<t:FieldURI FieldURI="item:Subject"/><t:FieldURI FieldURI="message:InternetMessageId"/>'
            . '</t:AdditionalProperties></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>',
            $user
        );
        $item = EwsXml::elements($xpath, '//m:Items/*')[0] ?? null;
        if ($item === null) {
            throw new OrvantaException('Die Nachricht wurde nicht gefunden.', 404);
        }
        $itemId = EwsXml::itemId($xpath, $item);
        $mime = base64_decode(trim(EwsXml::text($xpath, 't:MimeContent', $item)), true);

        return [
            'id' => $itemId['id'],
            'change_key' => $itemId['change_key'],
            'mime' => $mime === false ? '' : $mime,
            'internet_message_id' => EwsXml::text($xpath, 't:InternetMessageId', $item),
            'subject' => EwsXml::text($xpath, 't:Subject', $item),
        ];
    }

    /**
     * Dauerhafte Identitaet einer Nachricht (InternetMessageId) kurz vor der
     * Loeschung erneut abrufen. Liefert null, wenn die Nachricht nicht mehr
     * existiert (ErrorItemNotFound) - dann ist nichts mehr zu loeschen.
     *
     * @return array{id:string,internet_message_id:string}|null
     */
    public function messageIdentity(string $user, string $id): ?array
    {
        try {
            $xpath = $this->call(
                '<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape><t:AdditionalProperties>'
                . '<t:FieldURI FieldURI="message:InternetMessageId"/></t:AdditionalProperties></m:ItemShape>'
                . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>',
                $user
            );
        } catch (OrvantaException $exception) {
            if ($exception->getCode() === 404 || str_contains($exception->getMessage(), 'nicht gefunden')) {
                return null;
            }
            throw $exception;
        }
        $item = EwsXml::elements($xpath, '//m:Items/*')[0] ?? null;
        if ($item === null) {
            return null;
        }
        $itemId = EwsXml::itemId($xpath, $item);

        return ['id' => $itemId['id'], 'internet_message_id' => EwsXml::text($xpath, 't:InternetMessageId', $item)];
    }

    /**
     * Neue E-Mail senden (mit Kopie in „Gesendete Elemente“). Mit $draftId wird
     * ein vorhandener Entwurf aktualisiert und anschliessend gesendet; mit
     * $mail['reference'] (id, mode) entsteht eine Antwort/Weiterleitung mit
     * Bezug zur Originalnachricht.
     *
     * @param array{to?:list<string>,cc?:list<string>,bcc?:list<string>,subject?:string,body?:string,html?:bool,importance?:string,attachments?:list<array{name:string,content_type:string,content:string}>,reference?:array{id:string,mode:string,change_key?:string}} $mail
     * @return array{id:string}
     */
    public function send(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        $reference = $this->reference($user, $mail);
        if ($reference !== null) {
            $mail['reference'] = $reference;
        }
        $hasRecipients = ($mail['to'] ?? []) !== [] || ($mail['cc'] ?? []) !== [] || ($mail['bcc'] ?? []) !== [];
        if (!$hasRecipients && ($reference === null || $reference['mode'] === 'forward')) {
            throw new OrvantaException($reference !== null ? 'Bitte einen Empfänger für die Weiterleitung angeben.' : 'Bitte mindestens einen Empfänger angeben.', 422);
        }
        if ($draftId === '' && ($mail['attachments'] ?? []) === []) {
            $xpath = $this->call('<m:CreateItem MessageDisposition="SendAndSaveCopy"><m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId><m:Items>' . $this->outgoingXml($mail, $reference) . '</m:Items></m:CreateItem>', $user);

            return ['id' => EwsXml::attr($xpath, '//m:Items/t:Message/t:ItemId', 'Id')];
        }

        $draft = $this->saveDraft($user, $mail, $draftId, $changeKey);
        $this->call(
            '<m:SendItem SaveItemToFolder="true">' . EwsXml::itemIds([$draft])
            . '<m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId></m:SendItem>',
            $user
        );

        return ['id' => $draft['id']];
    }

    /**
     * Entwurf speichern: ohne $draftId wird ein neuer Entwurf angelegt (bei
     * $mail['reference'] als Antwort/Weiterleitung mit Bezug), sonst der
     * vorhandene aktualisiert. Neue Anhaenge werden angehaengt.
     *
     * @param array<string,mixed> $mail
     * @return array{id:string,change_key:string}
     */
    public function saveDraft(string $user, array $mail, string $draftId = '', string $changeKey = ''): array
    {
        if ($draftId === '') {
            $xpath = $this->call('<m:CreateItem MessageDisposition="SaveOnly"><m:SavedItemFolderId><t:DistinguishedFolderId Id="drafts"/></m:SavedItemFolderId><m:Items>' . $this->outgoingXml($mail, $this->reference($user, $mail)) . '</m:Items></m:CreateItem>', $user);
            $node = EwsXml::elements($xpath, '//m:Items/t:Message')[0] ?? null;
            $item = $node !== null ? EwsXml::itemId($xpath, $node) : ['id' => '', 'change_key' => ''];
            if ($item['id'] === '') {
                throw new OrvantaException('Exchange hat den Entwurf nicht angelegt.', 502);
            }
        } else {
            $item = $this->updateDraft($user, $draftId, $changeKey, $mail);
        }
        $attachments = $mail['attachments'] ?? [];
        if ($attachments !== []) {
            $item['change_key'] = $this->addAttachments($user, $item, $attachments);
        }

        return $item;
    }

    /**
     * Antwort oder Weiterleitung auf eine vorhandene Nachricht senden.
     *
     * @param 'reply'|'replyall'|'forward' $mode
     * @param array<string,mixed> $mail to, cc, bcc, subject, body, html, attachments
     * @return array{id:string}
     */
    public function respond(string $user, string $id, string $mode, array $mail): array
    {
        return $this->send($user, ['reference' => ['id' => $id, 'mode' => $mode]] + $mail);
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
        if (str_starts_with($attachmentId, self::SIGNED_ATTACHMENT_PREFIX)) {
            return $this->signedAttachment($user, $attachmentId);
        }
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
            . '<t:Body BodyType="HTML">' . EwsXml::escape($this->eventBody((string) ($event['body'] ?? ''))) . '</t:Body>'
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
            $updates .= $set('item:Body', '<t:Body BodyType="HTML">' . EwsXml::escape($this->eventBody((string) $event['body'])) . '</t:Body>');
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
        $this->call('<m:CreateItem MessageDisposition="SendAndSaveCopy"><m:Items><t:' . $element . '>' . $this->referenceItemIdXml($id, $this->currentChangeKey($user, $id)) . '</t:' . $element . '></m:Items></m:CreateItem>', $user);
    }

    /**
     * Anstehende Termine fuer den Erinnerungsdienst (mit und ohne eigene
     * Exchange-Erinnerung; remind_at = 0, wenn keine gesetzt ist).
     *
     * @return list<array{id:string,subject:string,location:string,start:int,end:int,reminder_set:bool,remind_at:int}>
     */
    public function upcomingReminders(string $user, int $from, int $hours = 48): array
    {
        $result = [];
        foreach ($this->calendar($user, $from - 3600, $from + $hours * 3600) as $event) {
            $result[] = [
                'id' => $event['id'],
                'subject' => $event['subject'],
                'location' => $event['location'],
                'start' => $event['start'],
                'end' => $event['end'],
                'reminder_set' => (bool) $event['reminder_set'],
                'remind_at' => $event['reminder_set'] ? $event['start'] - $event['reminder_minutes'] * 60 : 0,
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
     * (z. B. nicht vorhandene Systemordner). $url erzwingt einen bestimmten
     * Endpunkt (Verbindungstest eines einzelnen DAG-Hosts) und umgeht damit die
     * Lastverteilung.
     */
    private function call(string $body, string $impersonate, bool $strict = true, bool $retried = false, ?string $url = null): DOMXPath
    {
        $configured = $this->config->ewsUrl();
        if ($url === null && $configured === '') {
            throw new OrvantaException('Es ist kein Exchange-Server konfiguriert. Bitte im Adminbereich unter Office → Orvanta eintragen.', 503);
        }
        $impersonate = $this->primaryAddress($impersonate);
        $xml = EwsXml::envelope($body, $this->config->get('exchange_version'), $impersonate);
        $response = $url === null
            ? $this->request($xml, $impersonate, $configured, self::isReadOnly($body))
            : $this->transport->post($url, $xml, $this->config->transportOptions());
        if (($response['error'] ?? '') !== '') {
            throw new OrvantaException('Exchange ist nicht erreichbar: ' . $response['error'], 502);
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new OrvantaException($this->authFailure($response['status'], $response['auth_offered'] ?? []), 502);
        }
        $xpath = EwsXml::parse($response['body']);
        if ($xpath === null) {
            throw new OrvantaException('Exchange hat eine ungültige Antwort geliefert (HTTP ' . $response['status'] . ').', 502);
        }
        $error = EwsXml::error($xpath);
        // Die AD-Adresse ist nur ein Alias des Postfachs: Exchange nennt die
        // primaere Adresse, mit der die Anfrage einmalig wiederholt wird.
        if ($error !== null && !$retried && $impersonate !== '' && str_contains($error, 'ErrorNonPrimarySmtpAddress')) {
            $primary = EwsXml::primarySmtpAddress($xpath);
            if ($primary !== '' && strcasecmp($primary, $impersonate) !== 0 && filter_var($primary, FILTER_VALIDATE_EMAIL) !== false) {
                $this->rememberPrimaryAddress($impersonate, $primary);

                return $this->call($body, $primary, $strict, true);
            }
        }
        if ($error !== null && ($strict || str_contains($response['body'], 'soap:Fault'))) {
            $all = EwsXml::elements($xpath, '//m:ResponseMessages/*');
            $failed = EwsXml::elements($xpath, '//m:ResponseMessages/*[@ResponseClass="Error"]');
            if ($strict || $all === [] || count($failed) === count($all)) {
                throw new OrvantaException(self::translate($error), 502);
            }
        }

        return $xpath;
    }

    /**
     * Uebergibt die SOAP-Anfrage an einen Host der Exchange-DAG. Faellt der
     * gewaehlte Host aus, wird die Sitzung ohne Zutun des Benutzers auf den
     * naechsten Host umgeleitet. Lesende Anfragen werden dort wiederholt;
     * aendernde (Senden, Anlegen, Verschieben, Loeschen …) nur, wenn sie den
     * ausgefallenen Host nachweislich nie erreicht haben – sonst koennte z. B.
     * eine Mail doppelt versendet werden. Ohne Lastverteilung (kein Pool) bzw.
     * ohne eingetragenen Host geht die Anfrage an den konfigurierten Server.
     *
     * @return array{status:int,body:string,error:?string,auth_offered?:list<string>,request_sent?:bool}
     */
    private function request(string $xml, string $impersonate, string $configuredUrl, bool $retryable): array
    {
        $options = $this->config->transportOptions();
        if ($this->pool === null) {
            return $this->transport->post($configuredUrl, $xml, $options);
        }

        $key = $this->pool->sessionKey();
        $host = $this->pool->session($key, $impersonate);
        if ($host === null) {
            return $this->transport->post($configuredUrl, $xml, $options);
        }
        $tried = [];
        while (true) {
            $tried[] = (string) $host['host'];
            $started = microtime(true);
            $response = $this->transport->post(self::hostUrl($host), $xml, $options);
            $duration = (int) round((microtime(true) - $started) * 1000);
            if (!self::hostFailed($response)) {
                $this->pool->recordSuccess($host, $duration);

                return $response;
            }
            $this->pool->recordFailure($host, (string) (($response['error'] ?? '') !== '' ? $response['error'] : ('HTTP ' . $response['status'])));
            // Die Zuordnung wechselt in jedem Fall, damit der naechste Aufruf
            // nicht erneut am gestoerten Host haengt.
            $next = $this->pool->failover($key, $tried);
            if ($next === null || (!$retryable && self::requestSent($response))) {
                return $response;
            }
            $host = $next;
        }
    }

    /**
     * Rein lesende EWS-Operationen duerfen nach einem Host-Ausfall gefahrlos
     * auf einem anderen Host wiederholt werden. Alles andere gilt als aendernd.
     */
    private static function isReadOnly(string $body): bool
    {
        if (preg_match('/^\s*<m:([A-Za-z]+)/', $body, $match) !== 1) {
            return false;
        }

        return in_array($match[1], self::READ_ONLY_OPERATIONS, true);
    }

    /**
     * Hat die Anfrage den Host erreicht? Der Transport meldet das ueber
     * `request_sent`; ohne Angabe gilt jede HTTP-Antwort als zugestellt.
     *
     * @param array{status:int,body:string,error:?string,auth_offered?:list<string>,request_sent?:bool} $response
     */
    private static function requestSent(array $response): bool
    {
        return isset($response['request_sent']) ? (bool) $response['request_sent'] : (int) $response['status'] > 0;
    }

    /** Lesende EWS-Operationen (Wiederholung auf einem anderen DAG-Host erlaubt). */
    private const READ_ONLY_OPERATIONS = [
        'GetFolder', 'FindFolder', 'FindItem', 'GetItem', 'GetAttachment', 'ResolveNames',
        'GetUserAvailability', 'ConvertId', 'GetServerTimeZones', 'GetUserOofSettings',
        'SyncFolderItems', 'SyncFolderHierarchy', 'GetInboxRules', 'ExpandDL', 'GetMailTips',
    ];

    /**
     * EWS-Fehlercodes, die einen gestoerten Host bedeuten (Postfachdatenbank
     * nicht bereit, Server ueberlastet). Andere SOAP-Fehler – Exchange liefert
     * sie mit HTTP 500 – betreffen die Anfrage oder das Postfach und waeren auf
     * jedem Host der DAG gleich.
     */
    private const HOST_ERROR_CODES = [
        'ErrorServerBusy', 'ErrorMailboxStoreUnavailable', 'ErrorConnectionFailed',
        'ErrorInternalServerTransientError', 'ErrorMailboxMoveInProgress',
    ];

    /**
     * Host-Ausfall: Verbindungsfehler, Zeitueberschreitung oder Serverfehler
     * (HTTP 5xx ohne auswertbare EWS-Antwort bzw. mit einem Fehlercode aus
     * HOST_ERROR_CODES). Eine abgelehnte Anmeldung (HTTP 401/403) und
     * fachliche SOAP-Fehler (z. B. fehlendes Postfach, verweigerte
     * Impersonation) sind kein Host-Ausfall: sie betreffen alle Mitglieder der
     * DAG gleich.
     *
     * @param array{status:int,body:string,error:?string,auth_offered?:list<string>,request_sent?:bool} $response
     */
    private static function hostFailed(array $response): bool
    {
        $status = (int) $response['status'];
        if (($response['error'] ?? '') !== '' || $status === 0) {
            return true;
        }
        if ($status < 500) {
            return false;
        }
        $xpath = EwsXml::parse($response['body']);
        $error = $xpath !== null ? EwsXml::error($xpath) : null;
        if ($error === null) {
            return true;
        }
        foreach (self::HOST_ERROR_CODES as $code) {
            if (str_contains($error, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $host
     */
    private static function hostUrl(array $host): string
    {
        $url = trim((string) ($host['ews_url'] ?? ''));

        return $url !== '' ? $url : 'https://' . (string) $host['host'] . '/EWS/Exchange.asmx';
    }

    /**
     * Verbindungstest zu einem einzelnen Host der DAG (Dashboard). Der Aufruf
     * umgeht die Sitzungsaffinitaet und veraendert die Zuordnung der
     * Benutzersitzungen nicht; die gemessene Antwortzeit fliesst in die
     * Lastverteilung ein.
     *
     * @param array<string,mixed> $host Zeile aus orvanta_exchange_hosts
     *
     * @return array{ok:bool,message:string,server_version:string,latency_ms:int}
     */
    public function testHost(array $host, string $impersonate = ''): array
    {
        $name = (string) $host['host'];
        $started = microtime(true);
        try {
            $result = $this->testConnection($impersonate, self::hostUrl($host));
        } catch (OrvantaException $exception) {
            throw new OrvantaException('Der Host „' . $name . '“: ' . $exception->getMessage(), $exception->getCode() ?: 502);
        }
        $result['message'] = 'Der Host „' . $name . '“: ' . $result['message'];
        $result['latency_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /** Gelernte Zuordnungen gelten einen Tag; danach wird erneut bei Exchange nachgefragt. */
    private const PRIMARY_TTL = 86400;

    private function primaryAddress(string $address): string
    {
        if ($address === '') {
            return '';
        }
        $entry = $this->primaryAddresses()[strtolower($address)] ?? null;

        return $entry !== null && $entry['at'] > time() - self::PRIMARY_TTL ? $entry['primary'] : $address;
    }

    private function rememberPrimaryAddress(string $address, string $primary): void
    {
        $map = array_filter(
            $this->primaryAddresses(),
            static fn (array $entry): bool => $entry['at'] > time() - self::PRIMARY_TTL
        );
        $map[strtolower($address)] = ['primary' => $primary, 'at' => time()];
        $this->primaryAddresses = $map;
        if ($this->primaryCacheFile === null) {
            return;
        }
        $dir = dirname($this->primaryCacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($this->primaryCacheFile, (string) json_encode($map), LOCK_EX);
    }

    /**
     * @return array<string,array{primary:string,at:int}>
     */
    private function primaryAddresses(): array
    {
        if ($this->primaryAddresses !== null) {
            return $this->primaryAddresses;
        }
        $map = [];
        if ($this->primaryCacheFile !== null && is_file($this->primaryCacheFile)) {
            $data = json_decode((string) @file_get_contents($this->primaryCacheFile), true);
            foreach (is_array($data) ? $data : [] as $key => $entry) {
                if (is_string($key) && is_array($entry) && is_string($entry['primary'] ?? null) && is_int($entry['at'] ?? null)) {
                    $map[$key] = ['primary' => $entry['primary'], 'at' => $entry['at']];
                }
            }
        }

        return $this->primaryAddresses = $map;
    }

    /**
     * Verstaendliche Meldung zu HTTP 401/403 mit der wahrscheinlichen Ursache.
     *
     * @param list<string> $offered vom Server angebotene Verfahren (WWW-Authenticate)
     */
    private function authFailure(int $status, array $offered): string
    {
        $prefix = 'Exchange hat die Anmeldung abgelehnt (HTTP ' . $status . '). ';
        $options = $this->config->transportOptions();
        if ($status === 403) {
            return $prefix . 'Das Dienstkonto ist angemeldet, darf EWS aber nicht verwenden (z. B. EWS-Zugriffsrichtlinie oder SSL-Pflicht des EWS-Verzeichnisses). Bitte Anmeldeverfahren und Dienstkonto prüfen.';
        }
        if ($options['username'] === '') {
            return $prefix . 'Es ist kein Dienstkonto hinterlegt; der Intranet-Server besitzt keine eigene Kerberos-Identität. Bitte unter Office → Orvanta Dienstkonto und Kennwort eintragen.';
        }
        if ($options['password'] === '') {
            return $prefix . 'Für das Dienstkonto „' . $options['username'] . '“ ist kein Kennwort gespeichert. Bitte das Kennwort unter Office → Orvanta eintragen.';
        }
        $method = match ($options['auth']) {
            'basic' => 'Basic',
            default => 'NTLM',
        };
        $hint = 'Bitte Kennwort und Schreibweise des Dienstkontos „' . $options['username'] . '“ (FIRMA\\konto oder konto@firma.local) prüfen sowie, ob das Konto gesperrt oder abgelaufen ist.';
        if ($offered !== []) {
            $hint .= ' Der Server bietet: ' . implode(', ', $offered) . '.';
            if (!in_array(strtolower($method), array_map('strtolower', $offered), true)) {
                $hint .= ' Das verwendete Verfahren ' . $method . ' ist am EWS-Verzeichnis nicht aktiviert – bitte Anmeldeverfahren anpassen oder in Exchange freischalten.';
            }
        }

        return $prefix . $hint;
    }

    private static function translate(string $error): string
    {
        return match (true) {
            str_contains($error, 'ErrorImpersonateUserDenied'), str_contains($error, 'ErrorImpersonationDenied') => 'Dem Dienstkonto fehlt die Berechtigung „ApplicationImpersonation“ für dieses Postfach.',
            str_contains($error, 'ErrorNonExistentMailbox') => 'Für die SSO-Identität wurde kein Exchange-Postfach gefunden. Die verwendete Adresse muss die primäre SMTP-Adresse des Postfachs sein (Active Directory, Attribut „proxyAddresses“, Eintrag mit „SMTP:“); Anmeldename oder Attribut „mail“ funktionieren nur, wenn Exchange sie als Alias kennt.',
            str_contains($error, 'ErrorNonPrimarySmtpAddress') => 'Die E-Mail-Adresse aus dem Active Directory ist nicht die primäre SMTP-Adresse des Postfachs, und Exchange hat keine primäre Adresse genannt. Bitte im Active Directory das Attribut „mail“ auf die primäre Adresse setzen.',
            str_contains($error, 'ErrorItemNotFound') => 'Das Element wurde nicht gefunden (möglicherweise bereits verschoben oder gelöscht).',
            str_contains($error, 'ErrorFolderNotFound') => 'Der Ordner wurde nicht gefunden.',
            str_contains($error, 'ErrorFolderExists') => 'Ein Ordner mit diesem Namen ist hier bereits vorhanden.',
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
     * @return list<array{id:string,name:string,content_type:string,content_id:string,size:int,inline:bool,is_item:bool}>
     */
    private function attachmentList(DOMXPath $xpath, DOMElement $item): array
    {
        $list = [];
        foreach (EwsXml::elements($xpath, 't:Attachments/*', $item) as $attachment) {
            $list[] = [
                'id' => EwsXml::attr($xpath, 't:AttachmentId', 'Id', $attachment),
                'name' => EwsXml::text($xpath, 't:Name', $attachment),
                'content_type' => EwsXml::text($xpath, 't:ContentType', $attachment),
                'content_id' => trim(EwsXml::text($xpath, 't:ContentId', $attachment), '<>'),
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
        return '<t:Message>' . $this->messageFieldsXml($mail) . '</t:Message>';
    }

    /**
     * Antwort-/Weiterleitungsobjekt (ReplyToItem, ReplyAllToItem, ForwardItem).
     * Reihenfolge laut Schema: Item-Felder, Empfaenger, ReferenceItemId,
     * NewBodyContent. Der Text wandert in NewBodyContent, Exchange haengt die
     * Originalnachricht an und setzt den Bezug (Konversation, In-Reply-To).
     *
     * @param array<string,mixed> $mail
     * @param array{id:string,mode:string,change_key?:string} $reference
     */
    private function responseXml(array $mail, array $reference): string
    {
        $element = match ($reference['mode']) {
            'reply' => 'ReplyToItem',
            'replyall' => 'ReplyAllToItem',
            'forward' => 'ForwardItem',
            default => throw new OrvantaException('Unbekannte Antwortart.', 422),
        };
        $html = $mail['html'] ?? true;
        $subject = trim((string) ($mail['subject'] ?? ''));

        return '<t:' . $element . '>'
            . ($subject !== '' ? '<t:Subject>' . EwsXml::escape($subject) . '</t:Subject>' : '')
            . EwsXml::recipients('ToRecipients', $mail['to'] ?? [])
            . EwsXml::recipients('CcRecipients', $mail['cc'] ?? [])
            . EwsXml::recipients('BccRecipients', $mail['bcc'] ?? [])
            . $this->referenceItemIdXml($reference['id'], $reference['change_key'] ?? '')
            . '<t:NewBodyContent BodyType="' . ($html ? 'HTML' : 'Text') . '">' . EwsXml::escape($this->outgoingBody($mail)) . '</t:NewBodyContent>'
            . '</t:' . $element . '>';
    }

    /**
     * @param array<string,mixed> $mail
     * @param array{id:string,mode:string,change_key?:string}|null $reference
     */
    private function outgoingXml(array $mail, ?array $reference): string
    {
        return $reference !== null ? $this->responseXml($mail, $reference) : $this->messageXml($mail);
    }

    /**
     * @param array<string,mixed> $mail
     * @return array{id:string,mode:string,change_key?:string}|null
     */
    private function reference(string $user, array $mail): ?array
    {
        $reference = $mail['reference'] ?? null;
        if (!is_array($reference) || trim((string) ($reference['id'] ?? '')) === '') {
            return null;
        }
        $id = (string) $reference['id'];
        $changeKey = (string) ($reference['change_key'] ?? '');

        return [
            'id' => $id,
            'mode' => (string) ($reference['mode'] ?? 'reply'),
            'change_key' => $changeKey !== '' ? $changeKey : $this->currentChangeKey($user, $id),
        ];
    }

    /**
     * Aktueller ChangeKey eines Elements. Exchange verlangt ihn bei
     * ReferenceItemId (Antworten, Weiterleiten, Besprechungsantworten);
     * er aendert sich z. B. schon durch das Lesen, daher frisch abfragen.
     */
    private function currentChangeKey(string $user, string $id): string
    {
        $xpath = $this->call('<m:GetItem><m:ItemShape><t:BaseShape>IdOnly</t:BaseShape></m:ItemShape>' . EwsXml::itemIds([['id' => $id]]) . '</m:GetItem>', $user);

        return EwsXml::attr($xpath, '//m:Items/*/t:ItemId', 'ChangeKey');
    }

    private function referenceItemIdXml(string $id, string $changeKey): string
    {
        return '<t:ReferenceItemId Id="' . EwsXml::escape($id) . '"' . ($changeKey !== '' ? ' ChangeKey="' . EwsXml::escape($changeKey) . '"' : '') . '/>';
    }

    /**
     * Kindelemente einer Message in Schema-Reihenfolge.
     *
     * @param array<string,mixed> $mail
     */
    private function messageFieldsXml(array $mail): string
    {
        $html = $mail['html'] ?? true;

        return '<t:Subject>' . EwsXml::escape((string) ($mail['subject'] ?? '')) . '</t:Subject>'
            . '<t:Body BodyType="' . ($html ? 'HTML' : 'Text') . '">' . EwsXml::escape($this->outgoingBody($mail)) . '</t:Body>'
            . '<t:Importance>' . self::importance((string) ($mail['importance'] ?? 'Normal')) . '</t:Importance>'
            . EwsXml::recipients('ToRecipients', $mail['to'] ?? [])
            . EwsXml::recipients('CcRecipients', $mail['cc'] ?? [])
            . EwsXml::recipients('BccRecipients', $mail['bcc'] ?? []);
    }

    /**
     * @param array<string,mixed> $mail
     */
    private function outgoingBody(array $mail): string
    {
        // KI-Marker des Editors duerfen den Empfaenger nie erreichen.
        $body = OrvantaAiService::stripMarkers((string) ($mail['body'] ?? ''));

        return ($mail['html'] ?? true) ? MailHtmlSanitizer::clean($body, false)['html'] : $body;
    }

    /**
     * Terminbeschreibung fuer EWS: KI-Marker entfernen, dann bereinigen.
     */
    private function eventBody(string $body): string
    {
        return MailHtmlSanitizer::clean(OrvantaAiService::stripMarkers($body), false)['html'];
    }

    /**
     * Vorhandenen Entwurf mit den Formularwerten ueberschreiben.
     *
     * @param array<string,mixed> $mail
     * @return array{id:string,change_key:string}
     */
    private function updateDraft(string $user, string $id, string $changeKey, array $mail): array
    {
        $html = $mail['html'] ?? true;
        $set = static fn (string $field, string $inner): string => '<t:SetItemField><t:FieldURI FieldURI="' . $field . '"/><t:Message>' . $inner . '</t:Message></t:SetItemField>';
        $recipients = static function (string $field, string $element, array $addresses) use ($set): string {
            return $addresses !== []
                ? $set($field, EwsXml::recipients($element, $addresses))
                : '<t:DeleteItemField><t:FieldURI FieldURI="' . $field . '"/></t:DeleteItemField>';
        };
        $updates = $set('item:Subject', '<t:Subject>' . EwsXml::escape((string) ($mail['subject'] ?? '')) . '</t:Subject>')
            . $set('item:Body', '<t:Body BodyType="' . ($html ? 'HTML' : 'Text') . '">' . EwsXml::escape($this->outgoingBody($mail)) . '</t:Body>')
            . $set('item:Importance', '<t:Importance>' . self::importance((string) ($mail['importance'] ?? 'Normal')) . '</t:Importance>')
            . $recipients('message:ToRecipients', 'ToRecipients', $mail['to'] ?? [])
            . $recipients('message:CcRecipients', 'CcRecipients', $mail['cc'] ?? [])
            . $recipients('message:BccRecipients', 'BccRecipients', $mail['bcc'] ?? []);
        $xpath = $this->call(
            '<m:UpdateItem ConflictResolution="AlwaysOverwrite" MessageDisposition="SaveOnly"><m:ItemChanges><t:ItemChange>'
            . '<t:ItemId Id="' . EwsXml::escape($id) . '"' . ($changeKey !== '' ? ' ChangeKey="' . EwsXml::escape($changeKey) . '"' : '') . '/>'
            . '<t:Updates>' . $updates . '</t:Updates></t:ItemChange></m:ItemChanges></m:UpdateItem>',
            $user
        );
        $newKey = EwsXml::attr($xpath, '//m:Items/t:Message/t:ItemId', 'ChangeKey');

        return ['id' => $id, 'change_key' => $newKey !== '' ? $newKey : $changeKey];
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
