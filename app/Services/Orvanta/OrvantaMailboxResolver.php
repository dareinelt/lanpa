<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Core\Logger;
use App\Security\Session;
use App\Services\IdentitySourceService;
use App\Services\LdapClient;
use Throwable;

/**
 * Postfachadresse des angemeldeten Benutzers fuer die Exchange-Impersonation.
 *
 * Die Konfiguration nennt die Adresse aus dem AD-Attribut "mail" (Standard)
 * oder den Anmeldenamen mit UPN-Domaene. Beides ist nur dann eine
 * Postfach-Kennung, wenn Exchange die Adresse als Alias oder UPN kennt – bei
 * neu angelegten Konten fehlt beides, und EWS antwortet mit
 * ErrorNonExistentMailbox, obwohl das Postfach existiert.
 *
 * Massgeblich ist daher die primaere SMTP-Adresse des Postfachs aus dem AD
 * (proxyAddresses, Praefix "SMTP:"). Sie wird je Sitzung zwischengespeichert
 * (kein AD-Zugriff je Anfrage). Ohne AD-Treffer – kein Postfach, AD nicht
 * erreichbar, Quelle unbekannt, Demo-/Testbenutzer – gilt weiter die
 * Konfiguration, das Verhalten bleibt dort also unveraendert.
 */
final class OrvantaMailboxResolver
{
    /** Gueltigkeit der aus dem AD gelesenen Adresse in der Sitzung (Sekunden). */
    private const TTL = 900;

    private const SESSION_KEY = 'orvanta_mailbox_address';

    public function __construct(
        private readonly OrvantaConfigService $config,
        private readonly IdentitySourceService $sources,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * @param array<string,mixed> $ssoUser SSO-Benutzer (username, email, source_id, …)
     */
    public function address(array $ssoUser): string
    {
        $configured = $this->config->impersonationAddress($ssoUser);
        $username = (string) ($ssoUser['username'] ?? '');
        if ($username === '' || $this->config->isDemo() || !LdapClient::isSupported()) {
            return $configured;
        }
        // Testbenutzer ohne Telefonbucheintrag existieren im AD nicht.
        if (!empty($ssoUser['fake']) && (int) ($ssoUser['id'] ?? 0) === 0) {
            return $configured;
        }

        $primary = $this->fromDirectory((int) ($ssoUser['source_id'] ?? 0), $username);
        if ($primary === null) {
            return $configured;
        }
        if (strcasecmp($primary, $configured) !== 0) {
            $this->logger?->info('Orvanta: Postfachadresse aus dem AD verwendet.', [
                'user' => $username,
                'configured' => $configured,
                'primary' => $primary,
            ]);
        }

        return $primary;
    }

    /**
     * Primaere SMTP-Adresse aus der Identitaetsquelle des Benutzers, je
     * Sitzung zwischengespeichert. null = nicht ermittelbar.
     */
    private function fromDirectory(int $sourceId, string $username): ?string
    {
        $key = $sourceId . ':' . strtolower($username);
        $cached = Session::get(self::SESSION_KEY);
        if (is_array($cached) && ($cached['key'] ?? '') === $key && (int) ($cached['at'] ?? 0) > time() - self::TTL) {
            $address = (string) ($cached['address'] ?? '');

            return $address !== '' ? $address : null;
        }

        $address = null;
        try {
            foreach ($this->sources->configs() as $config) {
                if ((int) ($config['id'] ?? -1) === $sourceId) {
                    $address = (new LdapClient($config, $this->logger))->primaryMailboxAddress($username);
                    break;
                }
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('Orvanta: Postfachadresse konnte nicht aus dem AD gelesen werden.', ['error' => $exception->getMessage()]);
        }
        Session::put(self::SESSION_KEY, ['key' => $key, 'at' => time(), 'address' => (string) ($address ?? '')]);

        return $address;
    }
}
