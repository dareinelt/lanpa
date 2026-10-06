<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

use App\Core\Logger;
use App\Repositories\MailProxyRepository;
use App\Security\SecretBox;
use App\Services\Orvanta\OrvantaException;

/**
 * Fachliche Aufloesung beim Oeffnen von Orvanta:
 *
 *   SSO-Benutzer (phonebook.id, Quelle)
 *     -> Zuordnung derselben Identitaetsquelle
 *     -> Postfach -> Mailserver der Identitaetsquelle
 *
 * Regeln (siehe docs/mail-proxy.md, Fehlerverhalten):
 *  - keine Zuordnung, Benutzer ohne Telefonlisten-Eintrag (id 0),
 *    Quellen passen nicht zusammen, Benutzer inaktiv, Identitaetsquelle
 *    geloescht/deaktiviert, Mailserver deaktiviert -> Exchange (Standard)
 *  - Postfach deaktiviert -> blocked (keine Verbindung, kein Rueckfall)
 *  - sonst -> proxy
 *
 * Das Ergebnis (ohne Zugangsdaten) wird pro Benutzer gecacht; Zugangsdaten
 * werden bei jedem Verbindungsaufbau frisch gelesen und erneut geprueft.
 */
final class MailProxyResolver
{
    public function __construct(
        private readonly MailProxyRepository $repository,
        private readonly MailProxyCache $cache,
        private readonly SecretBox $secrets,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<string,mixed> $ssoUser SsoAuth-Benutzer (id, source_id, source_key, …)
     */
    public function resolve(array $ssoUser): MailProxyRoute
    {
        $phonebookId = (int) ($ssoUser['id'] ?? 0);
        $sourceId = (int) ($ssoUser['source_id'] ?? -1);
        if ($phonebookId <= 0 || $sourceId < 0) {
            return MailProxyRoute::exchange('Kein synchronisierter AD-Benutzer.');
        }

        try {
            $generation = $this->repository->generation();
        } catch (\PDOException) {
            // Tabellen fehlen (Migration 039 ausstehend): bestehendes Verhalten.
            return MailProxyRoute::exchange('Proxy-Tabellen nicht vorhanden.');
        }
        $key = 'route:' . $sourceId . ':' . $phonebookId;
        $cached = $this->cache->get($key, $generation);
        if ($cached !== null) {
            $route = MailProxyRoute::fromArray($cached);
            if ($route !== null) {
                return $route;
            }
        }

        $route = $this->decide($sourceId, $phonebookId, $this->repository->resolutionRow($sourceId, $phonebookId));
        $this->cache->put($key, $generation, $route->toArray());
        if ($route->state !== MailProxyRoute::EXCHANGE || $route->reason !== 'Keine Zuordnung.') {
            $this->logger->info('mail-proxy mapping resolved', [
                'identity_source' => (string) ($ssoUser['source_key'] ?? '') !== '' ? (string) $ssoUser['source_key'] : 'primary',
                'user' => $phonebookId,
                'state' => $route->state,
                'mailbox_id' => $route->mailboxId,
                'reason' => $route->reason,
            ]);
        }

        return $route;
    }

    /**
     * Entscheidungsregeln (rein, ohne Seiteneffekte).
     *
     * @param array<string,mixed>|null $row
     */
    public function decide(int $sourceId, int $phonebookId, ?array $row): MailProxyRoute
    {
        if ($row === null) {
            return MailProxyRoute::exchange('Keine Zuordnung.');
        }
        if ((int) $row['phonebook_id'] !== $phonebookId
            || (int) $row['mapping_source_id'] !== $sourceId
            || (int) $row['user_source_id'] !== $sourceId
            || (int) $row['server_source_id'] !== $sourceId) {
            // Schutz gegen Zuordnungen ueber Quellgrenzen hinweg.
            return MailProxyRoute::exchange('Identitätsquelle der Zuordnung passt nicht.');
        }
        if ((int) $row['user_active'] !== 1) {
            return MailProxyRoute::exchange('Benutzer ist nicht mehr aktiv.');
        }
        if ($sourceId > 0 && ($row['source_row_id'] === null || (int) $row['source_active'] !== 1)) {
            return MailProxyRoute::exchange('Identitätsquelle nicht mehr vorhanden oder deaktiviert.');
        }
        if ((int) $row['server_active'] !== 1) {
            return MailProxyRoute::exchange('Proxy-Konfiguration der Identitätsquelle ist deaktiviert.');
        }
        if ((int) $row['mailbox_active'] !== 1) {
            return MailProxyRoute::blocked((int) $row['mailbox_id'], $sourceId, 'Das zugeordnete Postfach ist deaktiviert.');
        }

        return MailProxyRoute::proxy((int) $row['mailbox_id'], (int) $row['server_id'], $sourceId, (string) $row['email_address']);
    }

    /**
     * Frische Verbindungsdaten fuer eine Proxy-Route (inkl. erneuter
     * Aktiv-Pruefung und Entschluesselung). Wirft bei jeder Abweichung,
     * statt eine andere Verbindung zu verwenden.
     */
    public function account(MailProxyRoute $route, bool $allowInactive = false): MailProxyAccount
    {
        if (!$route->isProxy()) {
            throw new OrvantaException($route->reason !== '' ? $route->reason : 'Für dieses Konto ist kein Proxy-Postfach zugeordnet.', 403);
        }
        $row = $this->repository->connectionRow($route->mailboxId);
        if ($row === null
            || (int) $row['server_id'] !== $route->serverId
            || (int) $row['identity_source_id'] !== $route->sourceId
            || strcasecmp((string) $row['email_address'], $route->email) !== 0) {
            throw new OrvantaException('Die Postfach-Zuordnung wurde geändert. Bitte Orvanta neu laden.', 409);
        }
        if (!$allowInactive && ((int) $row['mailbox_active'] !== 1 || (int) $row['server_active'] !== 1)) {
            throw new OrvantaException('Das zugeordnete Postfach ist deaktiviert.', 403);
        }
        $password = $this->secrets->decrypt((string) $row['password_encrypted']);
        if ($password === null || $password === '') {
            $this->logger->error('mail-proxy credentials unavailable', ['mailbox_id' => $route->mailboxId]);
            throw new OrvantaException('Die Zugangsdaten des Postfachs sind nicht lesbar. Bitte die Administration informieren.', 503);
        }

        return new MailProxyAccount(
            (int) $row['mailbox_id'],
            (int) $row['server_id'],
            (int) $row['identity_source_id'],
            (string) $row['username'],
            (string) $row['email_address'],
            (string) $row['display_name'],
            $password,
            [
                'smtp_host' => (string) $row['smtp_host'],
                'smtp_port' => (int) $row['smtp_port'],
                'smtp_security' => (string) $row['smtp_security'],
                'smtp_auth' => (int) $row['smtp_auth'] === 1,
                'imap_host' => (string) $row['imap_host'],
                'imap_port' => (int) $row['imap_port'],
                'imap_security' => (string) $row['imap_security'],
                'verify_tls' => (int) $row['verify_tls'] === 1,
                'timeout' => (int) $row['timeout_seconds'],
            ],
            $this->repository->generation()
        );
    }

    /**
     * Postfach-Verbindung fuer den Verbindungstest im Adminbereich (ohne
     * Zuordnung; Postfach und Server muessen nicht aktiv sein).
     */
    public function accountForTest(int $mailboxId): MailProxyAccount
    {
        $row = $this->repository->connectionRow($mailboxId);
        if ($row === null) {
            throw new OrvantaException('Das Postfach wurde nicht gefunden.', 404);
        }

        return $this->account(MailProxyRoute::proxy($mailboxId, (int) $row['server_id'], (int) $row['identity_source_id'], (string) $row['email_address']), true);
    }
}
