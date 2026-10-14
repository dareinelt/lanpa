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
