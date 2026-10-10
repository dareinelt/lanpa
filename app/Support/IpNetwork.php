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
