<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\SsoAuth;
use App\Services\IdentitySourceService;

/**
 * Interne Schnittstelle fuer die auth-Container: Beim Start ruft jede
 * auth-Instanz ihre Domaenen-Konfiguration (inkl. entschluesseltem Konto fuer
 * den Domaenenbeitritt) ab, damit keine Zugangsdaten in der .env stehen.
 *
 * Schutz:
 * - Gemeinsames Token aus dem Volume "sso_token" (nur app und auth-*),
 *   Header X-Intranet-Sso-Token.
 * - Die Anfrage muss direkt vom Container der angefragten Instanz stammen
 *   ("auth" bzw. "auth-<kennung>"); eine Zweigstellen-Instanz erhaelt so nie
 *   die Zugangsdaten einer anderen Domaene.
 * - Ueber den oeffentlichen Proxy weitergereichte Anfragen (X-Forwarded-For)
 *   werden abgelehnt; der auth-Container sperrt /internal/ zusaetzlich.
 */
final class InternalController
{
    public function ssoConfig(Request $request): Response
    {
        $key = IdentitySourceService::normalizeKey((string) $request->query('source', ''));
        if ($key !== '' && !IdentitySourceService::isValidKey($key)) {
            return $this->deny(400);
        }

        if (!$this->authorized($request, $key)) {
            app_logger()->warning('Abruf der SSO-Konfiguration abgelehnt.', [
                'source' => $key,
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        $environment = Container::identitySources()->authEnvironment($key);
        if ($environment === null) {
            return $this->deny(404);
        }

        return $this->lines($environment);
    }

    /**
     * HTTPS-Konfiguration fuer die Hauptinstanz des auth-Containers
     * (aktives Zertifikat bzw. Notfall-Zertifikat, Schluessel, Quellnetze fuer
     * reines HTTP). Wird regelmaessig abgerufen (docker/auth/tls-sync.sh);
     * dabei wird vermerkt, welches Zertifikat im Einsatz ist.
     */
    public function tlsConfig(Request $request): Response
    {
        if (!$this->authorized($request, '')) {
            app_logger()->warning('Abruf der TLS-Konfiguration abgelehnt.', [
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        return $this->lines(Container::tlsCertificates()->authConfig());
    }

    /**
     * Weiterleitungsziele fuer den auth-Container: aktive externe
     * Navigationskacheln mit aktivierter Weiterleitung ueber den
     * Reverse-Proxy. Der Container erzeugt daraus seine Proxy-Konfiguration
     * (docker/auth/weiterleitung-sync.sh); die Quellnetz-Ausnahmen wertet die
     * Anwendung beim Ausliefern der Kacheln aus.
     *
     * Jede Instanz des auth-Containers ruft die Ziele mit ihrer eigenen Kennung
     * ab (?source=<KENNUNG>), damit auch Zweigstellen-Instanzen den Proxy
     * erhalten.
     */
    public function navProxyConfig(Request $request): Response
    {
        // Instanzen weiterer Identitaetsquellen (Zweigstellen) brauchen dieselbe
        // Weiterleitungskonfiguration und weisen sich mit ihrer Kennung aus.
        $key = IdentitySourceService::normalizeKey((string) $request->query('source', ''));
        if ($key !== '' && !IdentitySourceService::isValidKey($key)) {
            return $this->deny(400);
        }

        if (!$this->authorized($request, $key)) {
            app_logger()->warning('Abruf der Weiterleitungskonfiguration abgelehnt.', [
                'source' => $key,
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        return $this->lines(Container::navigation()->proxyConfig());
    }

    /**
     * Kennzahlen des auth-Containers (CPU-Auslastung im Container, offene
     * TCP-Verbindungen und deren Quellnetze). Der Container misst selbst
     * (docker/auth/metrics.py) und meldet jede Probe; die Anwendung prueft und
     * speichert sie fuer die Karte auf dem Admin-Dashboard.
     */
    public function authMetrics(Request $request): Response
    {
        if (!$this->authorized($request, '')) {
            app_logger()->warning('Meldung der Kennzahlen des auth-Containers abgelehnt.', [
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        try {
            Container::authMetrics()->record([
                'cpu_percent' => $request->input('cpu_percent'),
                'cpu_limit' => $request->input('cpu_limit'),
                'tcp_open' => $request->input('tcp_open'),
                'sources' => $request->input('sources'),
            ]);
        } catch (ValidationException $exception) {
            app_logger()->warning('Ungueltige Kennzahlen des auth-Containers abgelehnt.', [
                'errors' => $exception->errors(),
            ]);

            return $this->deny(422);
        }

        return Response::noContent();
    }

    /**
     * Je Zeile NAME=base64(wert): keine Shell-Interpretation beim Einlesen.
     *
     * @param array<string,string> $values
     */
    private function lines(array $values): Response
    {
        $lines = [];
        foreach ($values as $name => $value) {
            $lines[] = $name . '=' . base64_encode($value);
        }

        return Response::text(implode("\n", $lines) . "\n")
            ->withHeader('Cache-Control', 'no-store');
    }

    private function authorized(Request $request, string $key): bool
    {
        $file = (string) Env::get('SSO_CONFIG_TOKEN_FILE', '/run/intranet-sso/token');
        $expected = is_readable($file) ? trim((string) @file_get_contents($file)) : '';
        $given = (string) ($request->server['HTTP_X_INTRANET_SSO_TOKEN'] ?? '');
        if (strlen($expected) < 32 || !hash_equals($expected, $given)) {
            return false;
        }

        if (($request->server['HTTP_X_FORWARDED_FOR'] ?? '') !== '' || ($request->server['HTTP_X_FORWARDED_HOST'] ?? '') !== '') {
            return false;
        }

        $remote = (string) ($request->server['REMOTE_ADDR'] ?? '');
        $service = $key === '' ? (string) Env::get('SSO_PRIMARY_SERVICE', 'auth') : IdentitySourceService::serviceName($key);

        return $remote !== '' && in_array($remote, SsoAuth::resolveHost($service), true);
    }

    private function deny(int $status): Response
    {
        return Response::text('', $status)->withHeader('Cache-Control', 'no-store');
    }
}
