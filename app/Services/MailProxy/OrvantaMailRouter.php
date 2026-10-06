<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

use App\Contracts\MailProxyTransportInterface;
use App\Contracts\OrvantaMailBackendInterface;
use App\Core\Logger;
use App\Repositories\MailProxyRepository;
use App\Services\Orvanta\OrvantaException;

/**
 * Waehlt das Mail-Backend fuer den angemeldeten Benutzer:
 *
 *   Zuordnung zu einem aktiven Proxy-Postfach -> ProxyMailBackend
 *   sonst                                       -> Exchange (Standard)
 *   Postfach deaktiviert                        -> Fehler (kein Rueckfall)
 *
 * Controller fragen nur den Router; Protokolldetails bleiben in den Backends.
 */
final class OrvantaMailRouter
{
    /** @var array<string,MailProxyRoute> */
    private array $routes = [];

    /**
     * @param \Closure(): OrvantaMailBackendInterface $exchange liefert das Exchange-Backend (lazy)
     */
    public function __construct(
        private readonly MailProxyResolver $resolver,
        private readonly MailProxyTransportInterface $transport,
        private readonly \Closure $exchange,
        private readonly ?MailProxyRepository $repository = null,
        private readonly ?Logger $logger = null
    ) {
    }

    /**
     * @param array<string,mixed> $ssoUser
     */
    public function route(array $ssoUser): MailProxyRoute
    {
        $key = (int) ($ssoUser['source_id'] ?? -1) . ':' . (int) ($ssoUser['id'] ?? 0);
        if (!isset($this->routes[$key])) {
            try {
                $this->routes[$key] = $this->resolver->resolve($ssoUser);
            } catch (\PDOException $exception) {
                // Datenbankfehler: kein Proxy, bestehendes Verhalten.
                $this->logger?->warning('mail-proxy resolution failed', ['error' => $exception->getMessage()]);
                $this->routes[$key] = MailProxyRoute::exchange('Zuordnung nicht ermittelbar.');
            }
        }

        return $this->routes[$key];
    }

    /**
     * @param array<string,mixed> $ssoUser
     */
    public function backendFor(array $ssoUser): OrvantaMailBackendInterface
    {
        return $this->backendForRoute($this->route($ssoUser));
    }

    public function backendForRoute(MailProxyRoute $route): OrvantaMailBackendInterface
    {
        if ($route->isBlocked()) {
            throw new OrvantaException($route->reason !== '' ? $route->reason : 'Das zugeordnete Postfach ist deaktiviert.', 403);
        }
        if (!$route->isProxy()) {
            return ($this->exchange)();
        }

        return new ProxyMailBackend(
            $route,
            fn (): MailProxyAccount => $this->resolver->account($route),
            $this->transport,
            $this->repository,
            $this->logger
        );
    }

    /**
     * Backend fuer tokenbasierte Zugriffe ohne Sitzungsbenutzer (z. B.
     * Anhang-Links fuer den Office-Viewer). $uid ist die Office-Kennung
     * (SamAccountName bzw. SamAccountName@quelle), $impersonate die beim
     * Ausstellen gebundene Postfachadresse; weicht die aktuelle Zuordnung
     * davon ab, wird der Zugriff verweigert.
     */
    public function backendForUid(string $uid, string $impersonate): OrvantaMailBackendInterface
    {
        $route = $this->routeForUid($uid);
        if ($route->isProxy() && strcasecmp($route->email, $impersonate) !== 0) {
            throw new OrvantaException('Die Postfach-Zuordnung wurde geändert.', 403);
        }

        return $this->backendForRoute($route);
    }

    private function routeForUid(string $uid): MailProxyRoute
    {
        if ($this->repository === null) {
            return MailProxyRoute::exchange();
        }
        $username = $uid;
        $sourceKey = '';
        $at = strrpos($uid, '@');
        if ($at !== false) {
            $username = substr($uid, 0, $at);
            $sourceKey = substr($uid, $at + 1);
        }
        try {
            $sourceId = $sourceKey === '' ? 0 : $this->repository->activeSourceIdByKey($sourceKey);
            if ($sourceId === null || $username === '') {
                return MailProxyRoute::exchange('Identitätsquelle nicht vorhanden.');
            }
            $userId = $this->repository->findActiveUserId($username, $sourceId);
        } catch (\PDOException) {
            return MailProxyRoute::exchange('Zuordnung nicht ermittelbar.');
        }
        if ($userId === null) {
            return MailProxyRoute::exchange('Benutzer nicht aktiv.');
        }

        return $this->route(['id' => $userId, 'source_id' => $sourceId, 'source_key' => $sourceKey]);
    }
}
