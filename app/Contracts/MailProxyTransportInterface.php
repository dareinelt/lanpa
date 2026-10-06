<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Transport zum internen SMTP-/IMAP-Proxy (Container mail-proxy). Austauschbar
 * fuer Tests (Fake mit simuliertem IMAP-/SMTP-Postfach) und den Betrieb
 * (HTTP im internen Docker-Netz, HMAC-signiert).
 *
 * Fehler werden als App\Services\Orvanta\OrvantaException mit einer fuer die
 * Anzeige geeigneten Meldung (ohne Zugangsdaten) geworfen.
 */
interface MailProxyTransportInterface
{
    /**
     * Fuehrt eine Proxy-Operation aus (z. B. "imap.folders", "smtp.send").
     *
     * @param array<string,mixed> $payload Enthaelt unter "account" die Verbindungsdaten
     *                                     inkl. entschluesseltem Passwort (nur im Speicher).
     *
     * @return array<string,mixed>
     */
    public function request(string $operation, array $payload): array;

    /**
     * Erreichbarkeit des Proxy-Dienstes (ohne Anmeldung an einem Mailserver).
     *
     * @return array{ok:bool,message:string,details:array<string,mixed>}
     */
    public function health(): array;
}
