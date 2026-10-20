<?php

declare(strict_types=1);

namespace App\Services\Ad;

use App\Core\Config;
use App\Core\Logger;
use App\Services\Ldap\LdapClientInterface;

/**
 * Einzelabfrage eines Kontos im Active Directory (ohne Synchronisation).
 *
 * Wird für den Live-Abgleich genutzt, wenn ein Vorgang nur den Anmeldenamen kennt
 * (z. B. Störungsformular): liefert Telefonnummer, E-Mail, Abteilung und Co.
 * Fehler (AD nicht erreichbar, Konto unbekannt) führen nie zu einem Abbruch, sondern zu null.
 */
final class AdUserLookupService
{
    /** Anmeldenamen sind auf unkritische Zeichen begrenzt – damit entfällt LDAP-Filter-Injection. */
    private const USERNAME_PATTERN = '/^[a-zA-Z0-9._\-]{1,120}$/';

    public function __construct(
        private readonly Config $config,
        private readonly LdapClientInterface $ldap,
        private readonly AdUserMapper $mapper,
        private readonly Logger $logger
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('ldap.enabled');
    }

    /**
     * Konto anhand des Anmeldenamens (sAMAccountName) suchen.
     * @return array<string,mixed>|null Felder wie AdUserMapper::map(), sonst null
     */
    public function findByUsername(string $username): ?array
    {
        $username = trim($username);
        if (!$this->isEnabled() || preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            return null;
        }

        try {
            $entries = $this->search($username);
        } catch (\Throwable $e) {
            $this->logger->warning('AD-Abgleich fehlgeschlagen', ['username' => $username, 'error' => $e->getMessage()]);

            return null;
        } finally {
            $this->ldap->close();
        }

        foreach ($entries as $entry) {
            $mapped = $this->mapper->map($entry);
            // Der Fake-Treiber ignoriert den Filter, deshalb wird der Name immer gegengeprüft
            if ($mapped !== null && strcasecmp((string) ($mapped['username'] ?? ''), $username) === 0) {
                return $mapped;
            }
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    private function search(string $username): array
    {
        $bindDn = (string) $this->config->get('ldap.bind_dn', '');
        if ($bindDn !== '' && !$this->ldap->bind($bindDn, (string) $this->config->get('ldap.bind_password', ''))) {
            throw new \RuntimeException('LDAP-Bind mit dem Dienstkonto fehlgeschlagen.');
        }
        $attribute = (string) $this->config->get('ldap.attributes.username', 'sAMAccountName');
        $userFilter = (string) $this->config->get('ldap.user_filter', '(&(objectClass=user)(objectCategory=person))');
        $filter = sprintf('(&%s(%s=%s))', $userFilter, $attribute, $username);

        return $this->ldap->search((string) $this->config->get('ldap.base_dn', ''), $filter, $this->mapper->ldapAttributes());
    }
}
