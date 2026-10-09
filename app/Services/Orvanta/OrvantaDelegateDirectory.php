<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Core\Logger;
use App\Security\Session;
use App\Services\IdentitySourceService;
use App\Services\LdapClient;
use Closure;
use Throwable;

/**
 * Automatisch eingebundene Postfaecher eines Benutzers aus dem Active
 * Directory (Auto-Mapping).
 *
 * Wer auf dem Exchange-Server Vollzugriff auf ein weiteres Postfach erhaelt
 * (ECP bzw. Add-MailboxPermission, Auto-Mapping ist Standard), wird im AD am
 * Postfach in msExchDelegateListLink eingetragen; Outlook liest den
 * Rueckverweis msExchDelegateListBL des Benutzers und blendet die Postfaecher
 * automatisch ein. Orvanta nutzt denselben Weg: Die Zuordnung wird nicht im
 * Intranet gepflegt, sondern aus der Identitaetsquelle des Benutzers gelesen
 * (LdapClient::delegatedMailboxes()) und je Sitzung zwischengespeichert.
 *
 * null = nicht ermittelbar (Demo-Modus, kein LDAP, Testbenutzer, Quelle
 * unbekannt, AD nicht erreichbar): Der bisherige Bestand bleibt unveraendert.
 */
final class OrvantaDelegateDirectory
{
    /** Gueltigkeit der aus dem AD gelesenen Liste in der Sitzung (Sekunden). */
    private const TTL = 900;

    private const SESSION_KEY = 'orvanta_delegate_mailboxes';

    /**
     * @param (Closure(array<string,mixed>,string):(list<array{email:string,name:string}>|null))|null $lookup
     *        Ersatz fuer den LDAP-Zugriff (Tests): erhaelt Quellen-Konfiguration und Anmeldenamen
     */
    public function __construct(
        private readonly OrvantaConfigService $config,
        private readonly IdentitySourceService $sources,
        private readonly ?Logger $logger = null,
        private readonly ?Closure $lookup = null
    ) {
    }

    /**
     * @param array<string,mixed> $ssoUser SSO-Benutzer (username, source_id, …)
     * @return list<array{email:string,name:string}>|null
     */
    public function mailboxes(array $ssoUser): ?array
    {
        $username = trim((string) ($ssoUser['username'] ?? ''));
        if ($username === '' || $this->config->isDemo()) {
            return null;
        }
        if ($this->lookup === null && !LdapClient::isSupported()) {
            return null;
        }
        // Testbenutzer ohne Telefonbucheintrag existieren im AD nicht.
        if (!empty($ssoUser['fake']) && (int) ($ssoUser['id'] ?? 0) === 0) {
            return null;
        }

        $sourceId = (int) ($ssoUser['source_id'] ?? 0);
        $key = $sourceId . ':' . strtolower($username);
        $cached = Session::get(self::SESSION_KEY);
        if (is_array($cached) && ($cached['key'] ?? '') === $key && (int) ($cached['at'] ?? 0) > time() - self::TTL) {
            return is_array($cached['mailboxes'] ?? null) ? $cached['mailboxes'] : null;
        }

        $mailboxes = null;
        try {
            foreach ($this->sources->configs() as $config) {
                if ((int) ($config['id'] ?? -1) !== $sourceId) {
                    continue;
                }
                $mailboxes = $this->lookup !== null
                    ? ($this->lookup)($config, $username)
                    : (new LdapClient($config, $this->logger))->delegatedMailboxes($username);
                break;
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('Orvanta: automatisch eingebundene Postfaecher konnten nicht aus dem AD gelesen werden.', [
                'user' => $username,
                'error' => $exception->getMessage(),
            ]);
        }
        Session::put(self::SESSION_KEY, ['key' => $key, 'at' => time(), 'mailboxes' => $mailboxes]);

        return $mailboxes;
    }
}
