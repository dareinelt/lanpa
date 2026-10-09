<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Request;

/**
 * IP-Adresse und Hostname des anfragenden Clients fuer Anzeigen wie die
 * Sitzungsliste der Exchange-DAG (Office -> Orvanta - DAG-Hosts).
 *
 * Die IP-Adresse stammt aus dem Aufruf (Request::clientIp()); der Hostname
 * wird einmalig per Reverse-DNS ermittelt und ist leer, wenn er nicht
 * aufloesbar ist ("wo moeglich").
 */
final class ClientAddress
{
    /** Obergrenze der Spalte orvanta_exchange_sessions.client_host. */
    private const MAX_HOSTNAME = 190;

    /**
     * @return array{ip:string,host:string}
     */
    public static function from(Request $request): array
    {
        $ip = $request->clientIp();
        if ($ip === '') {
            return ['ip' => '', 'host' => ''];
        }

        return ['ip' => $ip, 'host' => self::hostname($ip)];
    }

    /**
     * Reverse-DNS zum Client. Leer, wenn der Name nicht ermittelbar ist oder
     * nur die IP-Adresse zurueckkommt.
     */
    public static function hostname(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $name = @gethostbyaddr($ip);
        if (!is_string($name) || $name === '' || $name === $ip) {
            return '';
        }

        return Validator::cleanText($name, self::MAX_HOSTNAME);
    }
}
