<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Weiterleitung externer Navigationskacheln ueber den Reverse-Proxy des
 * auth-Containers (Adressraum /weiterleitung/<id>/).
 *
 * Zweck: Zweigstellen und Aussenstellen ohne eigene Namensaufloesung bzw.
 * Zertifikatskette erreichen das Ziel ueber den bereits vertrauenswuerdig
 * erreichbaren Intranet-Host; DNS- und Zertifikatspruefung findet dann nur
 * zwischen Reverse-Proxy und Ziel statt.
 *
 * Clients aus den je Kachel angegebenen Quellnetzen rufen das Ziel weiterhin
 * direkt auf (klassische URL). Die Entscheidung faellt serverseitig anhand der
 * Adresse des aufrufenden Clients, damit im Browser keine Sonderlogik noetig
 * ist.
 */
final class TileProxy
{
    public const PATH_PREFIX = '/weiterleitung/';

    /**
     * Hinweisseite, wenn der Proxy ein Ziel nicht erreicht (Fehlerseite des
     * auth-Containers).
     */
    public const UNAVAILABLE_PATH = '/weiterleitung-nicht-verfuegbar';

    /**
     * Ziele, die der Proxy spiegeln kann: http(s), Host (optional mit Port),
     * Pfad und optional Abfrage/Fragment. Nur Zeichen, die im Adressraum des
     * Proxys und in der erzeugten Apache-Konfiguration unkritisch sind.
     * Dieselbe Einschraenkung prueft docker/auth/weiterleitung-sync.sh.
     */
    private const PROXYABLE_URL = '#^https?://[A-Za-z0-9]([A-Za-z0-9._-]*[A-Za-z0-9])?(:[0-9]{1,5})?(/[A-Za-z0-9._~%+@:=-]*)*(\?[^\x23\s]*)?(\x23[^\s]*)?$#';

    /**
     * Adresse der Kachel, die der Proxy uebernehmen kann.
     */
    public static function isProxyableUrl(string $url): bool
    {
        return preg_match(self::PROXYABLE_URL, trim($url)) === 1;
    }

    /**
     * Oeffentlicher Praefix der Kachel. Die Kennung bleibt ueber Umbenennungen
     * hinweg stabil, deshalb der Primaerschluessel.
     */
    public static function path(int $id): string
    {
        return self::PATH_PREFIX . $id . '/';
    }

    /**
     * Adresse der Kachel im Adressraum des Proxys: Praefix plus Zielpfad der
     * Anwendung. Der Zielpfad bleibt unveraendert, damit auch absolute Verweise
     * der Zielanwendung ueber den Proxy laufen. Zeigt der Zielpfad auf ein
     * Verzeichnis, ergaenzt die Adresse einen Schraegstrich (relative Verweise
     * der Zielanwendung bleiben so gueltig).
     */
    public static function url(int $id, string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return self::path($id);
        }

        $address = self::path($id) . ltrim((string) ($parts['path'] ?? ''), '/');
        $last = substr($address, (int) strrpos($address, '/') + 1);
        if (!str_ends_with($address, '/') && !str_contains($last, '.')) {
            $address .= '/';
        }

        if (isset($parts['query']) && $parts['query'] !== '') {
            $address .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $address .= '#' . $parts['fragment'];
        }

        return $address;
    }

    /**
     * Nur externe Kacheln (neuer Tab) mit gesetztem Haken werden weitergeleitet.
     *
     * @param array<string,mixed> $item
     */
    public static function applies(array $item): bool
    {
        return (string) ($item['type'] ?? '') === 'external' && !empty($item['proxy_enabled']);
    }

    /**
     * Quellnetze, die das Ziel direkt aufrufen.
     *
     * @param array<string,mixed> $item
     *
     * @return list<string>
     */
    public static function bypassNetworks(array $item): array
    {
        return IpNetwork::parseList((string) ($item['proxy_bypass_networks'] ?? ''));
    }

    /**
     * Ziel der Kachel: Weiterleitungspfad oder null fuer den Direktaufruf.
     *
     * Die Adresse enthaelt den Zielpfad der Anwendung, damit die Zielanwendung
     * unter demselben Pfad erreicht wird wie bei einem Direktaufruf.
     *
     * @param array<string,mixed> $item
     */
    public static function target(array $item, ?string $clientIp): ?string
    {
        if (!self::applies($item)) {
            return null;
        }

        $id = (int) ($item['id'] ?? 0);
        if ($id < 1 || !self::isProxyableUrl((string) ($item['url'] ?? ''))) {
            return null;
        }

        // Unbekannte Client-Adresse: weiterleiten (Ziel ist sonst ggf. nicht
        // erreichbar), nur bekannte Ausnahmenetze werden direkt bedient.
        if ($clientIp !== null && $clientIp !== '' && IpNetwork::matchesAny(self::bypassNetworks($item), $clientIp)) {
            return null;
        }

        return self::url($id, (string) ($item['url'] ?? ''));
    }

    /**
     * Ergaenzt Kacheln um den Schluessel 'proxy_url' (null = Direktaufruf).
     *
     * @param list<array<string,mixed>> $items
     *
     * @return list<array<string,mixed>>
     */
    public static function decorate(array $items, ?string $clientIp): array
    {
        foreach ($items as $index => $item) {
            $items[$index]['proxy_url'] = self::target($item, $clientIp);
        }

        return $items;
    }
}
