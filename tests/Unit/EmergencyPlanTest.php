<?php

declare(strict_types=1);

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Repositories\EmergencyPlanRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\SettingsRepository;
use App\Services\EmergencyPlanDefinition;
use App\Services\EmergencyPlanService;
use App\Services\EmergencyPlanSms;
use App\Services\EmergencyPlanTransfer;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function emergencyPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE emergency_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, published INTEGER DEFAULT 0, revision INTEGER DEFAULT 1, definition TEXT, updated_by TEXT, updated_at TEXT, review_state TEXT DEFAULT 'draft', contributors TEXT, submitted_by TEXT, submitted_at TEXT, published_definition TEXT, published_revision INTEGER)");
    $pdo->exec("CREATE TABLE emergency_plan_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER, revision INTEGER, actor TEXT, action TEXT, comment TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE emergency_events (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER, title TEXT, actor TEXT, request_key TEXT UNIQUE, snapshot TEXT, state TEXT, coordination TEXT, revision INTEGER DEFAULT 1, status TEXT DEFAULT 'active', started_at TEXT, closed_at TEXT, trigger_group TEXT)");
    $pdo->exec("CREATE TABLE emergency_log (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER, node_id TEXT, actor TEXT, action TEXT, message TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE emergency_sms (event_id INTEGER, node_id TEXT, status TEXT, message TEXT, PRIMARY KEY (event_id, node_id))");
    $pdo->exec("CREATE TABLE emergency_password_attempts (actor TEXT PRIMARY KEY, window_start INTEGER, attempts INTEGER)");
    $pdo->exec("CREATE TABLE emergency_plan_attachments (id TEXT PRIMARY KEY, mime TEXT, size INTEGER, data TEXT, created_by TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE mail_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER, recipient TEXT, subject TEXT, body TEXT, available_at INTEGER, created_at TEXT, status TEXT DEFAULT 'queued', attempts INTEGER DEFAULT 0, message TEXT DEFAULT '')");

    return $pdo;
}

function emergencyNode(string $id, string $type = 'action', array $dependencies = []): array
{
    return ['id' => $id, 'title' => $id, 'type' => $type, 'dependencies' => $dependencies, 'checks' => $type === 'checklist' ? ['Prüfpunkt A', 'Prüfpunkt B'] : [], 'alarm_id' => $type === 'sms' ? 1 : 0];
}

function emergencyDefinition(): array
{
    return EmergencyPlanDefinition::validate(['title' => 'Brandfall', 'nodes' => [
        emergencyNode('entscheidung', 'decision'),
        emergencyNode('ja', 'action', [['id' => 'entscheidung', 'when' => 'yes']]),
        emergencyNode('nein', 'checklist', [['id' => 'entscheidung', 'when' => 'no']]),
        emergencyNode('ende', 'action', [['id' => 'ja', 'when' => 'always'], ['id' => 'nein', 'when' => 'always']]) + ['join' => 'any'],
    ]]);
}

function emergencyUser(): array
{
    return ['username' => 'demo', 'office_uid' => 'demo@quelle', 'source_id' => 2, 'groups' => ['NOTFALL'], 'fake' => false];
}

function emergencyService(PDO $pdo, ?Closure $verify = null, ?Closure $sms = null): EmergencyPlanService
{
    $settings = new SettingsService(new SettingsRepository($pdo));
    $pdo->exec("INSERT OR REPLACE INTO settings VALUES ('emergency_plan_enabled', '1'), ('emergency_plan_group', 'Notfall')");

    return new EmergencyPlanService(new EmergencyPlanRepository($pdo), $settings, new NavigationRepository($pdo),
        $verify ?? static fn (array $user, string $password): bool => $password === ' correct password ',
        static fn (): array => ['emails' => ['kaep@example.test'], 'missing' => 1],
        $sms ?? static fn (array $alarm): array => ['status' => 'success', 'message' => 'Gateway bestätigt.'],
        'https://intranet.example.test');
}

function emergencyStart(EmergencyPlanService $service, ?array $definition = null): int
{
    $id = $service->repository->savePlan(0, 0, $definition ?? emergencyDefinition(), 'local:admin');
    $service->repository->submit($id, 1, 'local:admin');
    $service->repository->review($id, 1, 'local:reviewer', true, 'Geprüft.');

    return $service->start($id, 1, emergencyUser(), ' correct password ', bin2hex(random_bytes(32)));
}

function emergencyThrows(callable $callback, int $status): void
{
    try {
        $callback();
    } catch (HttpException $exception) {
        Assert::same($status, $exception->statusCode());
        return;
    }
    throw new RuntimeException('Erwarteter HTTP-Fehler fehlt.');
}

Runner::test('Notfallplan: Rollen und fail-closed Gruppenfreigabe', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    Assert::true(EmergencyPlanService::isManager('kaep'));
    Assert::true(EmergencyPlanService::isManager('admin'));
    Assert::false(EmergencyPlanService::isManager('redaktion'));
    Assert::false(EmergencyPlanService::isManager(null));
    Assert::true($service->canView(emergencyUser()));
    Assert::false($service->canView(null));
    Assert::false($service->canView(array_replace(emergencyUser(), ['groups' => []])));
    $pdo->exec("UPDATE settings SET setting_value = '' WHERE setting_key = 'emergency_plan_group'");
    $withoutGroup = new EmergencyPlanService(new EmergencyPlanRepository($pdo), new SettingsService(new SettingsRepository($pdo)), new NavigationRepository($pdo), static fn () => true, static fn () => [], static fn () => [], '');
    Assert::false($withoutGroup->canView(emergencyUser()));
    $pdo->exec("UPDATE settings SET setting_value = 'Notfall' WHERE setting_key = 'emergency_plan_group'");
    $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'emergency_plan_enabled'");
    $disabled = new EmergencyPlanService(new EmergencyPlanRepository($pdo), new SettingsService(new SettingsRepository($pdo)), new NavigationRepository($pdo), static fn () => true, static fn () => [], static fn () => [], '');
    Assert::false($disabled->canView(emergencyUser()));
});

Runner::test('Notfallplan: Auslösegruppe nur für Auslösen und laufende eigene Ereignisse', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    $trigger = array_replace(emergencyUser(), ['groups' => ['Ausloeser']]);
    Assert::same(EmergencyPlanService::ACCESS_FULL, $service->accessLevel(emergencyUser()));
    Assert::null($service->accessLevel($trigger));
    $pdo->exec("INSERT OR REPLACE INTO settings VALUES ('emergency_plan_trigger_group', 'AUSLOESER')");
    $service = emergencyService($pdo);
    Assert::same(EmergencyPlanService::ACCESS_TRIGGER, $service->accessLevel($trigger));
    Assert::same(EmergencyPlanService::ACCESS_FULL, $service->accessLevel(array_replace(emergencyUser(), ['groups' => ['Ausloeser', 'Notfall']])));
    Assert::null($service->accessLevel(null));
    Assert::null(EmergencyPlanService::dashboardActor($trigger, null, null, null));

    $plan = $service->repository->savePlan(0, 0, emergencyDefinition(), 'local:admin');
    $service->repository->submit($plan, 1, 'local:admin');
    $service->repository->review($plan, 1, 'local:reviewer', true, 'Geprüft.');
    $id = $service->start($plan, 1, $trigger, ' correct password ', bin2hex(random_bytes(32)));
    $event = $service->requireEvent($id, 'ad:demo@quelle', false, true);
    $service->update($event, 'ad:demo@quelle', ['revision' => 1, 'action' => 'close', 'comment' => 'Übung beendet.']);
    emergencyThrows(fn () => $service->requireEvent($id, 'ad:demo@quelle', false, true), 403);
    Assert::same($id, (int) $service->requireEvent($id, 'ad:demo@quelle', false)['id']);
    Assert::same($id, (int) $service->requireEvent($id, 'local:kaep', true, true)['id']);
    Assert::same('ausloeser', $service->repository->event($id)['trigger_group']);

    // Gemeinsame Abarbeitung: andere Mitglieder der Auslösegruppe sehen laufende Ereignisse.
    $colleague = array_replace($trigger, ['office_uid' => 'kollege@quelle']);
    Assert::same('ausloeser', $service->triggerGroup($colleague));
    Assert::null($service->triggerGroup(emergencyUser()));
    $shared = $service->start($plan, 1, $trigger, ' correct password ', bin2hex(random_bytes(32)));
    $own = $service->start($plan, 1, emergencyUser(), ' correct password ', bin2hex(random_bytes(32)));
    $sharedEvent = $service->requireEvent($shared, 'ad:kollege@quelle', false, true, 'ausloeser');
    $service->update($sharedEvent, 'ad:kollege@quelle', ['revision' => 1, 'node' => 'entscheidung', 'action' => 'comment', 'comment' => 'Übernommen.']);
    emergencyThrows(fn () => $service->requireEvent($shared, 'ad:kollege@quelle', false, true), 403);
    emergencyThrows(fn () => $service->requireEvent($shared, 'ad:kollege@quelle', false, true, 'andere'), 403);
    emergencyThrows(fn () => $service->requireEvent($own, 'ad:kollege@quelle', false, true, 'ausloeser'), 403);
    emergencyThrows(fn () => $service->requireEvent($id, 'ad:kollege@quelle', false, true, 'ausloeser'), 403);
    $list = $service->repository->events('ad:kollege@quelle', 'active', '', '', 1, 'ausloeser');
    Assert::same([$shared], array_map(static fn (array $row) => (int) $row['id'], $list['items']));
    Assert::same(0, $service->repository->events('ad:kollege@quelle', 'active', '', '', 1)['total']);

    $pdo->exec("UPDATE settings SET setting_value = '' WHERE setting_key = 'emergency_plan_group'");
    $onlyTrigger = new EmergencyPlanService(new EmergencyPlanRepository($pdo), new SettingsService(new SettingsRepository($pdo)), new NavigationRepository($pdo), static fn () => true, static fn () => [], static fn () => [], '');
    Assert::true($onlyTrigger->canView($trigger));
    Assert::false($onlyTrigger->canView(emergencyUser()));
    $pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'emergency_plan_enabled'");
    $disabled = new EmergencyPlanService(new EmergencyPlanRepository($pdo), new SettingsService(new SettingsRepository($pdo)), new NavigationRepository($pdo), static fn () => true, static fn () => [], static fn () => [], '');
    Assert::false($disabled->canView($trigger));
});

Runner::test('Notfallplan: Graphvalidierung, Grenzen und sichere Links', static function (): void {
    $cases = [
        ['title' => 'x', 'nodes' => []],
        ['title' => 'x', 'nodes' => array_fill(0, 81, emergencyNode('x'))],
        ['title' => 'x', 'nodes' => [emergencyNode('x'), emergencyNode('x')]],
        ['title' => 'x', 'nodes' => [emergencyNode('x', 'action', [['id' => 'x', 'when' => 'always']])]],
        ['title' => 'x', 'nodes' => [emergencyNode('x'), emergencyNode('y', 'action', [['id' => 'x', 'when' => 'yes']])]],
        ['title' => 'x', 'nodes' => [emergencyNode('x') + ['link' => 'javascript:alert(1)']]],
        ['title' => 'x', 'nodes' => [emergencyNode('x') + ['minutes' => -1]]],
        ['title' => 'x', 'nodes' => [array_replace(emergencyNode('x', 'checklist'), ['checks' => []])]],
        ['title' => 'x', 'nodes' => [array_replace(emergencyNode('x', 'sms'), ['alarm_id' => 0])]],
        ['title' => 'x', 'nodes' => [emergencyNode('x') + ['x' => 20]]],
        ['title' => 'x', 'nodes' => [emergencyNode('x') + ['x' => -1, 'y' => 0]]],
        ['title' => 'x', 'nodes' => [emergencyNode('x') + ['x' => 'links', 'y' => 0]]],
        ['title' => 'x', 'nodes' => [emergencyNode('x') + ['x' => 0, 'y' => EmergencyPlanDefinition::MAX_COORDINATE + 1]]],
    ];
    foreach ($cases as $input) {
        $rejected = false;
        try { EmergencyPlanDefinition::validate($input); } catch (ValidationException) { $rejected = true; }
        Assert::true($rejected);
    }
    Assert::same('https://example.test/info', EmergencyPlanDefinition::validate(['title' => 'x', 'nodes' => [emergencyNode('x') + ['link' => 'https://example.test/info']]])['nodes'][0]['link']);

    $positioned = EmergencyPlanDefinition::validate(['title' => 'x', 'nodes' => [emergencyNode('x') + ['x' => 40, 'y' => 160], emergencyNode('y') + ['x' => null, 'y' => null]]])['nodes'];
    Assert::same([40, 160], [$positioned[0]['x'], $positioned[0]['y']]);
    Assert::false(array_key_exists('x', $positioned[1]) || array_key_exists('y', $positioned[1]));
});

Runner::test('Notfallplan: Ja/Nein-Zweige und ODER-Zusammenführung', static function (): void {
    $definition = emergencyDefinition();
    Assert::same(['entscheidung' => 'ready', 'ja' => 'waiting', 'nein' => 'waiting', 'ende' => 'waiting'], EmergencyPlanDefinition::readiness($definition, []));
    $state = ['entscheidung' => ['status' => 'done', 'answer' => 'yes']];
    Assert::same(['entscheidung' => 'ready', 'ja' => 'ready', 'nein' => 'skipped', 'ende' => 'waiting'], EmergencyPlanDefinition::readiness($definition, $state));
    $state['ja'] = ['status' => 'done'];
    Assert::same('ready', EmergencyPlanDefinition::readiness($definition, $state)['ende']);
    $definition['nodes'][3]['join'] = 'all';
    Assert::same('skipped', EmergencyPlanDefinition::readiness($definition, $state)['ende']);
});

Runner::test('Notfallplan: Kennwort, echte AD-Identität, Version und Entwurfsstatus', static function (): void {
    $service = emergencyService(emergencyPdo());
    $id = $service->repository->savePlan(0, 0, emergencyDefinition(), 'admin');
    $service->repository->submit($id, 1, 'admin');
    $service->repository->review($id, 1, 'reviewer', true, '');
    $key = bin2hex(random_bytes(32));
    emergencyThrows(fn () => $service->start($id, 1, emergencyUser(), 'wrong', $key), 403);
    emergencyThrows(fn () => $service->start($id, 1, array_replace(emergencyUser(), ['fake' => true]), ' correct password ', $key), 403);
    emergencyThrows(fn () => $service->start($id, 1, array_replace(emergencyUser(), ['groups' => []]), ' correct password ', $key), 403);
    emergencyThrows(fn () => $service->start($id, 0, emergencyUser(), ' correct password ', $key), 409);
    $service->repository->withdraw($id, 1, 'admin');
    emergencyThrows(fn () => $service->start($id, 2, emergencyUser(), ' correct password ', $key), 404);
    Assert::same(0, $service->repository->events(null, '', '', '', 1)['total']);
});

Runner::test('Notfallplan: AD-Ausfall startet kein Ereignis', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo, static function (): bool { throw new RuntimeException('AD down'); });
    emergencyThrows(fn () => emergencyStart($service), 503);
    Assert::same(0, (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn());
});

Runner::test('Notfallplan: Bestätigungsbegrenzung ist sitzungsunabhängig', static function (): void {
    $pdo = emergencyPdo();
    $repo = new EmergencyPlanRepository($pdo);
    for ($i = 0; $i < 5; $i++) Assert::true($repo->reservePasswordAttempt('ad:demo', 1000));
    Assert::false((new EmergencyPlanRepository($pdo))->reservePasswordAttempt('ad:demo', 1299));
    Assert::true($repo->reservePasswordAttempt('ad:other', 1299));
    Assert::true($repo->reservePasswordAttempt('ad:demo', 1300));
});

Runner::test('Notfallplan: Start snapshot, transaktionale E-Mail und Doppelklickschutz', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    $plan = $service->repository->savePlan(0, 0, emergencyDefinition(), 'admin');
    $service->repository->submit($plan, 1, 'admin');
    $service->repository->review($plan, 1, 'reviewer', true, '');
    $key = bin2hex(random_bytes(32));
    $id = $service->start($plan, 1, emergencyUser(), ' correct password ', $key);
    Assert::same($id, $service->start($plan, 1, emergencyUser(), '', $key));
    Assert::same(1, (int) $pdo->query('SELECT COUNT(*) FROM emergency_events')->fetchColumn());
    Assert::same(1, (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn());
    Assert::contains('1 KAEP-Mitglieder ohne', $service->repository->logs($id)[1]['message']);
    $updated = emergencyDefinition(); $updated['title'] = 'Geänderter Plan';
    $service->repository->savePlan($plan, 1, $updated, 'admin');
    Assert::same('Brandfall', $service->repository->event($id)['snapshot']['title']);
    Assert::same('ad:demo@quelle', $service->repository->event($id)['actor']);
    Assert::contains('https://intranet.example.test/admin/notfallplan/ereignis?id=' . $id, $pdo->query('SELECT body FROM mail_outbox')->fetchColumn());
});

Runner::test('Notfallplan: E-Mail-Queuefehler rollt Start vollständig zurück', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    $pdo->exec('DROP TABLE mail_outbox');
    $failed = false;
    try { emergencyStart($service); } catch (PDOException) { $failed = true; }
    Assert::true($failed);
    Assert::same(0, (int) $pdo->query('SELECT COUNT(*) FROM emergency_events')->fetchColumn());
    Assert::same(0, (int) $pdo->query('SELECT COUNT(*) FROM emergency_log')->fetchColumn());
});

Runner::test('Notfallplan: Ereignisrechte und konkurrierende Änderungen', static function (): void {
    $service = emergencyService(emergencyPdo());
    $id = emergencyStart($service);
    $event = $service->requireEvent($id, 'ad:demo@quelle', false);
    emergencyThrows(fn () => $service->requireEvent($id, 'ad:other', false), 403);
    Assert::same($id, (int) $service->requireEvent($id, 'local:kaep', true)['id']);
    $service->update($event, 'ad:demo@quelle', ['revision' => 1, 'node' => 'entscheidung', 'action' => 'comment', 'comment' => 'Leitung informiert.']);
    emergencyThrows(fn () => $service->update($event, 'local:kaep', ['revision' => 1, 'node' => 'entscheidung', 'action' => 'comment', 'comment' => 'Parallel']), 409);
    Assert::same(3, count($service->repository->logs($id)));
});

Runner::test('Notfallplan: Statusbedingungen, Entscheidungen und Checklisten', static function (): void {
    $service = emergencyService(emergencyPdo());
    $id = emergencyStart($service);
    $event = $service->repository->event($id);
    emergencyThrows(fn () => $service->update($event, 'actor', ['revision' => 1, 'node' => 'ja', 'action' => 'status', 'status' => 'done']), 409);
    $service->update($event, 'actor', ['revision' => 1, 'node' => 'entscheidung', 'action' => 'status', 'status' => 'done', 'answer' => 'no']);
    $event = $service->repository->event($id);
    emergencyThrows(fn () => $service->update($event, 'actor', ['revision' => 2, 'node' => 'entscheidung', 'action' => 'status', 'status' => 'open']), 409);
    $failed = false;
    try { $service->update($event, 'actor', ['revision' => 2, 'node' => 'nein', 'action' => 'status', 'status' => 'done', 'checks' => ['0']]); } catch (ValidationException) { $failed = true; }
    Assert::true($failed);
    $service->update($event, 'actor', ['revision' => 2, 'node' => 'nein', 'action' => 'status', 'status' => 'done', 'checks' => ['0', '1']]);
    Assert::same('done', $service->repository->event($id)['state']['nein']['status']);
});

Runner::test('Notfallplan: begründeter Abschluss und unveränderliche Historie', static function (): void {
    $service = emergencyService(emergencyPdo());
    $id = emergencyStart($service);
    $event = $service->repository->event($id);
    $failed = false;
    try { $service->update($event, 'actor', ['revision' => 1, 'action' => 'close']); } catch (ValidationException) { $failed = true; }
    Assert::true($failed);
    $service->update($event, 'actor', ['revision' => 1, 'action' => 'close', 'comment' => 'Übung beendet, Restmaßnahmen entfallen.']);
    $closed = $service->repository->event($id);
    Assert::same('closed', $closed['status']);
    emergencyThrows(fn () => $service->update($closed, 'actor', ['revision' => 2, 'node' => 'entscheidung', 'action' => 'comment', 'comment' => 'Nachtrag']), 409);
    Assert::same(1, $service->repository->events(null, 'closed', '', '', 1)['total']);
    Assert::same(0, $service->repository->events('ad:other', '', '', '', 1)['total']);
});

Runner::test('Notfallplan: SMS separat, Snapshot und höchstens ein Versuch', static function (): void {
    $sent = [];
    $service = emergencyService(emergencyPdo(), null, static function (array $alarm) use (&$sent): array {
        $sent[] = $alarm;
        return ['status' => 'error', 'message' => 'Gateway nicht erreichbar.'];
    });
    $definition = EmergencyPlanDefinition::validate(['title' => 'SMS', 'nodes' => [emergencyNode('sms', 'sms')]]);
    $definition['nodes'][0]['alarm'] = ['title' => 'Test', 'alarm_text' => 'Demo', 'alarm_group_number' => '99', 'alarm_group_type' => 'group'];
    $id = emergencyStart($service, $definition);
    Assert::same([], $sent, 'Start versendet keine SMS.');
    $service->update($service->repository->event($id), 'actor', ['revision' => 1, 'node' => 'sms', 'action' => 'sms']);
    Assert::same(1, count($sent));
    Assert::same('99', $sent[0]['alarm_group_number']);
    $event = $service->repository->event($id);
    Assert::same('error', $event['sms']['sms']['status']);
    emergencyThrows(fn () => $service->update($event, 'actor', ['revision' => $event['revision'], 'node' => 'sms', 'action' => 'sms']), 409);
    $service->update($event, 'actor', ['revision' => $event['revision'], 'node' => 'sms', 'action' => 'status', 'status' => 'done', 'comment' => 'Telefonisch alarmiert.']);
    Assert::same('done', $service->repository->event($id)['state']['sms']['status']);
});

Runner::test('KAEP: Verzeichnisrolle getrennt, Administratorrechte vorrangig', static function (): void {
    $service = adminGroupService(adminGroupsPdo());
    $service->addRule('kaep', 'GG_KAEP', 'admin');
    Assert::same('kaep', $service->intranetRole(['gg_kaep']));
    Assert::null($service->intranetRole(['andere']));
    $service->addRule('intranet', 'GG_ADMINS', 'admin');
    Assert::same('admin', $service->intranetRole(['GG_KAEP', 'GG_ADMINS']));
});

Runner::test('Notfallplan: Einzelne Prüfpunkte samt Person, Zeit und Rücknahme auswertbar', static function (): void {
    $service = emergencyService(emergencyPdo());
    $definition = EmergencyPlanDefinition::validate(['title' => 'Kontakte', 'nodes' => [emergencyNode('kontakte', 'checklist')]]);
    $id = emergencyStart($service, $definition);
    $update = static function (string $actor, array $checks) use ($service, $id): void {
        $event = $service->repository->event($id);
        $service->update($event, $actor, ['revision' => $event['revision'], 'node' => 'kontakte', 'action' => 'status', 'status' => 'in_progress', 'checks' => $checks]);
    };
    $update('ad:person-a', ['0']);
    $first = $service->repository->event($id)['state']['kontakte']['check_details'][0];
    Assert::same('ad:person-a', $first['actor']);
    Assert::true($first['at'] !== '');
    $update('local:person-b', ['0', '1']);
    Assert::same($first, $service->repository->event($id)['state']['kontakte']['check_details'][0]);
    Assert::same('local:person-b', $service->repository->event($id)['state']['kontakte']['check_details'][1]['actor']);
    $update('local:person-b', ['1']);
    Assert::false(isset($service->repository->event($id)['state']['kontakte']['check_details'][0]));
    $logs = array_values(array_filter($service->repository->logs($id), static fn ($log) => str_starts_with($log['action'], 'check_')));
    Assert::same(['check_done', 'check_done', 'check_reopened'], array_column($logs, 'action'));
    Assert::same('local:person-b', $logs[2]['actor']);
    Assert::contains('Prüfpunkt 1: Prüfpunkt A', $logs[2]['message']);
});

Runner::test('Notfallplan: Vier-Augen-Pflicht für Autoren, Mitautoren und Antragsteller', static function (): void {
    $repo = new EmergencyPlanRepository(emergencyPdo());
    $id = $repo->savePlan(0, 0, emergencyDefinition(), 'local:admin');
    emergencyThrows(fn () => $repo->publishedPlan($id), 404);
    $repo->savePlan($id, 1, emergencyDefinition(), 'ad:kaep-b');
    $repo->submit($id, 2, 'ad:kaep-c');
    foreach (['local:admin', 'ad:kaep-b', 'ad:kaep-c'] as $actor) {
        emergencyThrows(fn () => $repo->review($id, 2, $actor, true, ''), 403);
    }
    $repo->review($id, 2, 'ad:kaep-d', true, 'Fachlich geprüft.');
    $published = $repo->publishedPlan($id);
    Assert::same(['local:admin', 'ad:kaep-b'], $published['definition']['publication']['authors']);
    Assert::same('ad:kaep-d', $published['definition']['publication']['approved_by']);
    Assert::same(2, $published['definition']['publication']['revision']);
    Assert::same('approved', $repo->plan($id)['review_state']);
    emergencyThrows(fn () => $repo->review($id, 2, 'ad:kaep-e', true, ''), 409);
});

Runner::test('Notfallplan: Ablehnung benötigt Kommentar, Historie bleibt erhalten', static function (): void {
    $repo = new EmergencyPlanRepository(emergencyPdo());
    $id = $repo->savePlan(0, 0, emergencyDefinition(), 'ad:author');
    $repo->submit($id, 1, 'ad:author');
    $rejected = false;
    try { $repo->review($id, 1, 'ad:reviewer', false, '   '); } catch (ValidationException) { $rejected = true; }
    Assert::true($rejected);
    Assert::same('pending', $repo->plan($id)['review_state']);
    $repo->review($id, 1, 'ad:reviewer', false, 'Telefonnummer der Pforte fehlt.');
    Assert::same('rejected', $repo->plan($id)['review_state']);
    Assert::same('Telefonnummer der Pforte fehlt.', $repo->reviews($id)[2]['comment']);
    emergencyThrows(fn () => $repo->publishedPlan($id), 404);
    $repo->submit($id, 1, 'ad:author');
    $repo->review($id, 1, 'ad:reviewer', true, 'Nach Rücksprache freigegeben.');
    Assert::same(5, count($repo->reviews($id)));
});

Runner::test('Notfallplan: Entwurf ändert Live-Plan nicht; veraltete Freigabe scheitert', static function (): void {
    $repo = new EmergencyPlanRepository(emergencyPdo());
    $id = $repo->savePlan(0, 0, emergencyDefinition(), 'ad:author');
    $repo->submit($id, 1, 'ad:author');
    $repo->review($id, 1, 'ad:reviewer', true, '');
    $definition = emergencyDefinition();
    $definition['title'] = 'Unveröffentlichter Titel';
    $repo->savePlan($id, 1, $definition, 'ad:author');
    Assert::same('Brandfall', $repo->publishedPlan($id)['title']);
    Assert::same('Brandfall', $repo->plans(true)[0]['title']);
    Assert::same(1, (int) $repo->publishedPlan($id)['revision']);
    $repo->submit($id, 2, 'ad:author');
    $repo->savePlan($id, 2, $definition, 'ad:other');
    emergencyThrows(fn () => $repo->review($id, 2, 'ad:reviewer', true, ''), 409);
    Assert::same('draft', $repo->plan($id)['review_state']);
    $repo->submit($id, 3, 'ad:author');
    $repo->review($id, 3, 'ad:reviewer', true, '');
    Assert::same('Unveröffentlichter Titel', $repo->publishedPlan($id)['title']);
    $repo->withdraw($id, 3, 'ad:author');
    emergencyThrows(fn () => $repo->publishedPlan($id), 404);
    emergencyThrows(fn () => $repo->review($id, 4, 'ad:reviewer', true, ''), 409);
});

Runner::test('Notfallplan: Rücknahme durch Prüfer erzeugt keine künstliche Mitautorschaft', static function (): void {
    $repo = new EmergencyPlanRepository(emergencyPdo());
    $id = $repo->savePlan(0, 0, emergencyDefinition(), 'ad:author');
    $repo->submit($id, 1, 'ad:author');
    $repo->review($id, 1, 'ad:reviewer', true, '');
    $repo->withdraw($id, 1, 'ad:reviewer');
    Assert::same(['ad:author'], $repo->plan($id)['contributors']);
    emergencyThrows(fn () => $repo->publishedPlan($id), 404);
    emergencyThrows(fn () => $repo->review($id, 2, 'ad:reviewer', true, ''), 409);
    $repo->submit($id, 2, 'ad:author');
    emergencyThrows(fn () => $repo->review($id, 2, 'ad:author', true, ''), 403);
    $repo->review($id, 2, 'ad:reviewer', true, 'Erneut geprüft.');
    Assert::same(2, (int) $repo->publishedPlan($id)['revision']);
});

function emergencyAlarmTables(PDO $pdo, array $alarms): void
{
    $pdo->exec('CREATE TABLE alarm_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, group_number TEXT, description TEXT, type TEXT)');
    $pdo->exec('CREATE TABLE navigation_items (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, url TEXT, type TEXT, parent_id INTEGER, icon TEXT, background_color TEXT, background_opacity INTEGER, override_background INTEGER, short_description TEXT, description TEXT, content TEXT, alarm_text TEXT, alarm_group_id INTEGER, protected_access INTEGER, sort_order INTEGER, active INTEGER, created_at TEXT, updated_at TEXT)');
    foreach ($alarms as [$title, $text, $number]) {
        $pdo->prepare("INSERT INTO alarm_groups (group_number, description, type) VALUES (?, '', 'group')")->execute([$number]);
        $pdo->prepare("INSERT INTO navigation_items (title, type, alarm_text, alarm_group_id, active, sort_order) VALUES (?, 'alarm', ?, ?, 1, 0)")
            ->execute([$title, $text, (int) $pdo->lastInsertId()]);
    }
}

function emergencyImportRejected(EmergencyPlanService $service, string $contents): string
{
    try {
        $service->importPlans($contents, 'local:admin');
    } catch (ValidationException $exception) {
        return implode(' ', $exception->errors());
    }
    throw new RuntimeException('Import hätte abgelehnt werden müssen.');
}

function emergencyTransfer(EmergencyPlanService $service, int $partMaxBytes = EmergencyPlanTransfer::PART_MAX_BYTES, ?string $directory = null): EmergencyPlanTransfer
{
    $directory ??= sys_get_temp_dir() . '/lanpa-ep-transfer-' . bin2hex(random_bytes(6));
    register_shutdown_function(static function () use ($directory): void {
        if (!is_dir($directory)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    });

    return new EmergencyPlanTransfer($service, $directory, $partMaxBytes);
}

/** @return list<array{name:string,contents:string}> */
function emergencyExportFiles(EmergencyPlanTransfer $transfer, array $ids, string $actor = 'local:admin'): array
{
    $export = $transfer->createExport($ids, $actor);
    $files = [];
    foreach ($export['parts'] as $part) {
        $file = $transfer->exportPart($export['set'], $part['part'], $actor);
        $files[] = ['name' => $file['name'], 'contents' => (string) file_get_contents($file['path'])];
    }

    return $files;
}

/** @return list<int> */
function emergencyImportFiles(EmergencyPlanTransfer $transfer, array $files, string $actor = 'local:admin'): array
{
    $status = null;
    foreach ($files as $file) {
        $status = $transfer->stageImport($file['contents'], $file['name'], $actor);
    }

    return $transfer->importStaged($status['set'], $actor);
}

function emergencyTransferRejected(callable $callback): string
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return implode(' ', $exception->errors());
    }
    throw new RuntimeException('Hätte abgelehnt werden müssen.');
}

Runner::test('Notfallplan: Export und Import zwischen Systemen als neue Entwürfe', static function (): void {
    $sourcePdo = emergencyPdo();
    emergencyAlarmTables($sourcePdo, [['Werkschutz', 'Brand im Werk', '100']]);
    $source = emergencyService($sourcePdo);
    $definition = emergencyDefinition();
    $definition['nodes'][] = array_replace(emergencyNode('sms', 'sms', [['id' => 'ende', 'when' => 'always']]), ['alarm_id' => 1, 'phone' => '0800 112']);
    $id = $source->save(0, 0, $definition, 'local:autor');
    $source->repository->submit($id, 1, 'local:autor');
    $source->repository->review($id, 1, 'local:pruefer', true, 'Geprüft.');
    $files = emergencyExportFiles(emergencyTransfer($source), [$id]);
    Assert::same(1, count($files));
    Assert::true(str_starts_with($files[0]['name'], 'notfallplan-brandfall_') && str_ends_with($files[0]['name'], '_teil-1-von-1.json'));
    $data = json_decode($files[0]['contents'], true, 64, JSON_THROW_ON_ERROR);
    Assert::same(EmergencyPlanService::EXPORT_FORMAT, $data['format']);
    Assert::same(EmergencyPlanTransfer::VERSION, $data['version']);
    Assert::same(['part' => 1, 'parts' => 1], ['part' => $data['set']['part'], 'parts' => $data['set']['parts']]);
    Assert::same('Werkschutz', $data['plans'][0]['definition']['nodes'][4]['alarm']['title']);

    // Zielsystem mit anderer ID der gleichnamigen Alarmierung.
    $targetPdo = emergencyPdo();
    emergencyAlarmTables($targetPdo, [['Andere', 'x', '1'], ['werkschutz ', 'Brand im Werk (neu)', '200']]);
    $target = emergencyService($targetPdo);
    $target->repository->savePlan(0, 0, emergencyDefinition(), 'local:bestand');
    $files[0]['contents'] = "\xEF\xBB\xBF" . $files[0]['contents'];
    $ids = emergencyImportFiles(emergencyTransfer($target), $files);
    Assert::same([2], $ids);
    $plan = $target->repository->plan(2);
    Assert::same('Brandfall', $plan['title']);
    Assert::same('draft', $plan['review_state']);
    Assert::same(0, (int) $plan['published']);
    Assert::same(['local:admin'], $plan['contributors']);
    Assert::same(2, $plan['definition']['nodes'][4]['alarm_id']);
    Assert::same('200', $plan['definition']['nodes'][4]['alarm']['alarm_group_number']);
    Assert::same('0800 112', $plan['definition']['nodes'][4]['phone']);
    Assert::same('imported', $target->repository->reviews(2)[0]['action']);
    Assert::same('Brandfall', $target->repository->plan(1)['title'], 'Bestehende Pläne bleiben unverändert.');
    emergencyThrows(fn () => $target->repository->review(2, 1, 'local:admin', true, ''), 403);
});

Runner::test('Notfallplan: Import prüft Format, Inhalt und Alarmvorlagen vollständig', static function (): void {
    $pdo = emergencyPdo();
    emergencyAlarmTables($pdo, [['Doppelt', 'a', '1'], ['Doppelt', 'b', '2']]);
    $service = emergencyService($pdo);
    $wrap = static fn (array $plans, array $extra = []): string => json_encode($extra + ['format' => EmergencyPlanService::EXPORT_FORMAT, 'version' => 1, 'plans' => $plans]);
    $valid = ['definition' => emergencyDefinition()];
    Assert::true(str_contains(emergencyImportRejected($service, 'kein json'), 'JSON'));
    Assert::true(str_contains(emergencyImportRejected($service, $wrap([$valid], ['format' => 'andere'])), 'keine Notfallplan-Exportdatei'));
    Assert::true(str_contains(emergencyImportRejected($service, $wrap([$valid], ['version' => 99])), 'Version'));
    Assert::true(str_contains(emergencyImportRejected($service, $wrap([])), '1 bis'));
    $broken = ['definition' => ['title' => 'Kaputt', 'nodes' => [emergencyNode('x') + ['link' => 'javascript:alert(1)']]]];
    Assert::true(str_contains(emergencyImportRejected($service, $wrap([$valid, $broken])), 'Plan „Kaputt“'));
    $sms = static fn (string $title, array $alarm): array => ['definition' => ['title' => $title, 'nodes' => [array_replace(emergencyNode('s', 'sms'), ['alarm' => $alarm])]]];
    $message = emergencyImportRejected($service, $wrap([$valid, $sms('A', ['title' => 'Fehlt']), $sms('B', ['title' => 'Doppelt', 'alarm_text' => 'c', 'alarm_group_number' => '3'])]));
    Assert::true(str_contains($message, '„Fehlt“') && str_contains($message, '„Doppelt“'));
    Assert::same([], $service->repository->plans(), 'Fehlerhafter Import legt nichts an.');
    Assert::same([1], $service->importPlans($wrap([$sms('C', ['title' => 'Doppelt', 'alarm_text' => 'b', 'alarm_group_number' => '2'])]), 'local:admin'));
    Assert::same(2, $service->repository->plan(1)['definition']['nodes'][0]['alarm_id']);
    Assert::contains('mindestens einen', emergencyTransferRejected(fn () => emergencyTransfer($service)->createExport([], 'local:admin')));
});

function emergencySmsNumbersNode(string $id, array $values = []): array
{
    return array_replace(emergencyNode($id, 'sms'), ['alarm_id' => 0, 'sms_mode' => 'numbers',
        'sms_numbers' => ['+49 171 1234567', '0171/7654321'], 'sms_text' => 'Alarm {Notfallplan} am {Datum} um {Uhrzeit}'], $values);
}

function emergencyRejected(array $input): string
{
    try {
        EmergencyPlanDefinition::validate($input);
    } catch (ValidationException $exception) {
        return implode(' ', $exception->errors());
    }
    throw new RuntimeException('Validierung hätte scheitern müssen.');
}

Runner::test('Notfallplan: SMS an einzelne Rufnummern – Rufnummern, Pflichttext und Zeichenlimit', static function (): void {
    $node = EmergencyPlanDefinition::validate(['title' => 'Brand', 'nodes' => [emergencySmsNumbersNode('s', ['alarm_id' => 7, 'sms_numbers' => [' 0171 1 ', '', '0171 1', '+49 (30) 123-4']])]])['nodes'][0];
    Assert::same('numbers', $node['sms_mode']);
    Assert::same(['0171 1', '+49 (30) 123-4'], $node['sms_numbers'], 'Rufnummern werden bereinigt und entdoppelt.');
    Assert::same(0, $node['alarm_id']);
    $legacy = EmergencyPlanDefinition::validate(['title' => 'Alt', 'nodes' => [emergencyNode('s', 'sms')]])['nodes'][0];
    Assert::same(['template', [], ''], [$legacy['sms_mode'], $legacy['sms_numbers'], $legacy['sms_text']]);
    $action = EmergencyPlanDefinition::validate(['title' => 'x', 'nodes' => [emergencyNode('a') + ['sms_mode' => 'numbers', 'sms_numbers' => ['1'], 'sms_text' => 'x']]])['nodes'][0];
    Assert::same(['template', [], ''], [$action['sms_mode'], $action['sms_numbers'], $action['sms_text']], 'Nur SMS-Elemente tragen SMS-Daten.');

    Assert::contains('Rufnummern', emergencyRejected(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_numbers' => []])]]));
    Assert::contains('Rufnummern', emergencyRejected(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_numbers' => array_map(static fn ($i) => '0171 ' . $i, range(1, 21))])]]));
    Assert::contains('Ungültige Rufnummer', emergencyRejected(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_numbers' => ['0171;rm']])]]));
    Assert::contains('SMS-Text', emergencyRejected(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_text' => '  '])]]));
    Assert::contains('SMS-Text', emergencyRejected(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_text' => str_repeat('a', 256)])]]));
    Assert::contains('Empfängerart', emergencyRejected(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_mode' => 'mail'])]]));

    // 240 Zeichen + {Datum} (10) = 250 erlaubt; 238 + 2 × {Datum} = 258 zu lang, obwohl der Rohtext nur 252 Zeichen hat.
    EmergencyPlanDefinition::validate(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_text' => str_repeat('a', 240) . '{Datum}'])]]);
    Assert::contains('258 Zeichen', emergencyRejected(['title' => 'x', 'nodes' => [emergencySmsNumbersNode('s', ['sms_text' => str_repeat('a', 238) . '{Datum}{Datum}'])]]));
    // Der Plantitel zählt beim Platzhalter {Notfallplan} mit.
    Assert::contains('Zeichen', emergencyRejected(['title' => str_repeat('T', 190), 'nodes' => [emergencySmsNumbersNode('s', ['sms_text' => str_repeat('a', 70) . '{Notfallplan}'])]]));
    Assert::same(15, EmergencyPlanSms::length('{Datum}{Uhrzeit}', 'egal', 'egal'));
    Assert::same('Brand / Schritt / {Unbekannt}', EmergencyPlanSms::render('{Notfallplan} / {Schritt} / {Unbekannt}', 'Brand', 'Schritt', 0));
    Assert::same('{Datum}', EmergencyPlanSms::render('{Notfallplan}', '{Datum}', '', 0), 'Eingesetzte Werte werden nicht erneut ersetzt.');
});

Runner::test('Notfallplan: SMS an einzelne Rufnummern – Textbausteine beim Start, Versand je Rufnummer', static function (): void {
    $sent = [];
    $service = emergencyService(emergencyPdo(), null, static function (array $alarm) use (&$sent): array {
        $sent[] = $alarm;
        return ['status' => 'success', 'message' => 'Gateway bestätigt.'];
    });
    $id = $service->save(0, 0, ['title' => 'Gasaustritt', 'nodes' => [emergencySmsNumbersNode('sms', ['title' => 'Haustechnik informieren',
        'sms_text' => '{Notfallplan}: {Schritt} – ausgelöst am {Datum} um {Uhrzeit}'])]], 'local:admin');
    $draft = $service->repository->plan($id)['definition']['nodes'][0]['alarm'];
    Assert::same('{Notfallplan}: {Schritt} – ausgelöst am {Datum} um {Uhrzeit}', $draft['alarm_text'], 'Der Entwurf behält die Textbausteine.');
    Assert::same(['+49 171 1234567', '0171/7654321'], $draft['numbers']);
    Assert::same('number', $draft['alarm_group_type']);
    $service->repository->submit($id, 1, 'local:admin');
    $service->repository->review($id, 1, 'local:reviewer', true, 'Geprüft.');
    $eventId = $service->start($id, 1, emergencyUser(), ' correct password ', bin2hex(random_bytes(32)));
    $event = $service->repository->event($eventId);
    $started = strtotime($event['started_at'] . ' UTC');
    $expected = 'Gasaustritt: Haustechnik informieren – ausgelöst am ' . date('d.m.Y', $started) . ' um ' . date('H:i', $started);
    Assert::same($expected, $event['snapshot']['nodes'][0]['alarm']['alarm_text']);
    Assert::false(isset($event['snapshot']['nodes'][0]['alarm']['placeholders']));
    Assert::same('{Notfallplan}: {Schritt} – ausgelöst am {Datum} um {Uhrzeit}',
        $service->repository->publishedPlan($id)['definition']['nodes'][0]['alarm']['alarm_text'], 'Die veröffentlichte Fassung bleibt unverändert.');
    $service->update($event, 'actor', ['revision' => 1, 'node' => 'sms', 'action' => 'sms']);
    Assert::same(1, count($sent));
    Assert::same($expected, $sent[0]['alarm_text']);
    Assert::same(['+49 171 1234567', '0171/7654321'], $sent[0]['numbers']);
    Assert::same('success', $service->repository->event($eventId)['sms']['sms']['status']);
});

Runner::test('Notfallplan: Import übernimmt SMS an einzelne Rufnummern ohne Alarmvorlage', static function (): void {
    $source = emergencyService(emergencyPdo());
    $id = $source->save(0, 0, ['title' => 'Export', 'nodes' => [emergencySmsNumbersNode('s')]], 'local:autor');
    $targetPdo = emergencyPdo();
    emergencyAlarmTables($targetPdo, []);
    $target = emergencyService($targetPdo);
    Assert::same([1], emergencyImportFiles(emergencyTransfer($target), emergencyExportFiles(emergencyTransfer($source), [$id])));
    $node = $target->repository->plan(1)['definition']['nodes'][0];
    Assert::same(['+49 171 1234567', '0171/7654321'], $node['sms_numbers']);
    Assert::same('Alarm {Notfallplan} am {Datum} um {Uhrzeit}', $node['alarm']['alarm_text']);
});

function emergencyPng(string $salt = ''): string
{
    return "\x89PNG\r\n\x1A\n" . 'testbild' . $salt;
}

Runner::test('Notfallplan: Anhänge – Dateitypen, Grenzen und erlaubte Schritttypen', static function (): void {
    Assert::same('application/pdf', \App\Services\EmergencyPlanAttachments::detectMime("%PDF-1.7\n"));
    Assert::same('image/png', \App\Services\EmergencyPlanAttachments::detectMime(emergencyPng()));
    Assert::same('image/jpeg', \App\Services\EmergencyPlanAttachments::detectMime("\xFF\xD8\xFF\xE0xx"));
    Assert::same('image/webp', \App\Services\EmergencyPlanAttachments::detectMime('RIFF1234WEBPVP8 '));
    Assert::same(null, \App\Services\EmergencyPlanAttachments::detectMime('<svg onload="alert(1)">'));
    Assert::same('Anhang', \App\Services\EmergencyPlanAttachments::name("../\x01"));
    Assert::same('plan.pdf', \App\Services\EmergencyPlanAttachments::name('C:\\temp\\plan.pdf'));

    $attachment = ['id' => hash('sha256', 'x'), 'name' => 'Lageplan.pdf', 'mime' => 'application/pdf', 'size' => 10];
    foreach (['action', 'contact', 'decision', 'note'] as $type) {
        $node = EmergencyPlanDefinition::validate(['title' => 'x', 'nodes' => [emergencyNode('a', $type) + ['attachments' => [$attachment]]]])['nodes'][0];
        Assert::same([$attachment], $node['attachments']);
    }
    Assert::same([], EmergencyPlanDefinition::validate(['title' => 'x', 'nodes' => [emergencyNode('a')]])['nodes'][0]['attachments'], 'Alte Pläne ohne Feld.');
    Assert::contains('nur bei', emergencyRejected(['title' => 'x', 'nodes' => [emergencyNode('c', 'checklist') + ['attachments' => [$attachment]]]]));
    Assert::contains('nur bei', emergencyRejected(['title' => 'x', 'nodes' => [emergencyNode('s', 'sms') + ['attachments' => [$attachment]]]]));
    Assert::contains('Ungültiger Anhang', emergencyRejected(['title' => 'x', 'nodes' => [emergencyNode('a') + ['attachments' => [['id' => '../x'] + $attachment]]]]));
    Assert::contains('Ungültiger Anhang', emergencyRejected(['title' => 'x', 'nodes' => [emergencyNode('a') + ['attachments' => [['mime' => 'image/svg+xml'] + $attachment]]]]));
    Assert::contains('Ungültiger Anhang', emergencyRejected(['title' => 'x', 'nodes' => [emergencyNode('a') + ['attachments' => [$attachment, $attachment]]]]));
    $many = array_map(static fn (int $i) => ['id' => hash('sha256', (string) $i)] + $attachment, range(1, 11));
    Assert::contains('höchstens 10', emergencyRejected(['title' => 'x', 'nodes' => [emergencyNode('a') + ['attachments' => $many]]]));
});

Runner::test('Notfallplan: Anhänge base64 in der Datenbank, Snapshot und Speicherprüfung', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    $rejected = false;
    try { $service->uploadAttachment('<html>', 'x.html', 'local:admin'); } catch (ValidationException) { $rejected = true; }
    Assert::true($rejected, 'Nur PDF und Bilder.');
    $meta = $service->uploadAttachment(emergencyPng(), 'Lageplan.png', 'local:admin');
    Assert::same(hash('sha256', emergencyPng()), $meta['id']);
    Assert::same('image/png', $meta['mime']);
    Assert::same($meta, $service->uploadAttachment(emergencyPng(), 'Lageplan.png', 'local:admin'), 'Gleiche Datei wird nur einmal gespeichert.');
    Assert::same(1, (int) $pdo->query('SELECT COUNT(*) FROM emergency_plan_attachments')->fetchColumn());
    Assert::same(base64_encode(emergencyPng()), $service->repository->attachment($meta['id'])['data']);

    $definition = ['title' => 'Anhang', 'nodes' => [emergencyNode('a', 'note') + ['attachments' => [array_replace($meta, ['mime' => 'application/pdf', 'size' => 99])]]]];
    $id = $service->save(0, 0, $definition, 'local:admin');
    Assert::same([$meta], $service->repository->plan($id)['definition']['nodes'][0]['attachments'], 'Typ und Größe stammen aus der Datenbank.');
    $missing = $definition;
    $missing['nodes'][0]['attachments'][0]['id'] = hash('sha256', 'fehlt');
    $rejected = false;
    try { $service->save($id, 1, $missing, 'local:admin'); } catch (ValidationException $exception) { $rejected = str_contains(implode(' ', $exception->errors()), 'nicht (mehr) vorhanden'); }
    Assert::true($rejected);

    $service->repository->submit($id, 1, 'local:admin');
    $service->repository->review($id, 1, 'local:reviewer', true, 'Geprüft.');
    $event = $service->repository->event($service->start($id, 1, emergencyUser(), ' correct password ', bin2hex(random_bytes(32))));
    Assert::same([$meta], $event['snapshot']['nodes'][0]['attachments']);
});

Runner::test('Notfallplan: Export und Import übernehmen Anhänge', static function (): void {
    $sourcePdo = emergencyPdo();
    emergencyAlarmTables($sourcePdo, []);
    $source = emergencyService($sourcePdo);
    $png = $source->uploadAttachment(emergencyPng('a'), 'Bild.png', 'local:autor');
    $pdf = $source->uploadAttachment("%PDF-1.4\nInhalt", 'Plan.pdf', 'local:autor');
    $id = $source->save(0, 0, ['title' => 'Mit Anhängen', 'nodes' => [
        emergencyNode('a') + ['attachments' => [$png, $pdf]],
        emergencyNode('b', 'decision', [['id' => 'a', 'when' => 'always']]) + ['attachments' => [$pdf]],
    ]], 'local:autor');
    $files = emergencyExportFiles(emergencyTransfer($source), [$id]);
    $data = json_decode($files[0]['contents'], true, 64, JSON_THROW_ON_ERROR);
    Assert::same([$png['id'], $pdf['id']], array_keys($data['manifest']['attachments']));
    Assert::same(base64_encode("%PDF-1.4\nInhalt"), $data['chunks'][1]['data']);

    $targetPdo = emergencyPdo();
    emergencyAlarmTables($targetPdo, []);
    $target = emergencyService($targetPdo);
    $ids = emergencyImportFiles(emergencyTransfer($target), $files);
    $plan = $target->repository->plan($ids[0]);
    Assert::same([$png, $pdf], $plan['definition']['nodes'][0]['attachments']);
    Assert::same(base64_encode(emergencyPng('a')), $target->repository->attachment($png['id'])['data']);
    Assert::same(2, (int) $targetPdo->query('SELECT COUNT(*) FROM emergency_plan_attachments')->fetchColumn());

    // Version 2 (eine Datei mit Anhängen): manipulierte oder fehlende Anhänge – nichts wird importiert.
    $v2 = ['format' => EmergencyPlanService::EXPORT_FORMAT, 'version' => 2, 'exported_at' => '2024-01-01T00:00:00Z',
        'plans' => [['definition' => $plan['definition']]], 'attachments' => [
            $png['id'] => ['mime' => 'image/png', 'size' => strlen(emergencyPng('a')), 'data' => base64_encode(emergencyPng('a'))],
            $pdf['id'] => ['mime' => 'application/pdf', 'size' => strlen("%PDF-1.4\nInhalt"), 'data' => base64_encode("%PDF-1.4\nInhalt")],
        ]];
    $broken = $v2;
    $broken['attachments'][$pdf['id']]['data'] = base64_encode('%PDF-1.4 anders');
    $empty = emergencyPdo();
    emergencyAlarmTables($empty, []);
    $emptyService = emergencyService($empty);
    Assert::contains('beschädigt', emergencyImportRejected($emptyService, json_encode($broken)));
    $without = $v2;
    unset($without['attachments'][$pdf['id']]);
    Assert::contains('nicht (mehr) vorhanden', emergencyImportRejected($emptyService, json_encode($without)));
    Assert::same([], $emptyService->repository->plans());
    Assert::same(0, (int) $empty->query('SELECT COUNT(*) FROM emergency_plan_attachments')->fetchColumn());
    Assert::same([1], emergencyImportFiles(emergencyTransfer($emptyService), [['name' => 'alt.json', 'contents' => json_encode($v2)]]), 'Version 2 bleibt über den Export-Satz-Import importierbar.');

    // Version 1 (ohne Anhänge) bleibt importierbar.
    Assert::same([2], $emptyService->importPlans(json_encode(['format' => EmergencyPlanService::EXPORT_FORMAT, 'version' => 1, 'plans' => [['definition' => emergencyDefinition()]]]), 'local:admin'));
});

Runner::test('Notfallplan: Export-Satz teilt bei Erreichen der Größengrenze in anfolgende Dateien auf', static function (): void {
    $sourcePdo = emergencyPdo();
    emergencyAlarmTables($sourcePdo, []);
    $source = emergencyService($sourcePdo);
    $large = "%PDF-1.4\n" . random_bytes(30000);
    $pdf = $source->uploadAttachment($large, 'Gross.pdf', 'local:autor');
    $png = $source->uploadAttachment(emergencyPng('b'), 'Bild.png', 'local:autor');
    $first = $source->save(0, 0, ['title' => 'Großer Plan', 'nodes' => [emergencyNode('a') + ['attachments' => [$pdf, $png]]]], 'local:autor');
    $second = $source->save(0, 0, emergencyDefinition(), 'local:autor');
    Assert::same(EmergencyPlanTransfer::PART_MAX_BYTES, 15000000);

    $limit = 8000;
    $transfer = emergencyTransfer($source, $limit);
    $export = $transfer->createExport([$first, $second], 'local:autor');
    $count = count($export['parts']);
    Assert::true($count >= 6, 'Mehrere Teildateien erwartet, erhalten: ' . $count);
    Assert::same(['Großer Plan', 'Brandfall'], $export['plans']);
    Assert::true(str_starts_with($export['folder'], 'Notfallpläne/') && str_ends_with($export['folder'], '2 Notfallpläne'));
    $files = [];
    foreach ($export['parts'] as $i => $part) {
        $file = $transfer->exportPart($export['set'], $part['part'], 'local:autor');
        $contents = (string) file_get_contents($file['path']);
        Assert::true(strlen($contents) <= $limit, 'Teil ' . ($i + 1) . ' ist ' . strlen($contents) . ' Bytes groß.');
        Assert::same(strlen($contents), $part['bytes']);
        Assert::true(str_ends_with($file['name'], sprintf('_teil-%02d-von-%d.json', $i + 1, $count)) || str_ends_with($file['name'], sprintf('_teil-%d-von-%d.json', $i + 1, $count)));
        $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        Assert::same(['id' => $export['set'], 'part' => $i + 1, 'parts' => $count], $data['set']);
        Assert::same($i === 0, isset($data['manifest']));
        $files[] = ['name' => $file['name'], 'contents' => $contents];
    }
    emergencyThrows(fn () => $transfer->exportPart($export['set'], 1, 'local:fremd'), 404);
    emergencyThrows(fn () => $transfer->exportPart($export['set'], $count + 1, 'local:autor'), 404);
    emergencyThrows(fn () => $transfer->exportPart('../x', 1, 'local:autor'), 404);

    $targetPdo = emergencyPdo();
    emergencyAlarmTables($targetPdo, []);
    $target = emergencyService($targetPdo);
    $import = emergencyTransfer($target);

    // Unvollständiger Satz: Stand zeigt fehlende Teile, Import lehnt ab und legt nichts an.
    $reversed = array_reverse($files);
    $missing = array_pop($reversed); // Teil 1 fehlt
    $status = null;
    foreach ($reversed as $file) {
        $status = $import->stageImport($file['contents'], $file['name'], 'local:admin');
    }
    Assert::same([1], $status['missing']);
    Assert::false($status['complete']);
    Assert::same([], $status['plans'], 'Ohne Teil 1 ist das Inhaltsverzeichnis unbekannt.');
    Assert::contains('fehlt Teil 1 von ' . $count, emergencyTransferRejected(fn () => $import->importStaged($export['set'], 'local:admin')));
    Assert::same([], $target->repository->plans());

    // Fremde Personen sehen die hochgeladenen Teile nicht.
    Assert::contains('nicht (mehr) vor', emergencyTransferRejected(fn () => $import->importStaged($export['set'], 'local:fremd')));
    $foreign = $import->stageImport($missing['contents'], $missing['name'], 'local:fremd');
    Assert::same(range(2, $count), $foreign['missing']);

    // Doppelt gewählte Datei schadet nicht, abweichender Inhalt für denselben Teil wird abgelehnt.
    $import->stageImport($files[1]['contents'], $files[1]['name'], 'local:admin');
    $tampered = str_replace('"part":2', '"part":2 ', $files[1]['contents']);
    Assert::contains('anderem Inhalt', emergencyTransferRejected(fn () => $import->stageImport($tampered, 'x.json', 'local:admin')));

    $status = $import->stageImport($missing['contents'], $missing['name'], 'local:admin');
    Assert::true($status['complete']);
    Assert::same(['Großer Plan', 'Brandfall'], $status['plans']);
    Assert::same([1, 2], $import->importStaged($export['set'], 'local:admin'));
    Assert::same([$pdf, $png], $target->repository->plan(1)['definition']['nodes'][0]['attachments']);
    Assert::same(base64_encode($large), $target->repository->attachment($pdf['id'])['data']);
    Assert::same(emergencyDefinition()['nodes'][0]['title'], $target->repository->plan(2)['definition']['nodes'][0]['title']);
    Assert::contains('nicht (mehr) vor', emergencyTransferRejected(fn () => $import->importStaged($export['set'], 'local:admin')), 'Importierte Teile werden entfernt.');
});

Runner::test('Notfallplan: Import eines Export-Satzes prüft Inhaltsverzeichnis, Prüfsummen und Zugehörigkeit', static function (): void {
    $sourcePdo = emergencyPdo();
    emergencyAlarmTables($sourcePdo, []);
    $source = emergencyService($sourcePdo);
    $pdf = $source->uploadAttachment("%PDF-1.4\n" . random_bytes(12000), 'Plan.pdf', 'local:autor');
    $id = $source->save(0, 0, ['title' => 'Satz', 'nodes' => [emergencyNode('a') + ['attachments' => [$pdf]]]], 'local:autor');
    $files = emergencyExportFiles(emergencyTransfer($source, 6000), [$id]);
    Assert::true(count($files) >= 3);

    $targetPdo = emergencyPdo();
    emergencyAlarmTables($targetPdo, []);
    $target = emergencyService($targetPdo);
    $mutate = static function (array $files, int $index, callable $change): array {
        $data = json_decode($files[$index]['contents'], true, 64, JSON_THROW_ON_ERROR);
        $files[$index]['contents'] = json_encode($change($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $files;
    };
    $reject = static fn (array $files): string => emergencyTransferRejected(fn () => emergencyImportFiles(emergencyTransfer($target), $files));

    Assert::contains('beschädigt', $reject($mutate($files, 0, static function (array $data): array {
        $data['plans'][0]['definition']['title'] = 'Verändert';
        return $data;
    })));
    $last = count($files) - 1;
    Assert::contains('beschädigt', $reject($mutate($files, $last, static function (array $data): array {
        $chunk = &$data['chunks'][count($data['chunks']) - 1];
        $chunk['data'] = ($chunk['data'][0] === 'A' ? 'B' : 'A') . substr($chunk['data'], 1);
        return $data;
    })));
    Assert::contains('ungültige oder doppelte', $reject($mutate($files, $last, static function (array $data): array {
        $data['chunks'][] = $data['chunks'][0];
        return $data;
    })));
    Assert::contains('Inhaltsverzeichnis', $reject($mutate($files, 0, static function (array $data): array {
        unset($data['manifest']);
        return $data;
    })));
    Assert::contains('Kennzeichnung', $reject($mutate($files, 1, static function (array $data): array {
        $data['set']['part'] = 99;
        return $data;
    })));
    // Teil aus einem anderen Export-Satz desselben Plans.
    $other = emergencyExportFiles(emergencyTransfer($source, 6000), [$id]);
    $mixed = $files;
    $mixed[1] = $other[1];
    $staging = emergencyTransfer($target);
    $statuses = array_map(static fn (array $file): array => $staging->stageImport($file['contents'], $file['name'], 'local:admin'), $mixed);
    Assert::false($statuses[count($statuses) - 1]['complete']);
    Assert::true(in_array(2, $statuses[0]['missing'], true) || in_array(2, $statuses[count($statuses) - 1]['missing'], true));
    Assert::contains('keine Notfallplan-Exportdatei', emergencyTransferRejected(fn () => $staging->stageImport('{"format":"x"}', 'x.json', 'local:admin')));
    Assert::contains('Version', emergencyTransferRejected(fn () => $staging->stageImport(json_encode(['format' => EmergencyPlanService::EXPORT_FORMAT, 'version' => 99]), 'x.json', 'local:admin')));
    Assert::same([], $target->repository->plans(), 'Fehlerhafte Sätze legen nichts an.');
    Assert::same(0, (int) $targetPdo->query('SELECT COUNT(*) FROM emergency_plan_attachments')->fetchColumn());

    Assert::same([1], emergencyImportFiles(emergencyTransfer($target), array_reverse($files)), 'Reihenfolge der Auswahl ist egal.');
});

Runner::test('Notfallplan: Ablage in Nextcloud – signiertes Token und sichere Zielpfade', static function (): void {
    require_once BASE_PATH . '/docker/nextcloud/apps/intranet_integration/lib/Service/TokenVerifier.php';
    require_once BASE_PATH . '/docker/nextcloud/apps/intranet_integration/lib/Service/FileTarget.php';
    $secret = str_repeat('s', 32);
    $body = '{"format":"lanpa-notfallplaene"}';
    $token = \App\Services\Office\OfficeJwt::filesToken($secret, 'max@corp', 'Notfallpläne/2024-01-01 10-00 Brandfall', 'datei.json', $body, time());
    $verifier = new \OCA\IntranetIntegration\Service\TokenVerifier();
    $claims = $verifier->claims($token, $secret, \OCA\IntranetIntegration\Service\TokenVerifier::FILES_AUDIENCE);
    Assert::same('max@corp', $claims['sub']);
    Assert::same(hash('sha256', $body), $claims['body']);
    Assert::same('datei.json', $claims['name']);
    Assert::same(null, $verifier->claims($token, $secret, \OCA\IntranetIntegration\Service\TokenVerifier::AUDIENCE));
    Assert::same(null, $verifier->claims($token, str_repeat('x', 32), \OCA\IntranetIntegration\Service\TokenVerifier::FILES_AUDIENCE));

    $target = \OCA\IntranetIntegration\Service\FileTarget::class;
    Assert::same(['Notfallpläne', '2024-01-01 10-00 Brandfall'], $target::folder('Notfallpläne/2024-01-01 10-00 Brandfall'));
    foreach (['', '../x', 'a/../b', '/a', 'a//b', 'a/b/c/d/e', "a\nb", 'a\\b', '.hidden'] as $folder) {
        Assert::same(null, $target::folder($folder), 'Ordner abgelehnt: ' . $folder);
    }
    Assert::false($target::isSafeSegment('..'));
    Assert::false($target::isSafeSegment('a/b'));
    Assert::true($target::isSafeSegment('notfallplan_2024-01-01_teil-1-von-2.json'));

    Assert::same('Brandfall Werk', \App\Services\Office\NextcloudFilesService::segment('Brandfall/ Werk', 'x'));
    Assert::same('x', \App\Services\Office\NextcloudFilesService::segment('..', 'x'));

    // Übertragung: Inhalt als Anfragekörper, Token an Benutzer, Ziel und Inhalt gebunden.
    $probe = new FakeOfficeProbe();
    $url = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/files';
    $probe->responses[$url] = ['status' => 200, 'error' => null, 'body' => '{"ok":true,"message":"In Nextcloud gespeichert."}'];
    $files = new \App\Services\Office\NextcloudFilesService(officeConfig(), $probe);
    $result = $files->upload('max@corp', 'Notfallpläne/2024-01-01 10-00 Brandfall', 'teil-1-von-1.json', $body);
    Assert::true($result['ok']);
    Assert::same('/Notfallpläne/2024-01-01 10-00 Brandfall/teil-1-von-1.json', $result['path']);
    Assert::same($body, $probe->requests[0]['body']);
    $sent = $verifier->claims(substr($probe->requests[0]['headers']['Authorization'], 7), 'test-secret-0123456789', \OCA\IntranetIntegration\Service\TokenVerifier::FILES_AUDIENCE);
    Assert::same(['max@corp', 'Notfallpläne/2024-01-01 10-00 Brandfall', 'teil-1-von-1.json', hash('sha256', $body)], [$sent['sub'], $sent['folder'], $sent['name'], $sent['body']]);
    Assert::false($files->upload('max@corp', '../x', 'a.json', $body)['ok']);
    Assert::false($files->upload('böse uid', 'Notfallpläne', 'a.json', $body)['ok']);
    Assert::same(1, count($probe->requests), 'Ungültige Ziele werden nicht übertragen.');
    $probe->responses[$url] = ['status' => 404, 'error' => null, 'body' => '{"ok":false,"message":"Für Sie gibt es noch kein aktives Nextcloud-Konto."}'];
    Assert::contains('kein aktives Nextcloud-Konto', $files->upload('max@corp', 'Notfallpläne', 'a.json', $body)['message']);
    Assert::contains('nicht aktiviert', (new \App\Services\Office\NextcloudFilesService(officeConfig([], ['enabled' => false]), $probe))->unavailableReason() ?? '');
    Assert::same('/office/index.php/apps/files/?dir=%2FNotf%C3%A4lle', $files->folderTarget('Notfälle'));
});

