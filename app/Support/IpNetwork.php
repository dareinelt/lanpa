<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quellnetze in CIDR-Schreibweise (IPv4 und IPv6) fuer die Angabe, welche
 * Clients ein Weiterleitungsziel direkt statt ueber den Reverse-Proxy aufrufen.
 *
 * Die Adresse wird auf die Netzgrenze gerundet: "10.0.0.5/24" wird zu
 * "10.0.0.0/24". Eine Adresse ohne Praefix gilt als einzelner Host (/32 bzw.
 * /128). Es werden keine zusaetzlichen Erweiterungen benoetigt (inet_pton).
 */
final class IpNetwork
{
    /**
     * Zerlegt eine Liste (Leerzeichen, Komma, Semikolon, Zeilenumbruch) in
     * normalisierte Netze. Ungueltige Angaben werden verworfen.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $networks = [];
        foreach (self::split($raw) as $entry) {
            $normalized = self::normalize($entry);
            if ($normalized !== null && !in_array($normalized, $networks, true)) {
                $networks[] = $normalized;
            }
        }

        return $networks;
    }

    /**
     * Wie parseList, liefert zusaetzlich die verworfenen Angaben.
     *
     * @return array{networks:list<string>,invalid:list<string>}
     */
    public static function parseListDetailed(string $raw): array
    {
        $networks = [];
        $invalid = [];
        foreach (self::split($raw) as $entry) {
            $normalized = self::normalize($entry);
            if ($normalized === null) {
                $invalid[] = $entry;
            } elseif (!in_array($normalized, $networks, true)) {
                $networks[] = $normalized;
            }
        }

        return ['networks' => $networks, 'invalid' => $invalid];
    }

    /**
     * Normalisierte Schreibweise (z. B. "10.0.0.0/24", "2001:db8::/32")
     * oder null bei ungueltiger Angabe.
     */
    public static function normalize(string $network): ?string
    {
        $network = trim($network);
        if ($network === '') {
            return null;
        }

        $prefix = null;
        if (str_contains($network, '/')) {
            $parts = explode('/', $network, 2);
            $network = $parts[0];
            if (preg_match('/^[0-9]{1,3}$/', $parts[1]) !== 1) {
                return null;
            }
            $prefix = (int) $parts[1];
        }

        $packed = self::pack($network);
        if ($packed === null) {
            return null;
        }

        $bits = strlen($packed) * 8;
        $prefix ??= $bits;
        if ($prefix < 0 || $prefix > $bits) {
            return null;
        }

        $address = inet_ntop(self::mask($packed, $prefix));

        return $address === false ? null : $address . '/' . $prefix;
    }

    /**
     * Grenzen eines Netzes, aus dem Praefix errechnet: erste (Netz-) und
     * letzte (Broadcast-)Adresse. "192.168.200.0/21" reicht damit von
     * 192.168.200.0 bis 192.168.207.255 (2048 Adressen).
     *
     * @return array{first:string,last:string,prefix:int,host_bits:int,addresses:int|float}|null
     */
    public static function bounds(string $network): ?array
    {
        $normalized = self::normalize($network);
        if ($normalized === null) {
            return null;
        }

        [$address, $prefix] = explode('/', $normalized, 2);
        $packed = self::pack($address);
        if ($packed === null) {
            return null;
        }

        $hostBits = strlen($packed) * 8 - (int) $prefix;
        $first = inet_ntop(self::mask($packed, (int) $prefix));
        $last = inet_ntop(self::broadcast($packed, (int) $prefix));
        if ($first === false || $last === false) {
            return null;
        }

        return [
            'first' => $first,
            'last' => $last,
            'prefix' => (int) $prefix,
            'host_bits' => $hostBits,
            'addresses' => 2 ** $hostBits,
        ];
    }

    /**
     * Prueft, ob die Adresse in einem der Netze liegt. Ohne Netze: false.
     *
     * @param list<string> $networks
     */
    public static function matchesAny(array $networks, string $ip): bool
    {
        foreach ($networks as $network) {
            if (self::contains((string) $network, $ip)) {
                return true;
            }
        }

        return false;
    }

    public static function contains(string $network, string $ip): bool
    {
        $normalized = self::normalize($network);
        $packedIp = self::pack($ip);
        if ($normalized === null || $packedIp === null) {
            return false;
        }

        $parts = explode('/', $normalized, 2);
        $packedBase = self::pack($parts[0]);
        if ($packedBase === null || strlen($packedBase) !== strlen($packedIp)) {
            return false;
        }

        return self::mask($packedIp, (int) $parts[1]) === $packedBase;
    }

    /**
     * @return list<string>
     */
    private static function split(string $raw): array
    {
        $entries = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return $entries === false ? [] : array_values($entries);
    }

    private static function pack(string $address): ?string
    {
        $address = trim($address);
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($address);

        return $packed === false ? null : $packed;
    }

    /**
     * Setzt alle Bits hinter dem Praefix auf 1 (Broadcast-Adresse des Netzes).
     */
    private static function broadcast(string $packed, int $prefix): string
    {
        $bytes = strlen($packed);
        $result = '';
        for ($index = 0; $index < $bytes; $index++) {
            $remaining = $prefix - $index * 8;
            if ($remaining >= 8) {
                $result .= $packed[$index];
            } elseif ($remaining <= 0) {
                $result .= "\xFF";
            } else {
                $result .= chr(ord($packed[$index]) | (0xFF >> $remaining));
            }
        }

        return $result;
    }

    /**
     * Setzt alle Bits hinter dem Praefix auf 0 (Netzgrenze).
     */
    private static function mask(string $packed, int $prefix): string
    {
        $bytes = strlen($packed);
        $masked = '';
        for ($index = 0; $index < $bytes; $index++) {
            $remaining = $prefix - $index * 8;
            if ($remaining >= 8) {
                $masked .= $packed[$index];
            } elseif ($remaining <= 0) {
                $masked .= "\x00";
            } else {
                $masked .= chr(ord($packed[$index]) & (0xFF << (8 - $remaining)) & 0xFF);
            }
        }

        return $masked;
    }
}
