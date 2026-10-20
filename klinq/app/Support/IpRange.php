<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prüft, ob eine IP-Adresse zu einer Liste aus Einzeladressen oder CIDR-Netzen gehört
 * (IPv4 und IPv6). Wird für die Netzbeschränkung des Störungsformulars genutzt.
 */
final class IpRange
{
    /** @param array<int,string> $networks Einzel-IPs oder CIDR-Notation (z. B. 10.0.0.0/8, 2001:db8::/32) */
    public static function matchesAny(string $ip, array $networks): bool
    {
        foreach ($networks as $network) {
            if (self::matches($ip, (string) $network)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $network): bool
    {
        $ip = trim($ip);
        $network = trim($network);
        if ($ip === '' || $network === '') {
            return false;
        }
        $binaryIp = @inet_pton($ip);
        if ($binaryIp === false) {
            return false;
        }
        [$subnet, $bits] = array_pad(explode('/', $network, 2), 2, null);
        $binarySubnet = @inet_pton((string) $subnet);
        if ($binarySubnet === false || strlen($binaryIp) !== strlen($binarySubnet)) {
            return false;
        }
        if ($bits === null || $bits === '') {
            return $binaryIp === $binarySubnet;
        }
        $bits = (int) $bits;
        $maxBits = strlen($binaryIp) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && strncmp($binaryIp, $binarySubnet, $fullBytes) !== 0) {
            return false;
        }
        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }
        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($binaryIp[$fullBytes]) & $mask) === (ord($binarySubnet[$fullBytes]) & $mask);
    }
}
