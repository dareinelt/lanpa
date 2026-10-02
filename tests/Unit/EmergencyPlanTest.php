<?php

declare(strict_types=1);

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Repositories\EmergencyPlanRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\SettingsRepository;
use App\Services\EmergencyPlanDefinition;
use App\Services\EmergencyPlanService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function emergencyPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE emergency_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, published INTEGER DEFAULT 0, revision INTEGER DEFAULT 1, definition TEXT, updated_by TEXT, updated_at TEXT, review_state TEXT DEFAULT 'draft', contributors TEXT, submitted_by TEXT, submitted_at TEXT, published_definition TEXT, published_revision INTEGER)");
    $pdo->exec("CREATE TABLE emergency_plan_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER, revision INTEGER, actor TEXT, action TEXT, comment TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE emergency_events (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER, title TEXT, actor TEXT, request_key TEXT UNIQUE, snapshot TEXT, state TEXT, revision INTEGER DEFAULT 1, status TEXT DEFAULT 'active', started_at TEXT, closed_at TEXT)");
    $pdo->exec("CREATE TABLE emergency_log (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER, node_id TEXT, actor TEXT, action TEXT, message TEXT, created_at TEXT)");
    $pdo->exec("CREATE TABLE emergency_sms (event_id INTEGER, node_id TEXT, status TEXT, message TEXT, PRIMARY KEY (event_id, node_id))");
    $pdo->exec("CREATE TABLE emergency_password_attempts (actor TEXT PRIMARY KEY, window_start INTEGER, attempts INTEGER)");
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
    ];
    foreach ($cases as $input) {
        $rejected = false;
        try { EmergencyPlanDefinition::validate($input); } catch (ValidationException) { $rejected = true; }
        Assert::true($rejected);
    }
    Assert::same('https://example.test/info', EmergencyPlanDefinition::validate(['title' => 'x', 'nodes' => [emergencyNode('x') + ['link' => 'https://example.test/info']]])['nodes'][0]['link']);
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
