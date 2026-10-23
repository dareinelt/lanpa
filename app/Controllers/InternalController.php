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
use App\Services\PhonebookService;
use App\Support\ClientAddress;

/**
 * Interne Schnittstelle fuer die auth-Container: Beim Start ruft jede
 * auth-Instanz ihre Domaenen-Konfiguration (inkl. entschluesseltem Konto fuer
 * den Domaenenbeitritt) ab, damit keine Zugangsdaten in der .env stehen.
 * Ebenso meldet der auth-Container seine Kennzahlen (CPU, Arbeitsspeicher,
 * Verbindungen) und der Sammel-Container die Kennzahlen der uebrigen Container
 * (CPU und Arbeitsspeicher, siehe docker/monitor/metrics.py).
 *
 * Schutz:
 * - Gemeinsames Token aus dem Volume "sso_token" (nur app, auth-* und
 *   monitor), Header X-Intranet-Sso-Token.
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
     * Kennzahlen des auth-Containers (CPU-Auslastung im Container sowie die im
     * Messfenster aufgebauten Client-Verbindungen und die Anfragen je
     * Quellnetz). Der Container misst selbst (docker/auth/metrics.py) und
     * meldet jede Probe; die Anwendung prueft und speichert sie fuer die Karte
     * auf dem Admin-Dashboard.
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
                'ram_percent' => $request->input('ram_percent'),
                'ram_used' => $request->input('ram_used'),
                'ram_total' => $request->input('ram_total'),
                'connections' => $request->input('connections'),
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
     * Kennzahlen der uebrigen Container (CPU- und Arbeitsspeicher-Auslastung
     * je Container). Die Container app, db, mail-proxy, clamav, nextcloud und
     * eurooffice messen nicht selbst; der Sammel-Container
     * (docker/monitor/metrics.py) liest die Werte ueber den Docker-Socket aus
     * der Docker-Engine und meldet je Container eine Probe. Die Anwendung
     * prueft und speichert sie fuer die Kacheln auf dem Admin-Dashboard.
     */
    public function containerMetrics(Request $request): Response
    {
        if (!$this->authorized($request, '', (string) Env::get('MONITOR_METRICS_SERVICE', 'monitor'))) {
            app_logger()->warning('Meldung der Kennzahlen der uebrigen Container abgelehnt.', [
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        try {
            Container::containerMetrics()->record([
                'service' => $request->input('service'),
                'cpu_percent' => $request->input('cpu_percent'),
                'cpu_limit' => $request->input('cpu_limit'),
                'cpu_limited' => $request->input('cpu_limited'),
                'ram_percent' => $request->input('ram_percent'),
                'ram_used' => $request->input('ram_used'),
                'ram_total' => $request->input('ram_total'),
            ]);
        } catch (ValidationException $exception) {
            app_logger()->warning('Ungueltige Kennzahlen eines Containers abgelehnt.', [
                'errors' => $exception->errors(),
            ]);

            return $this->deny(422);
        }

        return Response::noContent();
    }

    /**
     * Melderdaten fuer die KLINQ-Meldeseite.
     *
     * Die Anwendung uebergibt beim Einstieg in KLINQ bereits die Kontaktdaten
     * (siehe KlinqController). Liegt dort nichts mehr vor, fragt KLINQ die
     * Angaben hier erneut ab. Der Rechnername wird aus der uebergebenen
     * Client-Adresse ermittelt, weil die Anfrage selbst vom KLINQ-Container
     * kommt.
     */
    public function klinqReporter(Request $request): Response
    {
        if (!$this->authorized($request, '', (string) Env::get('KLINQ_SERVICE', 'klinq-app'))) {
            app_logger()->warning('Abruf der KLINQ-Melderdaten abgelehnt.', [
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        $username = SsoAuth::normalizeUsername($request->input('username'));
        if ($username === null || $username === '') {
            return $this->deny(422);
        }

        $contact = Container::klinqIdentity()->contact(['username' => $username]);

        $ip = (string) ($request->input('ip') ?? '');
        $contact['ip'] = filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
        $contact['host'] = $contact['ip'] !== '' ? ClientAddress::hostname($contact['ip']) : '';

        return Response::json($contact)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * KI-Endpunkt fuer KLINQ.
     *
     * Der Endpunkt wird ausschliesslich im Intranet gepflegt (Adminbereich
     * "KLINQ – KI-Endpunkt"). KLINQ holt den Stand hier ab und verwendet ihn
     * fuer die KI-Unterstuetzung im Help Desk; der API-Schluessel bleibt damit
     * zentral verwaltet. Abgerufen wird nur bei Bedarf (kurzer Zwischenspeicher
     * im KLINQ-Container).
     */
    public function klinqAi(Request $request): Response
    {
        if (!$this->authorized($request, '', (string) Env::get('KLINQ_SERVICE', 'klinq-app'))) {
            app_logger()->warning('Abruf der KLINQ-KI-Konfiguration abgelehnt.', [
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        return Response::json(Container::klinqAi()->payload())->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Telefonbuch fuer die Personenauswahl in KLINQ (Melder/Betroffener im
     * Help-Desk-Ticket).
     *
     * Ohne Parameter liefert der Endpunkt die Treffer zu ?q= (Name,
     * Benutzername, Personalnummer, Telefonnummer) aus allen
     * Identitaetsquellen – auch Personen ohne E-Mail-Adresse. Mit ?id= liefert
     * er genau einen Eintrag; KLINQ nutzt das, um eine ausgewaehlte Person als
     * lokalen Mitarbeiter zu uebernehmen, ohne den Datensatz vom Client zu
     * uebernehmen.
     */
    public function klinqPhonebook(Request $request): Response
    {
        if (!$this->authorized($request, '', (string) Env::get('KLINQ_SERVICE', 'klinq-app'))) {
            app_logger()->warning('Abruf der KLINQ-Telefonbuchsuche abgelehnt.', [
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        $service = Container::phonebook();
        $id = $request->queryInt('id', 0);
        if ($id > 0) {
            $entry = $service->directoryEntry($id);

            return $entry === null
                ? $this->deny(404)
                : Response::json($entry)->withHeader('Cache-Control', 'no-store');
        }

        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return Response::json(['items' => []])->withHeader('Cache-Control', 'no-store');
        }

        $items = $service->directory($term, $request->queryInt('limit', PhonebookService::DEFAULT_LIMIT));

        return Response::json(['items' => $items])->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Signatur des angemeldeten Agenten fuer E-Mails aus einem KLINQ-Ticket.
     *
     * Die Signatur wird wie in Orvanta aus der Vorlage der AD-Gruppen und den
     * Personendaten der Telefonliste erzeugt. KLINQ zeigt sie im Editor
     * unveraenderlich an und haengt sie beim Senden erneut serverseitig an;
     * der Client kann sie damit weder aendern noch weglassen.
     */
    public function klinqSignature(Request $request): Response
    {
        if (!$this->authorized($request, '', (string) Env::get('KLINQ_SERVICE', 'klinq-app'))) {
            app_logger()->warning('Abruf der KLINQ-Agentensignatur abgelehnt.', [
                'remote' => (string) ($request->server['REMOTE_ADDR'] ?? ''),
            ]);

            return $this->deny(403);
        }

        $username = SsoAuth::normalizeUsername($request->query('username'));
        if ($username === null || $username === '') {
            return $this->deny(422);
        }

        $entry = Container::phonebookRepository()->findActiveBySamAccountNameAnySource($username);
        $ssoUser = [
            'id' => (int) ($entry['id'] ?? 0),
            'username' => $username,
            'display_name' => (string) ($entry['display_name'] ?? $username),
            'groups' => $entry === null ? [] : Container::adGroupRepository()->namesForUser((int) $entry['id']),
        ];
        $signature = Container::orvantaSignatures()->forUser($ssoUser);

        return Response::json([
            'available' => $signature !== null,
            'name' => $signature['name'] ?? '',
            'html' => $signature['html'] ?? '',
        ])->withHeader('Cache-Control', 'no-store');
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

    private function authorized(Request $request, string $key, ?string $service = null): bool
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
        $service ??= $key === '' ? (string) Env::get('SSO_PRIMARY_SERVICE', 'auth') : IdentitySourceService::serviceName($key);

        return $remote !== '' && in_array($remote, SsoAuth::resolveHost($service), true);
    }

    private function deny(int $status): Response
    {
        return Response::text('', $status)->withHeader('Cache-Control', 'no-store');
    }
}
