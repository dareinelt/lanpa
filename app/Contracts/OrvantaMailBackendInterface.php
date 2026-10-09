<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Mail-Backend von Orvanta: Exchange/EWS (Standard, OrvantaExchangeService)
 * oder SMTP-/IMAP-Proxy (ProxyMailBackend). Welches Backend ein Benutzer
 * erhaelt, entscheidet OrvantaMailRouter anhand der Postfach-Zuordnung.
 *
 * $user ist die Postfachadresse, mit der Orvanta den Benutzer anspricht
 * (bei Exchange die Impersonation-Adresse, beim Proxy die Adresse des
 * zugeordneten Postfachs). Rueckgabeformate entsprechen der EWS-Umsetzung,
 * damit orvanta.js unveraendert bleibt.
 */
interface OrvantaMailBackendInterface
{
    public const CAPABILITY_MAIL = 'mail';
    public const CAPABILITY_CALENDAR = 'calendar';
    public const CAPABILITY_CONTACTS = 'contacts';
    public const CAPABILITY_TASKS = 'tasks';
    public const CAPABILITY_NOTES = 'notes';
    public const CAPABILITY_REMINDERS = 'reminders';
    public const CAPABILITY_ARCHIVE = 'archive';

    /** Abwesenheitsnotiz (Out-of-Office): nur Exchange/EWS. */
    public const CAPABILITY_OOF = 'oof';

    /** Kennung des Backends ('exchange' oder 'proxy'). */
    public function backendName(): string;

    /**
     * Unterstuetzte Funktionen (Schluessel = CAPABILITY_*).
     *
     * @return array<string,bool>
     */
    public function capabilities(): array;

    /**
     * @return list<array{id:string,name:string,parent:string,total:int,unread:int,kind:string,class:string}>
     */
    public function folders(string $user): array;

    /**
     * @return array{id:string,name:string}
     */
    public function createFolder(string $user, string $parent, string $name): array;

    public function markFolderRead(string $user, string $folder): void;

    /**
     * @return array{id:string,name:string,total:int,unread:int,subfolders:int,size:int,total_with_subfolders:int,size_with_subfolders:int}
     */
    public function folderProperties(string $user, string $folder): array;

    /**
     * @return array{items:list<array<string,mixed>>,total:int,offset:int,has_more:bool}
     */
    public function messages(string $user, string $folder, int $offset = 0, int $limit = 50, string $search = ''): array;

    /**
     * @return array<string,mixed>
     */
    public function message(string $user, string $id): array;

    /**
     * @return array{id:string,subject:string,headers:string,source:string}
     */
    public function messageHeaders(string $user, string $id): array;

    /**
     * @param array<string,mixed> $mail
     * @return array{id:string}
     */
    public function send(string $user, array $mail, string $draftId = '', string $changeKey = ''): array;

    /**
     * @param array<string,mixed> $mail
     * @return array{id:string,change_key:string}
     */
    public function saveDraft(string $user, array $mail, string $draftId = '', string $changeKey = ''): array;

    /**
     * @param array<string,mixed> $mail
     * @return array{id:string}
     */
    public function respond(string $user, string $id, string $mode, array $mail): array;

    /**
     * @param list<string> $ids
     */
    public function markRead(string $user, array $ids, bool $read): void;

    /**
     * @param list<string> $ids
     */
    public function flag(string $user, array $ids, bool $flagged): void;

    /**
     * @param list<string> $ids
     */
    public function move(string $user, array $ids, string $folder): void;

    /**
     * @param list<string> $ids
     */
    public function delete(string $user, array $ids, bool $permanent = false): void;

    /**
     * @return array{name:string,content_type:string,content:string,size:int}
     */
    public function attachment(string $user, string $attachmentId): array;

    /**
     * @param array{warning:int,send:int,receive:int}|null $directory
     * @return array{used:int,quota:int,warning:int,receive_limit:int,limit:int,percent:int,source:string}
     */
    public function mailboxUsage(string $user, ?array $directory = null): array;
}
