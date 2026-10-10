<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\ExchangeTransportInterface;

/**
 * Demomodus ohne Exchange-Server: beantwortet EWS-Anfragen mit Beispieldaten
 * (Posteingang, Termine, Kontakte, Aufgaben, Notizen). Aktiv, wenn im
 * Adminbereich als Exchange-Server „demo“ eingetragen ist und die Anwendung
 * nicht im Produktionsmodus laeuft. Schreibende Aktionen werden bestaetigt,
 * aber nicht gespeichert.
 */
final class DemoExchangeTransport implements ExchangeTransportInterface
{
    public const HOST = 'demo';

    /** Ordnergroessen: [Typ, Id, Elternordner, Byte] */
    private const SIZES = [
        ['Folder', 'demo-ipm', 'demo-root', 4096], ['Folder', 'demo-inbox', 'demo-ipm', 912261120], ['Folder', 'demo-sentitems', 'demo-ipm', 402653184],
        ['CalendarFolder', 'demo-calendar', 'demo-ipm', 83886080], ['ContactsFolder', 'demo-contacts', 'demo-ipm', 2097152], ['TasksFolder', 'demo-tasks', 'demo-ipm', 524288],
        ['Folder', 'demo-projekte', 'demo-inbox', 48113254], ['SearchFolder', 'demo-search', 'demo-root', 500000000],
        ['Folder', 'demo-recoverable', 'demo-root', 300000000], ['Folder', 'demo-deletions', 'demo-recoverable', 200000000],
    ];

    /**
     * E-Mail-Ordner: [Id, Name, Elemente, ungelesen, Elternordner]. Wie bei
     * Exchange liegt der Elternordner der obersten Ebene im Stammordner
     * ("demo-root"), der selbst nicht Teil der Ordnerliste ist (FindFolder ab
     * "msgfolderroot" liefert nur die Unterordner). Unterordner bilden die
     * Hierarchie ab, damit sich der Ordnerbaum im Demomodus wie am
     * Exchange-Server prüfen lässt.
     */
    private const MAIL_FOLDERS = [
        ['demo-inbox', 'Posteingang', 8, 3, 'demo-root'], ['demo-drafts', 'Entwürfe', 1, 0, 'demo-root'],
        ['demo-sentitems', 'Gesendete Elemente', 42, 0, 'demo-root'], ['demo-deleteditems', 'Gelöschte Elemente', 5, 0, 'demo-root'],
        ['demo-junkemail', 'Junk-E-Mail', 0, 0, 'demo-root'], ['demo-outbox', 'Postausgang', 0, 0, 'demo-root'],
        ['demo-projekte', 'Projekte', 17, 1, 'demo-inbox'], ['demo-rechnungen', 'Rechnungen', 9, 0, 'demo-projekte'],
    ];

    /**
     * Zusaetzlich berechtigte Postfaecher (Vollzugriff / "Senden als") im
     * Demomodus: Nur diese beiden Adressen lassen sich im Adminbereich
     * zuordnen und ueber EWS oeffnen, alle anderen gelten als nicht
     * erreichbar.
     */
    public const SHARED_MAILBOXES = [
        ['email' => 'team@demo.local', 'name' => 'Team Postfach', 'send_as' => true],
        ['email' => 'buero@demo.local', 'name' => 'Büro', 'send_as' => false],
    ];

    /** Kennung des aktuell angesprochenen Postfachs ("" = eigenes Postfach). */
    private string $mailboxSuffix = '';

    /** Angesprochenes Postfach aus dem SOAP-Kopf (klein, "" ohne Impersonation). */
    private string $address = '';

    public function post(string $url, string $xml, array $options): array
    {
        $this->address = $this->impersonatedAddress($xml);
        $this->mailboxSuffix = $this->mailboxSuffix();
        $body = match (true) {
            str_contains($xml, '<m:GetFolder>') && str_contains($xml, 'DistinguishedFolderId Id="msgfolderroot"') => $this->probeMailbox($xml),
            str_contains($xml, '<m:GetFolder>') && str_contains($xml, 'DistinguishedFolderId Id="root"/>') => $this->mailboxUsage(),
            str_contains($xml, '<m:GetFolder>') && str_contains($xml, 'PropertyTag="0x0E08"') => $this->folderProperties($xml),
            str_contains($xml, '<m:GetFolder>') && str_contains($xml, 'DistinguishedFolderId Id="inbox"/></m:FolderIds>') && !str_contains($xml, 'Id="drafts"') => $this->getInbox(),
            str_contains($xml, '<m:GetFolder>') => $this->getKnownFolders(),
            str_contains($xml, '<m:FindFolder') && str_contains($xml, 'PropertyTag="0x0E08"') && !str_contains($xml, 'DistinguishedFolderId Id="root"/>') => $this->subfolders($xml),
            str_contains($xml, '<m:FindFolder') && str_contains($xml, 'PropertyTag="0x0E08"') => $this->folderSizes(),
            str_contains($xml, '<m:CreateFolder>') => $this->envelope('<m:CreateFolderResponse><m:ResponseMessages><m:CreateFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="demo-folder-' . substr(sha1($xml), 0, 8) . '" ChangeKey="A"/></t:Folder></m:Folders></m:CreateFolderResponseMessage></m:ResponseMessages></m:CreateFolderResponse>'),
            str_contains($xml, '<m:FindFolder') => $this->findFolders(),
            str_contains($xml, '<m:CalendarView') => $this->calendar($xml),
            str_contains($xml, 'Id="contacts"') && str_contains($xml, '<m:FindItem') => $this->contacts(),
            str_contains($xml, 'Id="tasks"') && str_contains($xml, '<m:FindItem') => $this->tasks(),
            str_contains($xml, 'Id="notes"') && str_contains($xml, '<m:FindItem') => $this->notes(),
            str_contains($xml, '<m:FindItem') && str_contains($xml, 'IsLessThanOrEqualTo') => $this->archiveCandidates($xml),
            str_contains($xml, '<m:FindItem') => $this->messages($xml),
            str_contains($xml, '<m:GetItem>') => $this->getItem($xml),
            str_contains($xml, '<m:GetAttachment>') => $this->attachment($xml),
            str_contains($xml, '<m:CreateItem') => $this->created($xml),
            str_contains($xml, '<m:GetUserOofSettingsRequest>') => $this->oofSettings(),
            str_contains($xml, '<m:SetUserOofSettingsRequest>') => $this->envelope('<m:SetUserOofSettingsResponse><m:ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:ResponseMessage></m:SetUserOofSettingsResponse>'),
            str_contains($xml, '<m:CreateAttachment>') => $this->envelope('<m:CreateAttachmentResponse><m:ResponseMessages><m:CreateAttachmentResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Attachments><t:FileAttachment><t:AttachmentId Id="demo-att-new" RootItemId="demo-draft" RootItemChangeKey="CK2"/></t:FileAttachment></m:Attachments></m:CreateAttachmentResponseMessage></m:ResponseMessages></m:CreateAttachmentResponse>'),
            default => $this->success(),
        };

        return ['status' => 200, 'body' => $body, 'error' => ''];
    }

    private function envelope(string $body): string
    {
        if ($this->mailboxSuffix !== '') {
            // Kennungen je Postfach eindeutig halten: Ordner und Elemente
            // eines zusaetzlichen Postfachs tragen dessen Kennung.
            $body = (string) preg_replace('/Id="(demo-[^"]*)"/', 'Id="$1' . $this->mailboxSuffix . '"', $body);
        }

        return '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Header><h:ServerVersionInfo MajorVersion="15" MinorVersion="2" MajorBuildNumber="1258" MinorBuildNumber="12" Version="V2017_07_11" xmlns:h="http://schemas.microsoft.com/exchange/services/2006/types"/></s:Header>'
            . '<s:Body xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages" xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types">' . $body . '</s:Body></s:Envelope>';
    }

    /**
     * Suffix fuer die Beispieldaten des angesprochenen Postfachs: leer fuer
     * das eigene Postfach, sonst "@<adresse>".
     */
    private function mailboxSuffix(): string
    {
        foreach (self::SHARED_MAILBOXES as $mailbox) {
            if ($mailbox['email'] === $this->address) {
                return '@' . preg_replace('/[^a-z0-9]+/', '-', $mailbox['email']);
            }
        }

        return '';
    }

    /**
     * Postfachadresse aus der Impersonation des SOAP-Kopfes (klein, "" ohne
     * Impersonation).
     */
    private function impersonatedAddress(string $xml): string
    {
        if (preg_match('/<t:(?:PrimarySmtpAddress|SmtpAddress)>([^<]+)</', $xml, $match) !== 1) {
            return '';
        }

        return strtolower(trim(html_entity_decode($match[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')));
    }

    /**
     * Kennung aus einer Anfrage ohne das Postfach-Suffix.
     */
    private function requestId(string $id): string
    {
        return $this->mailboxSuffix !== '' ? str_replace($this->mailboxSuffix, '', $id) : $id;
    }

    /**
     * Erreichbarkeitspruefung eines zusaetzlich berechtigten Postfachs
     * (Adminbereich); im Demomodus sind nur die Beispielpostfaecher erreichbar.
     */
    private function probeMailbox(string $xml): string
    {
        // Pruefung als Benutzer: das Zielpostfach steht in der Ordnerkennung.
        $target = $this->address;
        if (preg_match('~<t:DistinguishedFolderId Id="msgfolderroot"><t:Mailbox><t:EmailAddress>([^<]+)</t:EmailAddress>~', $xml, $match) === 1) {
            $target = strtolower(trim(html_entity_decode($match[1], ENT_XML1 | ENT_QUOTES, 'UTF-8')));
        }
        foreach (self::SHARED_MAILBOXES as $mailbox) {
            if ($mailbox['email'] === $target) {
                return $this->getKnownFolders();
            }
        }

        return $this->envelope('<m:GetFolderResponse><m:ResponseMessages><m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>Das Postfach konnte nicht geoeffnet werden.</m:MessageText><m:ResponseCode>ErrorMailboxStoreUnavailable</m:ResponseCode></m:GetFolderResponseMessage></m:ResponseMessages></m:GetFolderResponse>');
    }

    private function success(): string
    {
        return $this->envelope('<m:UpdateItemResponse><m:ResponseMessages><m:UpdateItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items/></m:UpdateItemResponseMessage></m:ResponseMessages></m:UpdateItemResponse>');
    }

    private function created(string $xml): string
    {
        $type = match (true) {
            str_contains($xml, '<t:CalendarItem>') => 'CalendarItem',
            str_contains($xml, '<t:Contact>') => 'Contact',
            str_contains($xml, '<t:Task>') => 'Task',
            str_contains($xml, 'IPM.StickyNote') => 'Item',
            default => 'Message',
        };

        return $this->envelope('<m:CreateItemResponse><m:ResponseMessages><m:CreateItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items><t:' . $type . '><t:ItemId Id="demo-new-' . substr(sha1($xml), 0, 8) . '" ChangeKey="CK1"/></t:' . $type . '></m:Items></m:CreateItemResponseMessage></m:ResponseMessages></m:CreateItemResponse>');
    }

    /**
     * Abwesenheitsnotiz: Im Demomodus ohne Exchange-Server traegt die
     * gespeicherte Einstellung den Zustand; hier wird nur eine abgeschaltete
     * Notiz mit Beispieltext gemeldet.
     */
    private function oofSettings(): string
    {
        return $this->envelope('<m:GetUserOofSettingsResponse><m:ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:ResponseMessage>'
            . '<m:OofSettings><t:OofState>Disabled</t:OofState><t:ExternalAudience>All</t:ExternalAudience>'
            . '<t:InternalReply><t:Message>Ich bin derzeit nicht im Haus.</t:Message></t:InternalReply>'
            . '<t:ExternalReply><t:Message>Ich bin derzeit nicht im Haus.</t:Message></t:ExternalReply>'
            . '</m:OofSettings></m:GetUserOofSettingsResponse>');
    }

    private function mailboxUsage(): string
    {
        // Stammordner 12 KB; Warnung 1,9 GB, Senden gesperrt ab 2 GB, Empfang ab 2,3 GB (Werte in KB)
        return $this->envelope('<m:GetFolderResponse><m:ResponseMessages><m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="demo-root" ChangeKey="A"/>'
            . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>12288</t:Value></t:ExtendedProperty>'
            . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x3ff5" PropertyType="Integer"/><t:Value>1992294</t:Value></t:ExtendedProperty>'
            . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x666e" PropertyType="Integer"/><t:Value>2097152</t:Value></t:ExtendedProperty>'
            . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x666a" PropertyType="Integer"/><t:Value>2411724</t:Value></t:ExtendedProperty>'
            . '</t:Folder></m:Folders></m:GetFolderResponseMessage>'
            . '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="demo-recoverable" ChangeKey="A"/>'
            . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>0</t:Value></t:ExtendedProperty>'
            . '</t:Folder></m:Folders></m:GetFolderResponseMessage></m:ResponseMessages></m:GetFolderResponse>');
    }

    /**
     * Groessen aller Ordner unterhalb des Stammordners (zusammen 1,35 GB
     * mit dem Stammordner; Suchordner und Wiederherstellbare Elemente
     * zaehlen nicht).
     */
    private function folderSizes(): string
    {
        $folders = self::SIZES;
        $out = '';
        foreach ($folders as [$type, $id, $parent, $size]) {
            $out .= '<t:' . $type . '><t:FolderId Id="' . $id . '" ChangeKey="A"/><t:ParentFolderId Id="' . $parent . '" ChangeKey="A"/>'
                . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>' . $size . '</t:Value></t:ExtendedProperty></t:' . $type . '>';
        }

        return $this->envelope('<m:FindFolderResponse><m:ResponseMessages><m:FindFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="' . count($folders) . '" IncludesLastItemInRange="true"><t:Folders>' . $out . '</t:Folders></m:RootFolder></m:FindFolderResponseMessage></m:ResponseMessages></m:FindFolderResponse>');
    }

    private function getInbox(): string
    {
        return $this->envelope('<m:GetFolderResponse><m:ResponseMessages><m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="demo-inbox" ChangeKey="A"/><t:DisplayName>Posteingang</t:DisplayName><t:TotalCount>8</t:TotalCount><t:ChildFolderCount>0</t:ChildFolderCount><t:UnreadCount>3</t:UnreadCount></t:Folder></m:Folders></m:GetFolderResponseMessage></m:ResponseMessages></m:GetFolderResponse>');
    }

    private function getKnownFolders(): string
    {
        $out = '';
        foreach (OrvantaExchangeService::MAIL_FOLDERS as $folder) {
            $out .= '<m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="demo-' . $folder . '" ChangeKey="A"/></t:Folder></m:Folders></m:GetFolderResponseMessage>';
        }

        return $this->envelope('<m:GetFolderResponse><m:ResponseMessages>' . $out . '</m:ResponseMessages></m:GetFolderResponse>');
    }

    private function findFolders(): string
    {
        $folders = self::MAIL_FOLDERS;
        $out = '';
        foreach ($folders as [$id, $name, $total, $unread, $parent]) {
            $out .= '<t:Folder><t:FolderId Id="' . $id . '" ChangeKey="A"/><t:ParentFolderId Id="' . $parent . '" ChangeKey="A"/><t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>' . $name . '</t:DisplayName><t:TotalCount>' . $total . '</t:TotalCount><t:ChildFolderCount>0</t:ChildFolderCount><t:UnreadCount>' . $unread . '</t:UnreadCount></t:Folder>';
        }

        return $this->envelope('<m:FindFolderResponse><m:ResponseMessages><m:FindFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="' . count($folders) . '" IncludesLastItemInRange="true"><t:Folders>' . $out . '</t:Folders></m:RootFolder></m:FindFolderResponseMessage></m:ResponseMessages></m:FindFolderResponse>');
    }

    /**
     * Ordner-Id aus dem ersten Folder- bzw. DistinguishedFolderId-Element
     * innerhalb von $container (Distinguished-Namen werden zu „demo-…“).
     */
    private function requestedFolder(string $xml, string $container): string
    {
        if (preg_match('/<m:' . $container . '>\s*<t:(?:Distinguished)?FolderId Id="([^"]+)"/', $xml, $match) !== 1) {
            return '';
        }
        $id = $this->requestId($match[1]);

        return str_starts_with($id, 'demo-') ? $id : 'demo-' . $id;
    }

    private function folderProperties(string $xml): string
    {
        $id = $this->requestedFolder($xml, 'FolderIds');
        $size = 0;
        foreach (self::SIZES as [, $sizeId, , $bytes]) {
            if ($sizeId === $id) {
                $size = $bytes;
            }
        }
        $children = 0;
        foreach (self::SIZES as [, , $parent]) {
            $children += $parent === $id ? 1 : 0;
        }
        foreach (self::MAIL_FOLDERS as [$folderId, $name, $total, $unread]) {
            if ($folderId === $id) {
                return $this->envelope('<m:GetFolderResponse><m:ResponseMessages><m:GetFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Folders><t:Folder><t:FolderId Id="' . $id . '" ChangeKey="A"/>'
                    . '<t:DisplayName>' . $name . '</t:DisplayName><t:TotalCount>' . $total . '</t:TotalCount><t:ChildFolderCount>' . $children . '</t:ChildFolderCount><t:UnreadCount>' . $unread . '</t:UnreadCount>'
                    . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>' . ($size ?: $total * 45056) . '</t:Value></t:ExtendedProperty>'
                    . '</t:Folder></m:Folders></m:GetFolderResponseMessage></m:ResponseMessages></m:GetFolderResponse>');
            }
        }

        return $this->envelope('<m:GetFolderResponse><m:ResponseMessages><m:GetFolderResponseMessage ResponseClass="Error"><m:MessageText>The specified folder could not be found in the store.</m:MessageText><m:ResponseCode>ErrorFolderNotFound</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:GetFolderResponseMessage></m:ResponseMessages></m:GetFolderResponse>');
    }

    /**
     * Unterordner (Deep) eines E-Mail-Ordners mit Anzahl und Groesse.
     */
    private function subfolders(string $xml): string
    {
        $totals = [];
        foreach (self::MAIL_FOLDERS as [$folderId, , $total]) {
            $totals[$folderId] = $total;
        }
        $parents = [$this->requestedFolder($xml, 'ParentFolderIds')];
        $out = '';
        $count = 0;
        for ($i = 0; $i < count($parents); $i++) {
            foreach (self::SIZES as [$type, $id, $parent, $size]) {
                if ($parent === $parents[$i]) {
                    $parents[] = $id;
                    $count++;
                    $out .= '<t:' . $type . '><t:FolderId Id="' . $id . '" ChangeKey="A"/><t:TotalCount>' . ($totals[$id] ?? 0) . '</t:TotalCount>'
                        . '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>' . $size . '</t:Value></t:ExtendedProperty></t:' . $type . '>';
                }
            }
        }

        return $this->envelope('<m:FindFolderResponse><m:ResponseMessages><m:FindFolderResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="' . $count . '" IncludesLastItemInRange="true"><t:Folders>' . $out . '</t:Folders></m:RootFolder></m:FindFolderResponseMessage></m:ResponseMessages></m:FindFolderResponse>');
    }

    /**
     * @return list<array{id:string,subject:string,from:array{0:string,1:string},received:int,read:bool,att:bool,preview:string,importance?:string,replied?:bool,forwarded?:bool}>
     */
    private function sampleMessages(): array
    {
        $today = strtotime('today 09:15');

        return [
            ['id' => 'demo-msg-1', 'subject' => 'Protokoll Dienstbesprechung KW ' . date('W'), 'from' => ['Sabine Krüger', 'sabine.krueger@example.org'], 'received' => $today + 3600 * 2, 'read' => false, 'att' => true, 'preview' => 'Hallo zusammen, anbei das Protokoll der heutigen Dienstbesprechung mit den vereinbarten Maßnahmen …', 'importance' => 'High'],
            ['id' => 'demo-msg-2', 'subject' => 'Wartungsfenster Rechenzentrum am Samstag', 'from' => ['IT-Service', 'it-service@example.org'], 'received' => $today + 1800, 'read' => false, 'att' => false, 'preview' => 'Am kommenden Samstag von 06:00 bis 10:00 Uhr werden die zentralen Speichersysteme gewartet.'],
            ['id' => 'demo-msg-3', 'subject' => 'Urlaubsantrag genehmigt', 'from' => ['Personalabteilung', 'personal@example.org'], 'received' => $today - 86400 + 7200, 'read' => true, 'att' => true, 'preview' => 'Ihr Urlaubsantrag für den Zeitraum 14.–25. des nächsten Monats wurde genehmigt.', 'replied' => true],
            ['id' => 'demo-msg-4', 'subject' => 'Re: Angebot Büromöbel – Rückfrage zur Lieferzeit', 'from' => ['Markus Vogel', 'm.vogel@moebel-beispiel.de'], 'received' => $today - 86400 + 3000, 'read' => true, 'att' => true, 'preview' => 'vielen Dank für Ihre Anfrage. Die Lieferzeit beträgt derzeit etwa vier Wochen …', 'replied' => true, 'forwarded' => true],
            ['id' => 'demo-msg-5', 'subject' => 'Einladung: Schulung Notfallplan-Editor', 'from' => ['Daniel Andre', 'daniel.andre@example.org'], 'received' => $today - 2 * 86400, 'read' => false, 'att' => false, 'preview' => 'Wir laden Sie herzlich zur Schulung des neuen Notfallplan-Editors ein.'],
            ['id' => 'demo-msg-6', 'subject' => 'Quartalszahlen Q3 – Entwurf zur Durchsicht', 'from' => ['Controlling', 'controlling@example.org'], 'received' => $today - 3 * 86400, 'read' => true, 'att' => true, 'preview' => 'Anbei der Entwurf der Quartalszahlen. Bitte prüfen Sie die Abteilungsbudgets bis Freitag.', 'forwarded' => true],
            ['id' => 'demo-msg-7', 'subject' => 'Newsletter Intranet: Neue Office-Apps verfügbar', 'from' => ['Intranet-Redaktion', 'intranet@example.org'], 'received' => $today - 4 * 86400, 'read' => true, 'att' => false, 'preview' => 'Ab sofort stehen Euro-Office Writer, Calc und Impress direkt über die Office-Seite bereit.'],
            ['id' => 'demo-msg-8', 'subject' => 'Parkplatzregelung ab nächstem Monat', 'from' => ['Facility Management', 'facility@example.org'], 'received' => $today - 6 * 86400, 'read' => true, 'att' => false, 'preview' => 'Bitte beachten Sie die geänderte Zuordnung der Parkflächen im Innenhof.'],
        ];
    }

    /**
     * Alte Beispielnachrichten fuer das Langzeitarchiv (Demomodus): deutlich
     * aelter als das Standard-Mindestalter von 60 Tagen.
     *
     * @return list<array{id:string,subject:string,from:array{0:string,1:string},received:int,read:bool,att:bool,preview:string}>
     */
    private function sampleArchiveMessages(): array
    {
        $today = strtotime('today 09:15');

        return [
            ['id' => 'demo-old-1', 'subject' => 'Jahresabschluss Vorjahr – finale Unterlagen', 'from' => ['Controlling', 'controlling@example.org'], 'received' => $today - 400 * 86400, 'read' => true, 'att' => true, 'preview' => 'Anbei die finalen Unterlagen zum Jahresabschluss des Vorjahres.'],
            ['id' => 'demo-old-2', 'subject' => 'Altes Wartungsprotokoll USV', 'from' => ['IT-Service', 'it-service@example.org'], 'received' => $today - 250 * 86400, 'read' => true, 'att' => false, 'preview' => 'Das Wartungsprotokoll der USV-Anlage liegt zur Ablage bereit.'],
            ['id' => 'demo-old-3', 'subject' => 'Einladung Sommerfest (vergangen)', 'from' => ['Personalabteilung', 'personal@example.org'], 'received' => $today - 120 * 86400, 'read' => true, 'att' => false, 'preview' => 'Herzliche Einladung zum Sommerfest auf dem Betriebsgelände.'],
            ['id' => 'demo-old-4', 'subject' => 'Rechnung 2024-1874 – Büromaterial', 'from' => ['Markus Vogel', 'm.vogel@moebel-beispiel.de'], 'received' => $today - 90 * 86400, 'read' => true, 'att' => true, 'preview' => 'Anbei die Rechnung für die Lieferung Büromaterial.'],
            ['id' => 'demo-old-5', 'subject' => 'Protokoll Dienstbesprechung (Archivbestand)', 'from' => ['Sabine Krüger', 'sabine.krueger@example.org'], 'received' => $today - 70 * 86400, 'read' => true, 'att' => false, 'preview' => 'Das Protokoll der damaligen Dienstbesprechung zur Ablage.'],
        ];
    }

    /**
     * FindItem mit Restriction (IsLessThanOrEqualTo item:DateTimeReceived):
     * Archivierungs-Kandidaten im Demomodus - nur im Posteingang.
     */
    private function archiveCandidates(string $xml): string
    {
        $before = PHP_INT_MAX;
        if (preg_match('/<t:Constant Value="([^"]+)"/', $xml, $m) === 1) {
            $before = strtotime($m[1]) ?: PHP_INT_MAX;
        }
        $list = [];
        if (str_contains($xml, 'Id="demo-inbox"') || str_contains($xml, 'Id="inbox"')) {
            foreach ($this->sampleArchiveMessages() as $message) {
                if ($message['received'] <= $before) {
                    $list[] = $message;
                }
            }
        }
        $offset = preg_match('/Offset="(\d+)"/', $xml, $m) === 1 ? (int) $m[1] : 0;
        $max = preg_match('/MaxEntriesReturned="(\d+)"/', $xml, $m) === 1 ? (int) $m[1] : 50;
        $page = array_slice($list, $offset, $max);
        $last = $offset + $max >= count($list) ? 'true' : 'false';
        $out = '';
        foreach ($page as $message) {
            $host = substr(strrchr($message['from'][1], '@') ?: '@example.org', 1);
            $out .= $this->messageXml($message, false, '<t:InternetMessageId>' . EwsXml::escape('<' . $message['id'] . '@' . $host . '>') . '</t:InternetMessageId>');
        }

        return $this->envelope('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="' . count($list) . '" IncludesLastItemInRange="' . $last . '"><t:Items>' . $out . '</t:Items></m:RootFolder></m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }

    private function messages(string $xml): string
    {
        $out = '';
        $list = $this->sampleMessages();
        $search = '';
        if (preg_match('/<m:QueryString>(.*?)<\/m:QueryString>/', $xml, $m) === 1) {
            $search = mb_strtolower(html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }
        if (str_contains($xml, 'Id="demo-sentitems"')) {
            $list = [['id' => 'demo-sent-1', 'subject' => 'AW: Protokoll Dienstbesprechung', 'from' => ['Ich', 'ich@example.org'], 'received' => time() - 5400, 'read' => true, 'att' => false, 'preview' => 'Danke, ich habe die Maßnahmen in den Notfallplan übernommen.']];
        } elseif (!str_contains($xml, 'Id="demo-inbox"') && !str_contains($xml, 'Id="inbox"')) {
            $list = array_slice($list, 4, 2);
        }
        foreach ($list as $message) {
            if ($search !== '' && !str_contains(mb_strtolower($message['subject'] . ' ' . $message['from'][0] . ' ' . $message['preview']), $search)) {
                continue;
            }
            $out .= $this->messageXml($message);
        }

        return $this->envelope('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="' . count($list) . '" IncludesLastItemInRange="true"><t:Items>' . $out . '</t:Items></m:RootFolder></m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }

    /**
     * @param array<string,mixed> $message
     */
    private function messageXml(array $message, bool $full = false, string $extra = ''): string
    {
        $e = static fn (string $value): string => EwsXml::escape($value);
        // Wie bei Exchange traegt der Symbolindex die Antwort und
        // PR_LAST_VERB_EXECUTED das zuletzt ausgefuehrte Verb (NOTEIVERB);
        // demo-msg-4 deckt beide Quellen ab.
        $icon = !empty($message['replied']) ? 0x0105 : (!empty($message['forwarded']) ? 0x0106 : 0);
        $verb = !empty($message['forwarded']) ? 104 : (!empty($message['replied']) ? 102 : 0);
        $xml = '<t:Message>' . $extra . '<t:ItemId Id="' . $message['id'] . '" ChangeKey="CK1"/><t:ItemClass>IPM.Note</t:ItemClass><t:Subject>' . $e($message['subject']) . '</t:Subject>'
            . '<t:Importance>' . ($message['importance'] ?? 'Normal') . '</t:Importance><t:DateTimeReceived>' . EwsXml::dateTime((int) $message['received']) . '</t:DateTimeReceived><t:DateTimeSent>' . EwsXml::dateTime((int) $message['received'] - 60) . '</t:DateTimeSent>'
            . '<t:Size>' . (12000 + strlen($message['subject']) * 97) . '</t:Size><t:HasAttachments>' . ($message['att'] ? 'true' : 'false') . '</t:HasAttachments>'
            . ($icon > 0 ? '<t:IconIndex>' . $icon . '</t:IconIndex>' : '')
            . ($verb > 0 ? '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x1081" PropertyType="Integer"/><t:Value>' . $verb . '</t:Value></t:ExtendedProperty>' : '')
            . ($full ? '<t:Body BodyType="HTML">' . $e($this->bodyFor($message)) . '</t:Body>' : '')
            . ($full && $message['att'] ? $this->attachmentsXml($message['id']) : '')
            . '<t:ToRecipients><t:Mailbox><t:Name>Ich</t:Name><t:EmailAddress>ich@example.org</t:EmailAddress></t:Mailbox></t:ToRecipients>'
            . ($full ? '<t:CcRecipients><t:Mailbox><t:Name>Team Intranet</t:Name><t:EmailAddress>team-intranet@example.org</t:EmailAddress></t:Mailbox></t:CcRecipients>' : '')
            . '<t:From><t:Mailbox><t:Name>' . $e($message['from'][0]) . '</t:Name><t:EmailAddress>' . $e($message['from'][1]) . '</t:EmailAddress></t:Mailbox></t:From>'
            . '<t:IsRead>' . ($message['read'] ? 'true' : 'false') . '</t:IsRead><t:Preview>' . $e($message['preview']) . '</t:Preview></t:Message>';

        return $xml;
    }

    /**
     * @param array<string,mixed> $message
     */
    private function bodyFor(array $message): string
    {
        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px"><p>Guten Tag,</p><p>' . EwsXml::escape($message['preview']) . '</p>'
            . '<p>Weitere Informationen finden Sie im Intranet unter <a href="https://intranet.example.org/">intranet.example.org</a>. Bei Rückfragen stehe ich gern zur Verfügung.</p>'
            . '<table style="border-collapse:collapse" border="1" cellpadding="6"><tr><th>Punkt</th><th>Verantwortlich</th><th>Termin</th></tr><tr><td>Serverwartung</td><td>IT-Service</td><td>Samstag</td></tr><tr><td>Schulung Notfallplan</td><td>D. Andre</td><td>nächste Woche</td></tr></table>'
            . '<p>Mit freundlichen Grüßen<br><strong>' . EwsXml::escape($message['from'][0]) . '</strong><br><span style="color:#5a6475">' . EwsXml::escape($message['from'][1]) . '</span></p>'
            . ($message['att'] ? '<p><img src="cid:orvanta-logo@demo" alt="Orvanta" width="80" height="70"></p>' : '')
            . '<img src="https://tracker.example.org/pixel.gif" width="1" height="1" alt=""></div>';
    }

    private function attachmentsXml(string $id): string
    {
        return '<t:Attachments>'
            . '<t:FileAttachment><t:AttachmentId Id="' . $id . '|att-1"/><t:Name>Protokoll_Dienstbesprechung.docx</t:Name><t:ContentType>application/vnd.openxmlformats-officedocument.wordprocessingml.document</t:ContentType><t:Size>48213</t:Size><t:IsInline>false</t:IsInline></t:FileAttachment>'
            . '<t:FileAttachment><t:AttachmentId Id="' . $id . '|att-2"/><t:Name>Massnahmenliste.xlsx</t:Name><t:ContentType>application/vnd.openxmlformats-officedocument.spreadsheetml.sheet</t:ContentType><t:Size>19877</t:Size><t:IsInline>false</t:IsInline></t:FileAttachment>'
            . '<t:FileAttachment><t:AttachmentId Id="' . $id . '|att-3"/><t:Name>Lageplan.pdf</t:Name><t:ContentType>application/pdf</t:ContentType><t:Size>302114</t:Size><t:IsInline>false</t:IsInline></t:FileAttachment>'
            . '<t:FileAttachment><t:AttachmentId Id="' . $id . '|att-4"/><t:Name>orvanta-logo.png</t:Name><t:ContentType>image/png</t:ContentType><t:ContentId>orvanta-logo@demo</t:ContentId><t:Size>417</t:Size><t:IsInline>true</t:IsInline></t:FileAttachment>'
            . '</t:Attachments>';
    }

    /**
     * Beispielhafter MIME-Quelltext (nur Kopf + kurzer Text) fuer „Info“.
     *
     * @param array<string,mixed> $message
     */
    private function mimeFor(array $message): string
    {
        $date = gmdate('D, d M Y H:i:s', (int) $message['received'] - 60) . ' +0000';
        $host = substr(strrchr($message['from'][1], '@') ?: '@example.org', 1);
        $headers = "Received: from mail.example.org (mail.example.org [192.0.2.10])\r\n\tby exchange.example.org with ESMTPS id " . substr(sha1($message['id']), 0, 12) . ";\r\n\t" . $date . "\r\n"
            . 'Received: from ' . $host . ' ([198.51.100.7]) by mail.example.org with ESMTP; ' . $date . "\r\n"
            . 'Authentication-Results: exchange.example.org; spf=pass smtp.mailfrom=' . $host . "; dkim=pass; dmarc=pass\r\n"
            . 'From: "' . $message['from'][0] . '" <' . $message['from'][1] . ">\r\n"
            . "To: Ich <ich@example.org>\r\nCc: Team Intranet <team-intranet@example.org>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($message['subject']) . "?=\r\n"
            . 'Date: ' . $date . "\r\nMessage-ID: <" . $message['id'] . '@' . $host . ">\r\n"
            . 'X-Priority: ' . (($message['importance'] ?? 'Normal') === 'High' ? '1 (Highest)' : '3 (Normal)') . "\r\n"
            . "X-Mailer: Orvanta Demo\r\nMIME-Version: 1.0\r\n";
        $html = "Content-Type: text/html; charset=\"utf-8\"\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $this->bodyFor($message) . "\r\n";
        if (empty($message['att'])) {
            return $headers . $html;
        }

        // Mit Anhaengen: multipart/mixed wie bei echten Exchange-MimeContents,
        // damit Archivierung und Anhangsliste den vollstaendigen Pfad durchlaufen.
        $boundary = '----=_Orvanta_' . substr(sha1('mime' . $message['id']), 0, 16);
        $mime = $headers . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n--" . $boundary . "\r\n" . $html;
        foreach (['att-1', 'att-2', 'att-3', 'att-4'] as $suffix) {
            [$name, $type, $content, $inline] = $this->attachmentData($suffix);
            $mime .= '--' . $boundary . "\r\nContent-Type: " . $type . '; name="' . $name . "\"\r\nContent-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . "\"\r\n"
                . ($inline ? "Content-ID: <orvanta-logo@demo>\r\n" : '')
                . "\r\n" . chunk_split(base64_encode($content), 76, "\r\n");
        }

        return $mime . '--' . $boundary . "--\r\n";
    }

    /**
     * Name, Typ, Inhalt und Inline-Kennzeichen eines Demo-Anhangs.
     *
     * @return array{0:string,1:string,2:string,3:bool}
     */
    private function attachmentData(string $suffix): array
    {
        $name = match ($suffix) {
            'att-1' => 'Protokoll_Dienstbesprechung.docx',
            'att-2' => 'Massnahmenliste.xlsx',
            'att-4' => 'orvanta-logo.png',
            default => 'Lageplan.pdf',
        };
        $type = match ($suffix) {
            'att-1' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'att-2' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'att-4' => 'image/png',
            default => 'application/pdf',
        };
        $content = match (true) {
            $type === 'image/png' => (string) @file_get_contents(dirname(__DIR__, 3) . '/public/assets/images/orvanta-logo.png'),
            str_ends_with($name, '.pdf') => "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n4 0 obj<</Length 70>>stream\nBT /F1 24 Tf 72 760 Td (Orvanta Demo - Lageplan) Tj ET\nendstream\nendobj\n5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF",
            default => 'Orvanta-Demoanhang ' . $name,
        };

        return [$name, $type, $content, $suffix === 'att-4'];
    }

    private function getItem(string $xml): string
    {
        preg_match('/<t:ItemId Id="([^"]+)"/', $xml, $m);
        $id = $this->requestId($m[1] ?? '');
        if (str_starts_with($id, 'demo-ev-')) {
            $event = $this->sampleEvents()[(int) substr($id, 8) - 1] ?? $this->sampleEvents()[0];

            return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items>' . $this->eventXml($event, true) . '</m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
        }
        if (str_starts_with($id, 'demo-contact-')) {
            return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items>' . $this->contactXml($this->sampleContacts()[(int) substr($id, 13) - 1] ?? $this->sampleContacts()[0]) . '</m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
        }
        if (str_starts_with($id, 'demo-task-')) {
            return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items>' . $this->taskXml($this->sampleTasks()[(int) substr($id, 10) - 1] ?? $this->sampleTasks()[0]) . '</m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
        }
        if (str_starts_with($id, 'demo-note-')) {
            $note = $this->sampleNotes()[(int) substr($id, 10) - 1] ?? $this->sampleNotes()[0];

            return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items><t:Item><t:ItemId Id="' . $id . '" ChangeKey="CK1"/><t:Subject>' . EwsXml::escape($note['subject']) . '</t:Subject><t:Body BodyType="Text">' . EwsXml::escape($note['body']) . '</t:Body><t:LastModifiedTime>' . EwsXml::dateTime($note['modified']) . '</t:LastModifiedTime></t:Item></m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
        }
        foreach (array_merge($this->sampleMessages(), $this->sampleArchiveMessages(), [['id' => 'demo-sent-1', 'subject' => 'AW: Protokoll Dienstbesprechung', 'from' => ['Ich', 'ich@example.org'], 'received' => time() - 5400, 'read' => true, 'att' => false, 'preview' => 'Danke, ich habe die Maßnahmen in den Notfallplan übernommen.']]) as $message) {
            if ($message['id'] === $id) {
                $extra = '';
                if (str_contains($xml, 'item:MimeContent') || str_contains($xml, 'IncludeMimeContent')) {
                    $extra .= '<t:MimeContent CharacterSet="UTF-8">' . base64_encode($this->mimeFor($message)) . '</t:MimeContent>';
                }
                if (str_contains($xml, 'InternetMessageId')) {
                    $host = substr(strrchr($message['from'][1], '@') ?: '@example.org', 1);
                    $extra .= '<t:InternetMessageId>' . EwsXml::escape('<' . $message['id'] . '@' . $host . '>') . '</t:InternetMessageId>';
                }

                return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Items>' . $this->messageXml($message, true, $extra) . '</m:Items></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
            }
        }

        return $this->envelope('<m:GetItemResponse><m:ResponseMessages><m:GetItemResponseMessage ResponseClass="Error"><m:MessageText>The specified object was not found in the store.</m:MessageText><m:ResponseCode>ErrorItemNotFound</m:ResponseCode></m:GetItemResponseMessage></m:ResponseMessages></m:GetItemResponse>');
    }

    private function attachment(string $xml): string
    {
        preg_match('/<t:AttachmentId Id="([^"]+)"/', $xml, $m);
        $id = $this->requestId(html_entity_decode($m[1] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        [$name, $type, $content] = $this->attachmentData(substr($id, -5));

        return $this->envelope('<m:GetAttachmentResponse><m:ResponseMessages><m:GetAttachmentResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:Attachments><t:FileAttachment><t:AttachmentId Id="' . EwsXml::escape($id) . '"/><t:Name>' . $name . '</t:Name><t:ContentType>' . $type . '</t:ContentType><t:Content>' . base64_encode($content) . '</t:Content></t:FileAttachment></m:Attachments></m:GetAttachmentResponseMessage></m:ResponseMessages></m:GetAttachmentResponse>');
    }

    /**
     * @return list<array{id:string,subject:string,start:int,end:int,location:string,all_day:bool,reminder:int,busy:string,meeting:bool}>
     */
    private function sampleEvents(): array
    {
        $monday = strtotime('monday this week 00:00');
        $now = time();

        return [
            ['id' => 'demo-ev-1', 'subject' => 'Dienstbesprechung', 'start' => $monday + 9 * 3600, 'end' => $monday + 10 * 3600 + 1800, 'location' => 'Besprechungsraum 2.14', 'all_day' => false, 'reminder' => 15, 'busy' => 'Busy', 'meeting' => true],
            ['id' => 'demo-ev-2', 'subject' => 'Jour fixe IT-Sicherheit', 'start' => $monday + 86400 + 11 * 3600, 'end' => $monday + 86400 + 12 * 3600, 'location' => 'Online (Teams)', 'all_day' => false, 'reminder' => 15, 'busy' => 'Busy', 'meeting' => true],
            ['id' => 'demo-ev-3', 'subject' => 'Schulung Notfallplan-Editor', 'start' => $monday + 2 * 86400 + 14 * 3600, 'end' => $monday + 2 * 86400 + 16 * 3600, 'location' => 'Schulungsraum EG', 'all_day' => false, 'reminder' => 30, 'busy' => 'Busy', 'meeting' => true],
            ['id' => 'demo-ev-4', 'subject' => 'Betriebsausflug', 'start' => $monday + 4 * 86400, 'end' => $monday + 5 * 86400, 'location' => 'Treffpunkt Haupteingang', 'all_day' => true, 'reminder' => 1080, 'busy' => 'OOF', 'meeting' => false],
            ['id' => 'demo-ev-5', 'subject' => 'Abstimmung Quartalszahlen', 'start' => $monday + 3 * 86400 + 10 * 3600, 'end' => $monday + 3 * 86400 + 11 * 3600, 'location' => 'Büro Controlling', 'all_day' => false, 'reminder' => 15, 'busy' => 'Tentative', 'meeting' => true],
            ['id' => 'demo-ev-6', 'subject' => 'Erinnerung: Statusbericht abgeben', 'start' => $now + 600, 'end' => $now + 1800, 'location' => '', 'all_day' => false, 'reminder' => 15, 'busy' => 'Free', 'meeting' => false],
            ['id' => 'demo-ev-7', 'subject' => 'Sprechstunde Personalrat', 'start' => $monday + 7 * 86400 + 13 * 3600, 'end' => $monday + 7 * 86400 + 14 * 3600, 'location' => 'Raum 1.03', 'all_day' => false, 'reminder' => 15, 'busy' => 'Busy', 'meeting' => false],
            ['id' => 'demo-ev-9', 'subject' => 'Rückruf Bürgerbüro', 'start' => $now + 1200, 'end' => $now + 1500, 'location' => 'Telefon', 'all_day' => false, 'reminder' => -1, 'busy' => 'Busy', 'meeting' => false],
            ['id' => 'demo-ev-8', 'subject' => 'Wartungsfenster Rechenzentrum', 'start' => $monday + 5 * 86400 + 6 * 3600, 'end' => $monday + 5 * 86400 + 10 * 3600, 'location' => 'Rechenzentrum', 'all_day' => false, 'reminder' => 60, 'busy' => 'WorkingElsewhere', 'meeting' => false],
        ];
    }

    private function calendar(string $xml): string
    {
        preg_match('/StartDate="([^"]+)" EndDate="([^"]+)"/', $xml, $m);
        $from = isset($m[1]) ? (strtotime($m[1]) ?: 0) : 0;
        $to = isset($m[2]) ? (strtotime($m[2]) ?: PHP_INT_MAX) : PHP_INT_MAX;
        $out = '';
        foreach ($this->sampleEvents() as $event) {
            if ($event['end'] >= $from && $event['start'] <= $to) {
                $out .= $this->eventXml($event);
            }
        }

        return $this->envelope('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="8" IncludesLastItemInRange="true"><t:Items>' . $out . '</t:Items></m:RootFolder></m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }

    /**
     * @param array<string,mixed> $event
     */
    private function eventXml(array $event, bool $full = false): string
    {
        return '<t:CalendarItem><t:ItemId Id="' . $event['id'] . '" ChangeKey="CK1"/><t:Subject>' . EwsXml::escape($event['subject']) . '</t:Subject>'
            . ($full ? '<t:Body BodyType="HTML">' . EwsXml::escape('<p>Agenda:</p><ol><li>Rückblick</li><li>Offene Punkte</li><li>Nächste Schritte</li></ol>') . '</t:Body>' : '')
            . ($event['reminder'] >= 0
                ? '<t:ReminderIsSet>true</t:ReminderIsSet><t:ReminderMinutesBeforeStart>' . $event['reminder'] . '</t:ReminderMinutesBeforeStart>'
                : '<t:ReminderIsSet>false</t:ReminderIsSet>')
            . '<t:Start>' . EwsXml::dateTime($event['start']) . '</t:Start><t:End>' . EwsXml::dateTime($event['end']) . '</t:End>'
            . '<t:IsAllDayEvent>' . ($event['all_day'] ? 'true' : 'false') . '</t:IsAllDayEvent><t:LegacyFreeBusyStatus>' . $event['busy'] . '</t:LegacyFreeBusyStatus>'
            . '<t:Location>' . EwsXml::escape($event['location']) . '</t:Location><t:IsMeeting>' . ($event['meeting'] ? 'true' : 'false') . '</t:IsMeeting><t:CalendarItemType>Single</t:CalendarItemType>'
            . '<t:MyResponseType>' . ($event['meeting'] ? 'Accept' : 'Organizer') . '</t:MyResponseType>'
            . '<t:Organizer><t:Mailbox><t:Name>Sabine Krüger</t:Name><t:EmailAddress>sabine.krueger@example.org</t:EmailAddress></t:Mailbox></t:Organizer>'
            . ($full && $event['meeting'] ? '<t:RequiredAttendees><t:Attendee><t:Mailbox><t:Name>Ich</t:Name><t:EmailAddress>ich@example.org</t:EmailAddress></t:Mailbox><t:ResponseType>Accept</t:ResponseType></t:Attendee><t:Attendee><t:Mailbox><t:Name>Markus Vogel</t:Name><t:EmailAddress>m.vogel@example.org</t:EmailAddress></t:Mailbox><t:ResponseType>Tentative</t:ResponseType></t:Attendee></t:RequiredAttendees>' : '')
            . '</t:CalendarItem>';
    }

    /**
     * @return list<array{id:string,given:string,surname:string,company:string,title:string,email:string,phone:string,mobile:string,department:string}>
     */
    private function sampleContacts(): array
    {
        return [
            ['id' => 'demo-contact-1', 'given' => 'Sabine', 'surname' => 'Krüger', 'company' => 'Beispiel-Verwaltung', 'title' => 'Abteilungsleitung', 'email' => 'sabine.krueger@example.org', 'phone' => '+49 30 1234-100', 'mobile' => '+49 171 2345678', 'department' => 'Verwaltung'],
            ['id' => 'demo-contact-2', 'given' => 'Markus', 'surname' => 'Vogel', 'company' => 'Möbel Beispiel GmbH', 'title' => 'Vertrieb', 'email' => 'm.vogel@moebel-beispiel.de', 'phone' => '+49 40 555-220', 'mobile' => '', 'department' => ''],
            ['id' => 'demo-contact-3', 'given' => 'Daniel', 'surname' => 'Andre', 'company' => 'Beispiel-Verwaltung', 'title' => 'IT-Koordination', 'email' => 'daniel.andre@example.org', 'phone' => '+49 30 1234-200', 'mobile' => '+49 160 9876543', 'department' => 'IT'],
            ['id' => 'demo-contact-4', 'given' => 'Lena', 'surname' => 'Hoffmann', 'company' => 'Beispiel-Verwaltung', 'title' => 'Controlling', 'email' => 'lena.hoffmann@example.org', 'phone' => '+49 30 1234-310', 'mobile' => '', 'department' => 'Finanzen'],
            ['id' => 'demo-contact-5', 'given' => 'Tobias', 'surname' => 'Schmidt', 'company' => 'IT-Service', 'title' => 'Systemadministrator', 'email' => 'it-service@example.org', 'phone' => '+49 30 1234-400', 'mobile' => '+49 152 1122334', 'department' => 'IT'],
        ];
    }

    private function contacts(): string
    {
        $out = '';
        foreach ($this->sampleContacts() as $contact) {
            $out .= $this->contactXml($contact);
        }

        return $this->envelope('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="5" IncludesLastItemInRange="true"><t:Items>' . $out . '</t:Items></m:RootFolder></m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }

    /**
     * @param array<string,string> $c
     */
    private function contactXml(array $c): string
    {
        $e = static fn (string $value): string => EwsXml::escape($value);

        return '<t:Contact><t:ItemId Id="' . $c['id'] . '" ChangeKey="CK1"/><t:Body BodyType="Text">Kontakt aus dem Demomodus.</t:Body><t:FileAs>' . $e($c['surname'] . ', ' . $c['given']) . '</t:FileAs><t:DisplayName>' . $e($c['given'] . ' ' . $c['surname']) . '</t:DisplayName>'
            . '<t:GivenName>' . $e($c['given']) . '</t:GivenName><t:CompanyName>' . $e($c['company']) . '</t:CompanyName>'
            . '<t:EmailAddresses><t:Entry Key="EmailAddress1">' . $e($c['email']) . '</t:Entry></t:EmailAddresses>'
            . '<t:PhysicalAddresses><t:Entry Key="Business"><t:Street>Musterstraße 1</t:Street><t:City>Berlin</t:City><t:PostalCode>10115</t:PostalCode><t:CountryOrRegion>Deutschland</t:CountryOrRegion></t:Entry></t:PhysicalAddresses>'
            . '<t:PhoneNumbers><t:Entry Key="BusinessPhone">' . $e($c['phone']) . '</t:Entry><t:Entry Key="MobilePhone">' . $e($c['mobile']) . '</t:Entry></t:PhoneNumbers>'
            . '<t:Department>' . $e($c['department']) . '</t:Department><t:JobTitle>' . $e($c['title']) . '</t:JobTitle><t:Surname>' . $e($c['surname']) . '</t:Surname></t:Contact>';
    }

    /**
     * @return list<array{id:string,subject:string,due:int,status:string,percent:int,importance:string,body:string}>
     */
    private function sampleTasks(): array
    {
        $today = strtotime('today');

        return [
            ['id' => 'demo-task-1', 'subject' => 'Statusbericht Q3 fertigstellen', 'due' => $today + 86400, 'status' => 'InProgress', 'percent' => 60, 'importance' => 'High', 'body' => 'Kennzahlen aus dem Controlling einarbeiten.'],
            ['id' => 'demo-task-2', 'subject' => 'Notfallplan Kapitel 4 prüfen', 'due' => $today + 3 * 86400, 'status' => 'NotStarted', 'percent' => 0, 'importance' => 'Normal', 'body' => 'Abgleich mit den Maßnahmen aus der Dienstbesprechung.'],
            ['id' => 'demo-task-3', 'subject' => 'Angebot Büromöbel freigeben', 'due' => $today + 7 * 86400, 'status' => 'WaitingOnOthers', 'percent' => 25, 'importance' => 'Normal', 'body' => 'Rückmeldung der Lieferzeit abwarten.'],
            ['id' => 'demo-task-4', 'subject' => 'Schulungsunterlagen verteilen', 'due' => $today - 86400, 'status' => 'Completed', 'percent' => 100, 'importance' => 'Low', 'body' => ''],
        ];
    }

    private function tasks(): string
    {
        $out = '';
        foreach ($this->sampleTasks() as $task) {
            $out .= $this->taskXml($task);
        }

        return $this->envelope('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="4" IncludesLastItemInRange="true"><t:Items>' . $out . '</t:Items></m:RootFolder></m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }

    /**
     * @param array<string,mixed> $task
     */
    private function taskXml(array $task): string
    {
        return '<t:Task><t:ItemId Id="' . $task['id'] . '" ChangeKey="CK1"/><t:Subject>' . EwsXml::escape($task['subject']) . '</t:Subject><t:Body BodyType="Text">' . EwsXml::escape($task['body']) . '</t:Body>'
            . '<t:Importance>' . $task['importance'] . '</t:Importance><t:DueDate>' . EwsXml::dateTime($task['due']) . '</t:DueDate><t:IsComplete>' . ($task['status'] === 'Completed' ? 'true' : 'false') . '</t:IsComplete>'
            . '<t:PercentComplete>' . $task['percent'] . '</t:PercentComplete><t:Status>' . $task['status'] . '</t:Status></t:Task>';
    }

    /**
     * @return list<array{id:string,subject:string,body:string,modified:int,color:string}>
     */
    private function sampleNotes(): array
    {
        return [
            ['id' => 'demo-note-1', 'subject' => 'Zugangsdaten Schulungsraum', 'body' => "Zugangsdaten Schulungsraum\nBeamer: HDMI 2, Fernbedienung im Schrank links.\nWLAN: Gast-Netz, Voucher beim Empfang.", 'modified' => time() - 3600, 'color' => '3'],
            ['id' => 'demo-note-2', 'subject' => 'Ideen Intranet', 'body' => "Ideen Intranet\n- Kachel für Orvanta auf der Startseite\n- Schnellsuche im Kopfbereich\n- Dunkles Design", 'modified' => time() - 86400 * 2, 'color' => '0'],
            ['id' => 'demo-note-3', 'subject' => 'Rückruf Herr Vogel', 'body' => "Rückruf Herr Vogel\nLieferzeit Büromöbel klären, Angebot bis Freitag.", 'modified' => time() - 86400 * 5, 'color' => '1'],
        ];
    }

    private function notes(): string
    {
        $out = '';
        foreach ($this->sampleNotes() as $note) {
            $out .= '<t:Message><t:ItemId Id="' . $note['id'] . '" ChangeKey="CK1"/><t:Subject>' . EwsXml::escape($note['subject']) . '</t:Subject><t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x8b00" PropertyType="Integer"/><t:Value>' . $note['color'] . '</t:Value></t:ExtendedProperty><t:LastModifiedTime>' . EwsXml::dateTime($note['modified']) . '</t:LastModifiedTime><t:Preview>' . EwsXml::escape(mb_substr($note['body'], 0, 120)) . '</t:Preview></t:Message>';
        }

        return $this->envelope('<m:FindItemResponse><m:ResponseMessages><m:FindItemResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:RootFolder TotalItemsInView="3" IncludesLastItemInRange="true"><t:Items>' . $out . '</t:Items></m:RootFolder></m:FindItemResponseMessage></m:ResponseMessages></m:FindItemResponse>');
    }
}
