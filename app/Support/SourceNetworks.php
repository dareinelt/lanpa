<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bekannte Quellnetze (Admin → System → Bekannte Quellnetze).
 *
 * Der auth-Container meldet die Anfragen je Quellnetz verfeinert (/24 bei
 * IPv4, /64 bei IPv6, siehe docker/auth/metrics.py). Gehoeren mehrere dieser
 * Netze zu einem groesseren bekannten Netz, werden sie fuer die Statistik zu
 * diesem Netz zusammengefasst. Die Zuordnung wird aus dem Praefix errechnet
 * (Subnetting ueber die Bitmaske), nicht aus Textmustern: 192.168.200.0/21
 * reicht von 192.168.200.0 bis 192.168.207.255 und enthaelt damit die
 * gemeldeten Netze 192.168.204.0/24 und 192.168.206.0/24.
 */
final class SourceNetworks
{
    /** Hoechstzahl bekannter Quellnetze (wie bei den HTTP-Quellnetzen). */
    public const MAX_NETWORKS = 32;

    /**
     * Liest die Einstellung; leer oder "none" bedeutet keine bekannten Netze.
     *
     * @return list<string>
     */
    public static function fromSetting(string $value): array
    {
        $value = trim($value);
        if ($value === '' || $value === 'none') {
            return [];
        }

        return IpNetwork::parseList($value);
    }

    /**
     * Fasst Quellnetze zusammen, die in einem bekannten Netz liegen.
     *
     * @param array<string,int> $sources Quellnetz => Anfragen
     * @param list<string> $knownNetworks bekannte Netze (normalisiert)
     * @return array<string,int>
     */
    public static function merge(array $sources, array $knownNetworks): array
    {
        if ($knownNetworks === []) {
            return $sources;
        }

        $known = self::normalizeKnown($knownNetworks);
        if ($known === []) {
            return $sources;
        }

        $merged = [];
        foreach ($sources as $network => $count) {
            $network = (string) $network;
            $target = self::groupNormalized($network, $known) ?? $network;
            $merged[$target] = ($merged[$target] ?? 0) + (int) $count;
        }

        return $merged;
    }

    /**
     * Bekanntes Netz, in dem das Quellnetz liegt (bei mehreren das genaueste,
     * also das mit dem laengsten Praefix); null, wenn keines passt oder die
     * Angabe kein Netz ist ("lokal", "weitere").
     *
     * @param list<string> $knownNetworks
     */
    public static function group(string $network, array $knownNetworks): ?string
    {
        return self::groupNormalized($network, self::normalizeKnown($knownNetworks));
    }

    /**
     * Wie group(), jedoch mit bereits normalisierten bekannten Netzen - fuer
     * die Auswertung vieler Quellnetze (Verlauf) ohne wiederholte Pruefung.
     *
     * @param list<string> $knownNetworks normalisierte Netze
     */
    private static function groupNormalized(string $network, array $knownNetworks): ?string
    {
        $normalized = IpNetwork::normalize($network);
        if ($normalized === null) {
            return null;
        }

        [$address, $prefix] = explode('/', $normalized, 2);
        $prefix = (int) $prefix;

        $match = null;
        $matchPrefix = -1;
        foreach ($knownNetworks as $known) {
            $knownPrefix = (int) explode('/', $known, 2)[1];
            // Nur ein weiter oder gleich grosses Netz kann das Quellnetz enthalten;
            // bei mehreren Treffern gewinnt das genaueste (laengstes Praefix).
            if ($knownPrefix > $prefix || $knownPrefix <= $matchPrefix) {
                continue;
            }

            if (IpNetwork::contains($known, $address)) {
                $match = $known;
                $matchPrefix = $knownPrefix;
            }
        }

        return $match;
    }

    /**
     * Normalisiert eine Liste bekannter Netze; ungueltige Angaben entfallen.
     *
     * @param list<string> $knownNetworks
     * @return list<string>
     */
    private static function normalizeKnown(array $knownNetworks): array
    {
        $normalized = [];
        foreach ($knownNetworks as $known) {
            $value = IpNetwork::normalize((string) $known);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Beschreibung eines bekannten Netzes fuer den Adminbereich: Bereich von
     * der ersten bis zur letzten Adresse und Zahl der Adressen.
     *
     * @return array{network:string,first:string,last:string,addresses:int|float,host_bits:int}|null
     */
    public static function describe(string $network): ?array
    {
        $normalized = IpNetwork::normalize($network);
        $bounds = IpNetwork::bounds($network);
        if ($normalized === null || $bounds === null) {
            return null;
        }

        return [
            'network' => $normalized,
            'first' => $bounds['first'],
            'last' => $bounds['last'],
            'addresses' => $bounds['addresses'],
            'host_bits' => $bounds['host_bits'],
        ];
    }
}
