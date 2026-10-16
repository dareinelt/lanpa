<?php

declare(strict_types=1);

use App\Services\Sso\SsoAgentService;
use App\Core\View;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Windows-Anmeldung aus dem Adminbereich anwenden: die Anwendung hat keinen
 * Docker-Socket und kein Projektverzeichnis, deshalb laeuft der Auftrag ueber
 * das Austauschverzeichnis (request.json/status.json) des Dienstes sso-agent.
 * Geprueft wird die PHP-Seite (Auftrag annehmen, Zustand lesen) und die
 * Verdrahtung von Dienst, Route und Adminbereich.
 */
function ssoAgentDir(): string
{
    $dir = sys_get_temp_dir() . '/lanpa-sso-agent-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

function ssoAgentFile(string $relative): string
{
    $content = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    Assert::true(is_string($content), $relative . ' fehlt.');

    return str_replace("\r\n", "\n", (string) $content);
}

function ssoAgentWriteStatus(string $dir, array $status): void
{
    file_put_contents($dir . '/status.json', json_encode($status, JSON_THROW_ON_ERROR));
}

Runner::test('SSO-Agent: ohne Agent wird kein Auftrag angenommen', function (): void {
    $service = new SsoAgentService(ssoAgentDir() . '/fehlt');

    Assert::false($service->isAgentAvailable());
    $status = $service->status();
    Assert::false($status['available']);
    Assert::same('unknown', $status['state']);
    Assert::same([], $status['instances']);
    Assert::false($status['running']);
    Assert::false($status['pending']);
    Assert::null($service->instanceStatus('auth'));

    $message = '';
    try {
        $service->request('apply');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }
    Assert::contains('sso-agent', $message, 'Ohne Agent muss der Auftrag mit Hinweis abgelehnt werden.');
});

Runner::test('SSO-Agent: Zustand und Instanzen werden gelesen', function (): void {
    $dir = ssoAgentDir();
    ssoAgentWriteStatus($dir, [
        'state' => 'done',
        'action' => 'apply',
        'message' => 'Windows-Anmeldung angewendet (2 Instanz(en)).',
        'request_id' => 'abc',
        'updated_at' => '2026-01-01T00:00:00Z',
        'project' => 'lanpa',
        'compose_file' => 'docker-compose.yml:docker-compose.sso.yml',
        'instances' => [
            ['service' => 'auth', 'state' => 'running', 'health' => 'healthy', 'domain' => 'KHWF',
                'active' => true, 'enabled' => true, 'kerberos' => true, 'join' => 'ok', 'ntp' => 'ok', 'notes' => []],
            ['service' => 'auth-mvzintwf', 'state' => 'running', 'health' => 'healthy', 'domain' => 'MVZINTSZ',
                'active' => true, 'enabled' => true, 'kerberos' => false, 'join' => 'ok', 'ntp' => 'failed',
                'notes' => ['WARNUNG: Kerberos nicht verfuegbar - nur NTLM.', ['verschachtelt']]],
            ['service' => 42, 'state' => 'running'],
            ['service' => 'auth-x', 'state' => 'exited'],
        ],
    ]);

    $service = new SsoAgentService($dir);
    Assert::true($service->isAgentAvailable());
    $status = $service->status();
    Assert::true($status['available']);
    Assert::same('done', $status['state']);
    Assert::false($status['running']);
    Assert::same('apply', $status['action']);
    Assert::same('docker-compose.yml:docker-compose.sso.yml', $status['compose_file']);
    Assert::same(3, count($status['instances']), 'Eintraege ohne Dienstnamen werden verworfen.');

    $secondary = $service->instanceStatus('auth-mvzintwf');
    Assert::same('MVZINTSZ', $secondary['domain']);
    Assert::false($secondary['kerberos']);
    Assert::same('failed', $secondary['ntp']);
    Assert::same(['WARNUNG: Kerberos nicht verfuegbar - nur NTLM.'], $secondary['notes'], 'Nur Textzeilen als Hinweise.');

    $unknown = $service->instanceStatus('auth-x');
    Assert::same('exited', $unknown['state']);
    Assert::null($unknown['enabled'], 'Ohne Angabe bleibt die Aktivierung unbekannt.');
    Assert::same('unknown', $unknown['join']);
    Assert::null($service->instanceStatus('auth-gibtsnicht'));
});

Runner::test('SSO-Agent: Auftrag wird abgelegt und ein zweiter abgelehnt', function (): void {
    $dir = ssoAgentDir();
    ssoAgentWriteStatus($dir, ['state' => 'done']);
    $service = new SsoAgentService($dir);

    $id = $service->request('apply');
    Assert::same(16, strlen($id));
    $request = json_decode((string) file_get_contents($dir . '/request.json'), true);
    Assert::same('apply', $request['action']);
    Assert::same($id, $request['id']);
    Assert::same([], glob($dir . '/.request.*.tmp') ?: [], 'Die Auftragsdatei wird atomar abgelegt.');

    $status = $service->status();
    Assert::true($status['pending']);
    Assert::false($status['pending_stale']);
    Assert::false($status['running']);

    $message = '';
    try {
        $service->request('apply');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }
    Assert::contains('bereits ein Auftrag', $message);
});

Runner::test('SSO-Agent: laufender Auftrag blockiert, veralteter wird ersetzt', function (): void {
    $dir = ssoAgentDir();
    ssoAgentWriteStatus($dir, ['state' => 'running', 'message' => 'Windows-Anmeldung wird angewendet ...']);
    $service = new SsoAgentService($dir);

    Assert::true($service->status()['running']);
    $message = '';
    try {
        $service->request('apply');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }
    Assert::contains('bereits angewendet', $message);
    Assert::false(is_file($dir . '/request.json'), 'Bei laufendem Auftrag darf nichts abgelegt werden.');

    // Ein Auftrag, der nicht mehr abgeholt wurde (z. B. Agent neu gestartet),
    // darf einen neuen Auftrag nicht dauerhaft blockieren.
    ssoAgentWriteStatus($dir, ['state' => 'failed', 'message' => 'unterbrochen']);
    file_put_contents($dir . '/request.json', '{}');
    touch($dir . '/request.json', time() - 600);

    $status = $service->status();
    Assert::true($status['pending']);
    Assert::true($status['pending_stale']);
    Assert::same(16, strlen($service->request('refresh')));
});

Runner::test('SSO-Agent: unbekannter Auftrag wird abgelehnt', function (): void {
    $dir = ssoAgentDir();
    ssoAgentWriteStatus($dir, ['state' => 'done']);
    $service = new SsoAgentService($dir);

    $message = '';
    try {
        $service->request('loeschen');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }
    Assert::same('Unbekannter Auftrag.', $message);
    Assert::false(is_file($dir . '/request.json'));
});

Runner::test('SSO-Agent: Dienst, Route und Adminbereich sind verdrahtet', function (): void {
    $compose = ssoAgentFile('docker-compose.yml');
    Assert::contains("\n  sso-agent:\n", $compose);
    Assert::contains('docker/sso-agent/Dockerfile', $compose);
    Assert::contains('- ./:/project', $compose, 'Der Agent braucht das Projektverzeichnis fuer .env und die Compose-Datei.');
    Assert::contains('- /var/run/docker.sock:/var/run/docker.sock', $compose);
    Assert::contains('SSO_AGENT_CONTROL_DIR: /storage/sso-agent', $compose);

    $index = ssoAgentFile('public/index.php');
    Assert::contains("'/admin/ad/anmeldeinstanzen/anwenden', [LdapController::class, 'applySso']", $index);

    $view = ssoAgentFile('views/admin/ldap.php');
    Assert::contains('action="/admin/ad/anmeldeinstanzen/anwenden"', $view);
    Assert::contains('Windows-Anmeldung anwenden', $view);
    Assert::contains('ssoAgent', $view);
});

function ssoAgentViewSources(): array
{
    return [
        ['id' => 0, 'key' => '', 'label' => 'Zentrale', 'hosts' => ['dc.khwf.de'], 'base_dn' => 'DC=khwf,DC=de',
            'active' => true, 'primary' => true, 'configured' => true, 'password_state' => 'set', 'sso' => 'KHWF',
            'users' => 12, 'service' => 'auth', 'instance' => ['state' => 'running', 'health' => 'healthy',
                'domain' => 'KHWF', 'active' => true, 'enabled' => true, 'kerberos' => true, 'join' => 'ok', 'ntp' => 'ok', 'notes' => []]],
        ['id' => 3, 'key' => 'MVZINTWF', 'label' => 'MVZ <script>', 'hosts' => ['192.168.233.200'], 'base_dn' => 'DC=mvzintsz,DC=local',
            'active' => true, 'primary' => false, 'configured' => true, 'password_state' => 'set', 'sso' => 'MVZINTSZ',
            'users' => 4, 'service' => 'auth-mvzintwf', 'instance' => ['state' => 'running', 'health' => 'unhealthy',
                'domain' => 'MVZINTSZ', 'active' => true, 'enabled' => true, 'kerberos' => false, 'join' => 'failed',
                'ntp' => 'failed', 'notes' => ['WARNUNG: Kerberos nicht verfuegbar - nur NTLM.']]],
        ['id' => 4, 'key' => 'OHNE_SSO', 'label' => 'Tochter ohne Windows-Anmeldung', 'hosts' => [], 'base_dn' => '',
            'active' => false, 'primary' => false, 'configured' => false, 'password_state' => 'missing', 'sso' => null,
            'users' => 0, 'service' => 'auth-ohne-sso', 'instance' => null],
    ];
}

Runner::test('SSO-Agent: Adminseite zeigt Zustand und Schaltfläche', function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $ssoAgent = [
        'available' => true, 'state' => 'done', 'action' => 'apply',
        'message' => 'Windows-Anmeldung angewendet (2 Instanz(en)).', 'request_id' => 'abc',
        'updated_at' => '2026-01-01T00:00:00Z', 'project' => 'lanpa',
        'compose_file' => 'docker-compose.yml:docker-compose.sso.yml',
        'running' => false, 'pending' => false, 'pending_stale' => false,
        'instances' => [
            'auth' => ['service' => 'auth', 'state' => 'running', 'health' => 'healthy', 'domain' => 'KHWF',
                'active' => true, 'enabled' => true, 'kerberos' => true, 'join' => 'ok', 'ntp' => 'ok', 'notes' => []],
            'auth-mvzintwf' => ['service' => 'auth-mvzintwf', 'state' => 'running', 'health' => 'unhealthy',
                'domain' => 'MVZINTSZ', 'active' => true, 'enabled' => true, 'kerberos' => false, 'join' => 'failed',
                'ntp' => 'failed', 'notes' => ['WARNUNG: Kerberos nicht verfuegbar - nur NTLM.']],
        ],
    ];
    $data = [
        'primarySecretStates' => ['ldap_bind_password' => 'set', 'sso_join_password' => 'set'],
        'ldapExtensionAvailable' => true,
        'sources' => ssoAgentViewSources(),
        'dialog' => null,
        'ssoAgent' => $ssoAgent,
    ];

    $html = View::render('admin.ldap', $data);
    Assert::contains('Windows-Anmeldung angewendet (2 Instanz(en)).', $html);
    Assert::contains('action="/admin/ad/anmeldeinstanzen/anwenden"', $html, 'Die Schaltfläche sendet den Auftrag.');
    Assert::contains('name="_token"', $html, 'Der Auftrag ist CSRF-geschützt.');
    Assert::contains('docker-compose.yml:docker-compose.sso.yml', $html);
    Assert::contains('MVZINTSZ', $html, 'Die Domäne der zweiten Quelle steht in der Tabelle.');
    Assert::contains('Kerberos aktiv', $html);
    Assert::contains('nur NTLM – Kerberos nicht verfügbar', $html, 'Der fehlende Kerberos-Ticketpfad wird benannt.');
    Assert::contains('Domänenbeitritt fehlgeschlagen', $html);
    Assert::contains('Uhrzeit nicht geprüft oder weicht ab', $html, 'Der Zeitserver-Hinweis steht an der Instanz.');
    Assert::contains('MVZ &lt;script&gt;', $html, 'Beschriftungen werden escaped.');
    Assert::false(str_contains($html, '<script>'), 'Die Ausgabe ist escaped.');
    Assert::contains('<code>auth-mvzintwf</code>', $html, 'Die Instanz wird der Quelle zugeordnet.');
    Assert::false(str_contains($html, '<code>auth-ohne-sso</code>'), 'Quellen ohne Windows-Anmeldung stehen nicht in der Instanzliste.');

    // Laufender Auftrag: die Schaltfläche ist gesperrt, der Auftrag wird angekündigt.
    $busy = View::render('admin.ldap', ['ssoAgent' => array_replace($ssoAgent, ['running' => true])] + $data);
    Assert::contains('Windows-Anmeldung anwenden</button>', $busy);
    Assert::contains('disabled', $busy, 'Während eines Auftrags lässt sich nichts erneut anstoßen.');

    // Ohne erreichbaren Dienst bleibt nur der Hinweis auf den manuellen Weg.
    $offline = View::render('admin.ldap', ['ssoAgent' => array_replace($ssoAgent, ['available' => false])] + $data);
    Assert::contains('meldet sich nicht', $offline);
    Assert::contains('./scripts/sso-domains.sh', $offline);
    Assert::false(str_contains($offline, 'anmeldeinstanzen/anwenden'), 'Ohne Dienst gibt es keine Schaltfläche.');
});

Runner::test('SSO-Agent: COMPOSE_FILE steht vor dem Erzeugen der Instanzen', function (): void {
    $script = ssoAgentFile('docker/sso-agent/sso-agent.sh');

    // Ohne Eintrag in COMPOSE_FILE fehlt der Dienst im Compose-Projekt: der
    // Generator bricht dann ab, die Anmeldung landet auf der Hauptinstanz
    // einer anderen Domaene und der Browser fragt endlos nach Zugangsdaten.
    $composeFile = strpos($script, 'ensure_compose_file "$(compose_file_with add)"');
    $generator = strpos($script, './scripts/sso-domains.sh');
    Assert::true($composeFile !== false && $generator !== false, 'COMPOSE_FILE und Generator muessen im Skript stehen.');
    Assert::true($composeFile < $generator, 'COMPOSE_FILE muss vor dem Generator gepflegt werden.');
    Assert::contains('--no-deps', $script, 'Nur die auth-Instanzen anfassen.');
    Assert::contains('mapfile -t services < <(auth_services)', $script);
    Assert::contains('request.json', $script);
    Assert::contains('status.json', $script);

    $dockerfile = ssoAgentFile('docker/sso-agent/Dockerfile');
    Assert::contains('COPY docker/sso-agent/sso-agent.sh /usr/local/bin/sso-agent', $dockerfile);
    Assert::contains('docker-cli-compose', $dockerfile);
    Assert::contains('jq', $dockerfile);
});

Runner::test('SSO-Agent: Zeithinweis nennt die Folge fuer Kerberos', function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $ssoAgent = ['available' => false, 'state' => 'unknown', 'action' => '', 'message' => '', 'request_id' => '',
        'updated_at' => '', 'project' => '', 'compose_file' => '', 'running' => false, 'pending' => false,
        'pending_stale' => false, 'instances' => []];
    $sources = ssoAgentViewSources();
    $data = ['primarySecretStates' => [], 'ldapExtensionAvailable' => true, 'dialog' => null, 'ssoAgent' => $ssoAgent];

    // Uhr nicht abgeglichen, Kerberos laeuft (Abweichung nachgewiesen unter 5 Minuten).
    $sources[1]['instance']['ntp'] = 'warn';
    $html = View::render('admin.ldap', ['sources' => $sources] + $data);
    Assert::contains('Uhrzeit nicht abgeglichen, Kerberos läuft', $html,
        'Die Entwarnung steht an der Instanz.');
    Assert::false(str_contains($html, 'Kerberos braucht unter 5 Minuten'),
        'Bei laufendem Kerberos entfaellt die Warnung.');

    // Kein Zeitserver erreichbar bzw. Abweichung zu gross: die Folge wird benannt.
    $sources[1]['instance']['ntp'] = 'failed';
    $html = View::render('admin.ldap', ['sources' => $sources] + $data);
    Assert::contains('Uhrzeit nicht geprüft oder weicht ab (Kerberos braucht unter 5 Minuten)', $html,
        'Ohne Nachweis wird die Uhr als ungeprueft gemeldet.');
    Assert::false(str_contains($html, 'Kerberos läuft'), 'Der Fehlerfall wird nicht als Entwarnung gezeigt.');
});

Runner::test('auth-Container: unerreichbarer Zeitserver wird eingeordnet', function (): void {
    $entrypoint = ssoAgentFile('docker/auth/entrypoint.sh');

    // Die Domaenencontroller sind NTP-Server und dienen als Rueckfallebene:
    // ein falsch gesetzter SSO_NTP darf die Uhrpruefung nicht verhindern.
    $fallback = strpos($entrypoint, 'for s in ${DCS}; do');
    Assert::true($fallback !== false, 'Die DCs werden als Zeitserver ergaenzt.');
    Assert::true($fallback < strpos($entrypoint, 'NTP_SERVERS="${NTP_SERVERS# }"'),
        'Die Rueckfallebene steht vor der Ausgabe der Serverliste.');

    // Meldung nennt Ursache, Folge und Pruefung - vorher stand dort nur
    // "nicht erreichbar", ohne Hinweis ob die Anmeldung dadurch ausfaellt.
    Assert::contains('WARNUNG: Zeitserver ${NTP_SERVERS} nicht erreichbar - Uhrzeit nicht geprueft', $entrypoint);
    Assert::contains('Kerberos toleriert hoechstens 5 Minuten', $entrypoint,
        'Die Folge fuer Kerberos wird benannt.');
    Assert::contains('SSO_NTP bzw. Firewall (UDP 123) pruefen', $entrypoint,
        'Die Ursache laesst sich pruefen.');

    // Der Domaenenbeitritt laeuft ueber Kerberos und belegt damit die
    // Abweichung - danach ist die Warnung entschaerft.
    Assert::contains('Uhrzeit nicht abgeglichen, Kerberos gegen ${SSO_DOMAIN} funktioniert aber', $entrypoint);
    Assert::contains('WARNUNG: Uhrzeit nicht geprueft - eine Abweichung ueber 5 Minuten verhindert Kerberos', $entrypoint);
    Assert::true(strpos($entrypoint, 'chronyd -q -t 15') < strpos($entrypoint, 'Uhrzeit nicht abgeglichen, Kerberos gegen'),
        'Die Einordnung steht nach dem Zeitabgleich.');

    $agent = ssoAgentFile('docker/sso-agent/sso-agent.sh');
    Assert::contains('*"Uhrzeit nicht abgeglichen, Kerberos"*) ntp=warn ;;', $agent,
        'Der Dienst erkennt die Entwarnung.');
    Assert::contains('*"WARNUNG: Uhrzeit nicht geprueft"*) ntp=failed ;;', $agent);
    Assert::true(strpos($agent, 'ntp=warn') < strpos($agent, 'ntp=failed'),
        'Die Entwarnung muss vor den Fehlerfaellen stehen.');
});

/**
 * Protokolle der Anmelde-Instanzen. Die Anwendung hat keinen Docker-Socket und
 * kann selbst kein "docker logs" ausfuehren: sie fordert das Protokoll ueber
 * logs-request.json an und liest die Dateien, die der Dienst ablegt
 * (Overlay: letzte 100 Eintraege, Download: letzte 48 Stunden).
 */
function ssoAgentWriteLogs(string $dir, string $service, array $meta, ?string $tail = null, ?string $download = null): void
{
    $logs = $dir . '/logs';
    if (!is_dir($logs)) {
        mkdir($logs, 0700, true);
    }
    file_put_contents($logs . '/' . $service . '.json', json_encode($meta, JSON_THROW_ON_ERROR));
    if ($tail !== null) {
        file_put_contents($logs . '/' . $service . '.tail.log', $tail);
    }
    if ($download !== null) {
        file_put_contents($logs . '/' . $service . '.download.log', $download);
    }
}

function ssoAgentLogsMeta(string $service, string $requestId, string $generatedAt = '', string $error = ''): array
{
    return [
        'service' => $service,
        'request_id' => $requestId,
        'generated_at' => $generatedAt !== '' ? $generatedAt : gmdate('Y-m-d\TH:i:s\Z'),
        'lines' => 2,
        'download_bytes' => 6,
        'error' => $error,
    ];
}

Runner::test('SSO-Agent: Protokollauftrag wird abgelegt und Ergebnis gelesen', function (): void {
    $dir = ssoAgentDir();
    ssoAgentWriteStatus($dir, ['state' => 'done']);
    $service = new SsoAgentService($dir);

    // Nur Dienstnamen der Anmelde-Instanzen duerfen in Auftrag und Dateiname
    // eingehen (der Agent fragt damit Docker ab).
    Assert::true($service->isAuthService('auth'));
    Assert::true($service->isAuthService('auth-mvzintwf'));
    Assert::true($service->isAuthService('auth-mvz-int-wf'));
    Assert::false($service->isAuthService('web'));
    Assert::false($service->isAuthService('auth-'));
    Assert::false($service->isAuthService('auth-MVZ'));
    Assert::false($service->isAuthService('auth_mvz'));
    Assert::false($service->isAuthService('../auth'));

    $id = $service->requestLogs('auth');
    Assert::same(16, strlen($id));
    $request = json_decode((string) file_get_contents($dir . '/logs-request.json'), true);
    Assert::same('logs', $request['action']);
    Assert::same('auth', $request['service']);
    Assert::same($id, $request['id']);
    Assert::same([], glob($dir . '/.logs-request.*.tmp') ?: [], 'Der Auftrag wird atomar abgelegt.');
    Assert::false(is_file($dir . '/request.json'), 'Ein Protokollauftrag ersetzt kein "anwenden".');
    Assert::true($service->hasPendingLogsRequest());

    $pending = $service->logsResult('auth', $id);
    Assert::same('pending', $pending['state'], 'Ohne Ergebnis bleibt der Auftrag offen.');
    Assert::same('', $pending['tail']);
    Assert::false($pending['download_ready']);

    ssoAgentWriteLogs($dir, 'auth', ssoAgentLogsMeta('auth', $id), "Zeile 1\nZeile 2\n", "letzte 48 Stunden\n");
    $result = $service->logsResult('auth', $id);
    Assert::same('ready', $result['state']);
    Assert::same(2, $result['lines']);
    Assert::same("Zeile 1\nZeile 2\n", $result['tail'], 'Das Overlay zeigt die letzten Eintraege.');
    Assert::true($result['download_ready']);
    Assert::same('auth-protokoll-' . gmdate('Y-m-d') . '.log', $result['download_name']);
    Assert::same("letzte 48 Stunden\n", $service->logDownload('auth'));

    // Nur das Ergebnis des eigenen Auftrags zaehlt (sonst zeigt ein zweiter
    // Auftrag den Stand des vorherigen).
    Assert::same('pending', $service->logsResult('auth', 'ffffffffffffffff')['state']);
    Assert::same('ready', $service->logsResult('auth')['state'], 'Ein frisches Ergebnis steht auch ohne Auftragskennung bereit.');
});

Runner::test('SSO-Agent: Protokoll ohne Dienst, ohne Ergebnis und mit Fehler', function (): void {
    $dir = ssoAgentDir();
    $offline = new SsoAgentService($dir . '/fehlt');
    $message = '';
    try {
        $offline->requestLogs('auth');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }
    Assert::contains('sso-agent', $message, 'Ohne Dienst muss der Auftrag mit Hinweis abgelehnt werden.');
    Assert::false(is_file($dir . '/fehlt/logs-request.json'));

    $service = new SsoAgentService($dir);
    ssoAgentWriteStatus($dir, ['state' => 'done']);
    $message = '';
    try {
        $service->requestLogs('web');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    }
    Assert::same('Unbekannte Anmelde-Instanz.', $message);
    Assert::false(is_file($dir . '/logs-request.json'));

    Assert::same('error', $service->logsResult('web')['state']);
    Assert::null($service->logDownload('web'));

    // Der Dienst meldet sich seit Stunden nicht: das Overlay darf nicht ewig
    // "wird erstellt" anzeigen.
    $silent = ssoAgentDir();
    ssoAgentWriteStatus($silent, ['state' => 'done']);
    touch($silent . '/status.json', time() - 3600);
    $result = (new SsoAgentService($silent))->logsResult('auth');
    Assert::same('error', $result['state']);
    Assert::contains('sso-agent', $result['message']);

    // Ein liegen gebliebener Auftrag (Dienst neu gestartet) wird als Fehler
    // gemeldet statt als endlose Wartezeit.
    $stale = ssoAgentDir();
    ssoAgentWriteStatus($stale, ['state' => 'done']);
    $staleService = new SsoAgentService($stale);
    $staleService->requestLogs('auth');
    touch($stale . '/logs-request.json', time() - 300);
    Assert::false($staleService->hasPendingLogsRequest());
    Assert::same('error', $staleService->logsResult('auth')['state']);

    // Eine nicht laufende Instanz meldet der Dienst als Fehler (leere
    // Ausgabe waere hier irrefuehrend).
    $failed = ssoAgentDir();
    ssoAgentWriteStatus($failed, ['state' => 'failed', 'message' => 'Die Instanz laeuft nicht.']);
    ssoAgentWriteLogs($failed, 'auth-mvzintwf', ssoAgentLogsMeta('auth-mvzintwf', 'abc', '',
        'Die Instanz auth-mvzintwf laeuft nicht (Zustand: exited).'));
    $failedService = new SsoAgentService($failed);
    $error = $failedService->logsResult('auth-mvzintwf');
    Assert::same('error', $error['state']);
    Assert::contains('laeuft nicht', $error['message']);
    Assert::null($failedService->logDownload('auth-mvzintwf'), 'Ohne Datei gibt es keinen Download.');

    // Nach zehn Minuten gilt ein Ergebnis nicht mehr als frisch: die Uebersicht
    // fordert ein neues an, der letzte Download bleibt aber lesbar.
    $old = ssoAgentDir();
    ssoAgentWriteStatus($old, ['state' => 'done']);
    ssoAgentWriteLogs($old, 'auth', ssoAgentLogsMeta('auth', 'abc', gmdate('Y-m-d\TH:i:s\Z', time() - 3600)), 'alt', 'alt');
    $oldService = new SsoAgentService($old);
    Assert::same('pending', $oldService->logsResult('auth')['state']);
    Assert::same('alt', $oldService->logDownload('auth'));
});

Runner::test('SSO-Agent: Dienst liest die Protokolle der Anmelde-Instanzen', function (): void {
    $script = ssoAgentFile('docker/sso-agent/sso-agent.sh');

    Assert::contains('logs-request.json', $script);
    Assert::contains('do_logs()', $script);
    Assert::contains('valid_service()', $script);
    Assert::contains('docker logs --tail "$LOG_TAIL_LINES"', $script, 'Das Overlay bekommt die letzten Eintraege.');
    Assert::contains('docker logs --since "${LOG_DOWNLOAD_HOURS}h"', $script, 'Der Download umfasst die letzten Stunden.');
    Assert::contains('${dir}/${service}.tail.log', $script);
    Assert::contains('${dir}/${service}.download.log', $script);
    Assert::contains('SSO_AGENT_LOG_TIMEOUT:-30', $script);
    Assert::contains('SSO_AGENT_LOG_TAIL_LINES:-100', $script);
    Assert::contains('SSO_AGENT_LOG_DOWNLOAD_HOURS:-48', $script);
    Assert::contains('chown "${APP_UID}:${APP_UID}" "$dir"', $script,
        'Die Anwendung muss die abgelegten Dateien lesen koennen.');

    // Ein Protokollauftrag wird vor "anwenden" bearbeitet: ein langer Auftrag
    // darf das Overlay nicht blockieren, und waehrend eines Laufs ist das
    // Protokoll besonders wichtig.
    $logsBranch = strpos($script, 'if [ -f "$logs_request" ]');
    Assert::true($logsBranch !== false, 'Der Protokollauftrag wird bearbeitet.');
    Assert::true($logsBranch < strpos($script, '[ -f "$request" ] || return 0'),
        'Der Protokollauftrag steht vor dem Auftrag "anwenden".');

    $index = ssoAgentFile('public/index.php');
    Assert::contains("'/admin/ad/anmeldeinstanzen/protokoll', [LdapController::class, 'logRequest']", $index);
    Assert::contains("'/admin/ad/anmeldeinstanzen/protokoll/inhalt', [LdapController::class, 'logContent']", $index);
    Assert::contains("'/admin/ad/anmeldeinstanzen/protokoll/download', [LdapController::class, 'logDownload']", $index);
});

Runner::test('SSO-Agent: Adminseite zeigt Protokoll und Download je Instanz', function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $ssoAgent = [
        'available' => true, 'state' => 'done', 'action' => 'apply', 'message' => 'Windows-Anmeldung angewendet.',
        'request_id' => 'abc', 'updated_at' => '2026-01-01T00:00:00Z', 'project' => 'lanpa', 'compose_file' => '',
        'running' => false, 'pending' => false, 'pending_stale' => false,
        'instances' => [
            'auth' => ['service' => 'auth', 'state' => 'running', 'health' => 'healthy', 'domain' => 'KHWF',
                'active' => true, 'enabled' => true, 'kerberos' => true, 'join' => 'ok', 'ntp' => 'ok', 'notes' => []],
            'auth-mvzintwf' => ['service' => 'auth-mvzintwf', 'state' => 'running', 'health' => 'healthy',
                'domain' => 'MVZINTSZ', 'active' => true, 'enabled' => true, 'kerberos' => true, 'join' => 'ok',
                'ntp' => 'ok', 'notes' => []],
        ],
    ];
    $data = [
        'primarySecretStates' => [], 'ldapExtensionAvailable' => true, 'dialog' => null, 'ssoAgent' => $ssoAgent,
        'sources' => ssoAgentViewSources(),
    ];

    $html = View::render('admin.ldap', $data);
    Assert::contains('Protokoll anzeigen', $html, 'Jede Instanz bietet ihr Protokoll an.');
    Assert::contains('action="/admin/ad/anmeldeinstanzen/protokoll"', $html);
    Assert::contains('name="service" value="auth-mvzintwf"', $html);
    Assert::contains('name="_token"', $html, 'Der Protokollauftrag ist CSRF-geschützt.');
    Assert::contains('/admin/ad/anmeldeinstanzen/protokoll/download?service=auth-mvzintwf', $html,
        'Der Download der letzten 48 Stunden steht an der Instanz.');
    Assert::contains('Letzte 48 Stunden herunterladen', $html);
    Assert::contains('<dialog id="ad-logs-dialog"', $html);
    Assert::contains('ad-logs-output', $html);
    Assert::contains('100 Einträge', $html, 'Das Overlay benennt den Umfang.');

    // Ohne JavaScript liefert der Server das Overlay fertig aus.
    $ready = ['service' => 'auth-mvzintwf', 'label' => 'MVZ', 'domain' => 'MVZINTSZ', 'state' => 'ready',
        'message' => '', 'lines' => 2, 'generated_at' => '2026-01-01T00:00:00Z',
        'tail' => "Zeile 1\n<script>alert(1)</script>\n",
        'download_url' => '/admin/ad/anmeldeinstanzen/protokoll/download?service=auth-mvzintwf'];
    $html = View::render('admin.ldap', ['logs' => $ready] + $data);
    Assert::contains('id="ad-logs-dialog" class="ad-logs" aria-labelledby="ad-logs-title" open', $html,
        'Mit Ergebnis wird das Overlay geoeffnet ausgeliefert.');
    Assert::contains('2 Einträge', $html, 'Die Anzahl der Eintraege steht im Overlay.');
    Assert::contains('Zeile 1', $html);
    Assert::contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'Die Protokollausgabe wird escaped.');
    Assert::false(str_contains($html, '<script>alert(1)</script>'), 'Protokollinhalte werden nie als Markup ausgegeben.');
    Assert::contains('href="/admin/ad/anmeldeinstanzen/protokoll/download?service=auth-mvzintwf"', $html);

    $error = ['service' => 'auth', 'state' => 'error', 'message' => 'Die Instanz auth läuft nicht.',
        'tail' => '', 'lines' => 0, 'generated_at' => '', 'download_url' => ''];
    $html = View::render('admin.ldap', ['logs' => $error] + $data);
    Assert::contains('Die Instanz auth läuft nicht.', $html, 'Der Fehler wird im Overlay benannt.');
    Assert::contains('ad-logs__status--error', $html);
    Assert::contains('id="ad-logs-download" href="#" hidden', $html, 'Ohne Protokoll gibt es keinen Download-Link.');
    Assert::contains('Keine Ausgabe.', ssoAgentFile('public/assets/css/admin.css'));

    // Das Overlay holt das Ergebnis nach (Auftrag sofort bestaetigen, dann im
    // Sekundentakt nachfragen) - sonst laeuft die Anfrage in max_execution_time.
    $js = ssoAgentFile('public/assets/js/admin-ldap.js');
    Assert::contains("'/admin/ad/anmeldeinstanzen/protokoll/inhalt'", $js);
    Assert::contains('ad-logs-dialog', $js);
    Assert::contains('showModal', $js);
    Assert::contains('data-ad-logs', ssoAgentFile('views/admin/ldap.php'));
});
