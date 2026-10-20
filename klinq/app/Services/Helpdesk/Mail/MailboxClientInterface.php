<?php

declare(strict_types=1);

namespace App\Services\Helpdesk\Mail;

/**
 * Quelle eingehender E-Mails (IMAP-Postfach oder Verzeichnis mit .eml-Dateien).
 * Der Aufrufer holt ungelesene Nachrichten, verarbeitet sie und bestätigt jede einzeln.
 */
interface MailboxClientInterface
{
    /** Menschlich lesbare Beschreibung der Quelle (für Logs/CLI). */
    public function describe(): string;

    /**
     * Liefert bis zu $limit unverarbeitete Nachrichten als Roh-RFC822 (uid => raw).
     * @return array<string,string>
     */
    public function fetchUnprocessed(int $limit): array;

    /**
     * Verfügbare Postfachverzeichnisse (für die Auswahl des Zielordners in der Administration).
     * @return list<string>
     */
    public function listMailboxes(): array;

    /** Nachricht als verarbeitet markieren (gelesen setzen und ggf. in Zielordner verschieben). */
    public function markProcessed(string $uid): void;

    /**
     * Nachricht als fehlgeschlagen markieren – bleibt zur manuellen Prüfung erhalten,
     * wird aber nicht erneut abgeholt (gelesen + Flag).
     */
    public function markFailed(string $uid): void;

    public function close(): void;
}
