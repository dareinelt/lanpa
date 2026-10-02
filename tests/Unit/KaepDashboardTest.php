<?php

declare(strict_types=1);

use App\Controllers\KaepDashboardController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\PhonebookRepository;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\SsoAuth;
use App\Services\EmergencyPlanService;
use App\Services\KaepDashboard;
use Tests\Support\Assert;
use Tests\Support\FakeAdminUserStore;
use Tests\Support\Runner;

Runner::test('KAEP-Dashboard: Rollen, echte Identitäten und lokale Tablet-Anmeldung', static function (): void {
    $user = emergencyUser();
    Assert::same('ad:demo@quelle', EmergencyPlanService::dashboardActor($user, 'kaep', null, null));
    Assert::same('ad:demo@quelle', EmergencyPlanService::dashboardActor($user, 'admin', 'admin', 'Backup'));
    Assert::null(EmergencyPlanService::dashboardActor($user, null, null, null));
    Assert::null(EmergencyPlanService::dashboardActor($user, 'redaktion', null, null));
    Assert::null(EmergencyPlanService::dashboardActor(array_replace($user, ['fake' => true]), 'kaep', null, null));
    Assert::same('local:tablet', EmergencyPlanService::dashboardActor(null, null, 'kaep', 'Tablet'));
    Assert::same('ad:demo@quelle', EmergencyPlanService::dashboardActor($user, null, 'kaep', 'Tablet'));
    Assert::null(EmergencyPlanService::dashboardActor(null, null, 'redaktion', 'Tablet'));
});

Runner::test('KAEP-Dashboard: Änderungen sind persistent, atomar und im gemeinsamen Journal', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    $repo = $service->repository;
    $id = emergencyStart($service);
    $event = $repo->event($id);
    $token = $repo->dashboardToken();
    $change = KaepDashboard::change($event, ['revision' => 1, 'action' => 'assignment', 'node' => 'entscheidung',
        'owner' => 'Leitung Pflege', 'priority' => 'critical', 'due' => '2026-10-05T08:30', 'comment' => 'Mit Nachtschicht abgestimmt.']);
    $repo->coordinate($event, $change, 'local:tablet');
    $current = $repo->event($id);
    Assert::same(2, (int) $current['revision']);
    Assert::same('Leitung Pflege', $current['coordination']['assignments']['entscheidung']['owner']);
    Assert::same('2026-10-05 08:30:00', $current['coordination']['assignments']['entscheidung']['due']);
    Assert::true($token !== $repo->dashboardToken());
    $log = $repo->journal($id)[0];
    Assert::same('local:tablet', $log['actor']);
    Assert::same('entscheidung', $log['node_id']);
    Assert::contains('Mit Nachtschicht abgestimmt.', $log['message']);
    emergencyThrows(fn () => $repo->coordinate($event, $change, 'local:tv'), 409);
    Assert::same(3, count($repo->logs($id)));
    $service->update($current, 'ad:demo@quelle', ['revision' => 2, 'action' => 'status', 'node' => 'entscheidung', 'status' => 'done', 'answer' => 'yes']);
    $dashboard = $repo->dashboard($id, 'active', 1);
    Assert::same('Leitung Pflege', $dashboard['event']['coordination']['assignments']['entscheidung']['owner']);
    Assert::same('ready', $dashboard['ready']['ja']);
    Assert::same('skipped', $dashboard['ready']['nein']);
    Assert::same($repo->dashboardToken(), $dashboard['token']);
    Assert::same('status', $dashboard['logs'][0]['action']);
});

Runner::test('KAEP-Dashboard: Leitung, Lage und Schichtübergabe behalten ihre Historie', static function (): void {
    $service = emergencyService(emergencyPdo());
    $id = emergencyStart($service);
    $repo = $service->repository;
    $inputs = [
        ['action' => 'leadership', 'role' => 'Einsatzleitung', 'person' => 'Person A', 'phone' => '123'],
        ['action' => 'leadership', 'role' => 'Einsatzleitung', 'person' => 'Person B', 'phone' => '456', 'comment' => 'Schichtwechsel'],
        ['action' => 'leadership', 'role' => 'Bereich Pflege', 'person' => 'Team C'],
        ['action' => 'situation', 'situation' => 'Evakuierung läuft', 'briefing' => '2026-10-06T10:00'],
        ['action' => 'handover', 'comment' => 'Person B übernimmt. Offene Aufgaben besprochen.'],
        ['action' => 'leadership', 'role' => 'Bereich Pflege', 'person' => ''],
    ];
    foreach ($inputs as $input) {
        $event = $repo->event($id);
        $repo->coordinate($event, KaepDashboard::change($event, $input + ['revision' => $event['revision']]), 'local:kaep');
    }
    $event = $repo->event($id);
    Assert::same('Person B', $event['coordination']['leadership']['Einsatzleitung']['person']);
    Assert::same(1, count($event['coordination']['leadership']));
    Assert::same('Evakuierung läuft', $event['coordination']['situation']);
    Assert::same('2026-10-06 10:00:00', $event['coordination']['briefing']);
    Assert::contains('Person A → Person B', $repo->logs($id)[3]['message']);
    Assert::contains('Schichtübergabe:', $repo->journal($id)[1]['message']);
});

Runner::test('KAEP-Dashboard: Validierung, abgeschlossene Einsätze und Rollback', static function (): void {
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    $id = emergencyStart($service);
    $repo = $service->repository;
    $event = $repo->event($id);
    emergencyThrows(fn () => KaepDashboard::change($event, ['revision' => 0, 'action' => 'journal', 'comment' => 'x']), 409);
    emergencyThrows(fn () => KaepDashboard::change($event, ['revision' => 1, 'action' => 'journal', 'node' => 'unknown', 'comment' => 'x']), 422);
    emergencyThrows(fn () => KaepDashboard::change($event, ['revision' => 1, 'action' => 'assignment', 'node' => 'entscheidung', 'priority' => 'unknown']), 422);
    foreach ([
        ['action' => 'journal', 'comment' => '  '],
        ['action' => 'handover', 'comment' => str_repeat('x', 2001)],
        ['action' => 'leadership', 'role' => [], 'person' => 'x'],
        ['action' => 'assignment', 'node' => 'entscheidung', 'priority' => 'normal', 'due' => '2026-02-30T12:00'],
        ['action' => 'situation', 'situation' => 'x', 'briefing' => 'tomorrow'],
    ] as $input) {
        $rejected = false;
        try { KaepDashboard::change($event, $input + ['revision' => 1]); } catch (ValidationException) { $rejected = true; }
        Assert::true($rejected);
    }
    $change = KaepDashboard::change($event, ['revision' => 1, 'action' => 'journal', 'comment' => 'Notiz']);
    $pdo->exec("CREATE TRIGGER dashboard_log_failure BEFORE INSERT ON emergency_log BEGIN SELECT RAISE(FAIL, 'journal down'); END");
    $failed = false;
    try { $repo->coordinate($event, $change, 'local:kaep'); } catch (PDOException) { $failed = true; }
    Assert::true($failed);
    Assert::same(1, (int) $repo->event($id)['revision']);
    $pdo->exec('DROP TRIGGER dashboard_log_failure');
    $service->update($event, 'local:kaep', ['revision' => 1, 'action' => 'close', 'comment' => 'Übung beendet, offene Maßnahmen dokumentiert.']);
    emergencyThrows(fn () => KaepDashboard::change($repo->event($id), ['revision' => 2, 'action' => 'journal', 'comment' => 'x']), 409);
    emergencyThrows(fn () => $repo->coordinate($event, $change, 'local:kaep'), 409);
    Assert::same(0, $repo->dashboard(0, 'active', 1)['events']['total']);
    Assert::same($id, (int) $repo->dashboard(0, 'closed', 1)['event']['id']);
});

Runner::test('KAEP-Dashboard: Journal über mehrere Tage ist lückenlos paginierbar und filterbar', static function (): void {
    $service = emergencyService(emergencyPdo());
    $repo = $service->repository;
    Assert::null($repo->dashboard(0, 'active', 1)['event']);
    $id = emergencyStart($service);
    for ($i = 0; $i < 220; $i++) {
        $event = $repo->event($id);
        $repo->coordinate($event, KaepDashboard::change($event, [
            'revision' => $event['revision'], 'action' => 'journal', 'comment' => 'Eintrag ' . $i,
            'node' => $i % 2 === 0 ? 'entscheidung' : '',
        ]), 'local:kaep');
    }
    $rows = $repo->journal($id);
    Assert::same(100, count($rows));
    $rows = [...$rows, ...$repo->journal($id, (int) end($rows)['id'])];
    $rows = [...$rows, ...$repo->journal($id, (int) end($rows)['id'])];
    Assert::same(222, count($rows));
    Assert::same(222, count(array_unique(array_column($rows, 'id'))));
    $nodeRows = $repo->journal($id, 0, 'entscheidung');
    Assert::same(100, count($nodeRows));
    Assert::same(10, count($repo->journal($id, (int) end($nodeRows)['id'], 'entscheidung')));
    Assert::same(['entscheidung'], array_values(array_unique(array_column($nodeRows, 'node_id'))));
});

Runner::test('KAEP-Dashboard: Wiedervorlagen und geplante Ablösungen für mehrtägige Einsätze', static function (): void {
    $service = emergencyService(emergencyPdo());
    $repo = $service->repository;
    $id = emergencyStart($service);
    $apply = static function (array $input) use ($repo, $id): array {
        $event = $repo->event($id);
        $repo->coordinate($event, KaepDashboard::change($event, $input + ['revision' => $event['revision']]), 'local:kaep');

        return $repo->event($id);
    };
    $event = $apply(['action' => 'leadership', 'role' => 'Einsatzleitung', 'person' => 'Person A', 'phone' => '123', 'until' => '2026-10-03T06:00']);
    Assert::same('2026-10-03 06:00:00', $event['coordination']['leadership']['Einsatzleitung']['until']);
    Assert::contains('Ablösung geplant (UTC):  → 2026-10-03 06:00:00', $repo->journal($id)[0]['message']);

    $event = $apply(['action' => 'reminder', 'mode' => 'add', 'title' => 'Rückruf Leitstelle', 'due' => '2026-10-02T14:30', 'node' => 'entscheidung', 'comment' => 'Zusage abwarten']);
    $reminder = $event['coordination']['reminders'][0];
    Assert::same('Rückruf Leitstelle', $reminder['title']);
    Assert::same('2026-10-02 14:30:00', $reminder['due']);
    Assert::same('entscheidung', $reminder['node']);
    Assert::false($reminder['done']);
    Assert::same(12, strlen($reminder['key']));
    Assert::same('entscheidung', $repo->journal($id)[0]['node_id']);
    Assert::contains('Zusage abwarten', $repo->journal($id)[0]['message']);

    $event = $apply(['action' => 'reminder', 'mode' => 'add', 'title' => 'Kontrollgang Nachtschicht', 'due' => '2026-10-03T02:00']);
    Assert::same(2, count($event['coordination']['reminders']));
    $event = $apply(['action' => 'reminder', 'mode' => 'done', 'key' => $reminder['key']]);
    Assert::true($event['coordination']['reminders'][0]['done']);
    Assert::same('entscheidung', $repo->journal($id)[0]['node_id']);
    emergencyThrows(fn () => KaepDashboard::change($event, ['revision' => $event['revision'], 'action' => 'reminder', 'mode' => 'done', 'key' => $reminder['key']]), 409);
    emergencyThrows(fn () => KaepDashboard::change($event, ['revision' => $event['revision'], 'action' => 'reminder', 'mode' => 'done', 'key' => 'unbekannt']), 422);
    emergencyThrows(fn () => KaepDashboard::change($event, ['revision' => $event['revision'], 'action' => 'reminder', 'mode' => 'kaputt', 'key' => $reminder['key']]), 422);
    $second = $event['coordination']['reminders'][1]['key'];
    $event = $apply(['action' => 'reminder', 'mode' => 'remove', 'key' => $second]);
    Assert::same(1, count($event['coordination']['reminders']));
    Assert::contains('Wiedervorlage entfernt: Kontrollgang Nachtschicht', $repo->journal($id)[0]['message']);

    foreach ([
        ['action' => 'reminder', 'mode' => 'add', 'title' => '', 'due' => '2026-10-02T14:30'],
        ['action' => 'reminder', 'mode' => 'add', 'title' => 'Ohne Zeit', 'due' => ''],
        ['action' => 'reminder', 'mode' => 'add', 'title' => 'Falsche Zeit', 'due' => '14:30'],
        ['action' => 'leadership', 'role' => 'Pflege', 'person' => 'X', 'until' => 'morgen'],
    ] as $input) {
        $rejected = false;
        try { KaepDashboard::change($event, $input + ['revision' => $event['revision']]); } catch (ValidationException) { $rejected = true; }
        Assert::true($rejected);
    }
    for ($i = 1; $i <= KaepDashboard::REMINDER_LIMIT; $i++) {
        $event['coordination'] = KaepDashboard::change($event, ['revision' => $event['revision'], 'action' => 'reminder', 'mode' => 'add', 'title' => 'W' . $i, 'due' => '2026-10-04T10:00'])['coordination'];
    }
    $rejected = false;
    try { KaepDashboard::change($event, ['revision' => $event['revision'], 'action' => 'reminder', 'mode' => 'add', 'title' => 'Zu viel', 'due' => '2026-10-04T10:00']); } catch (ValidationException) { $rejected = true; }
    Assert::true($rejected);
});

Runner::test('KAEP-Dashboard: Unbekannte Ereignis-ID fällt auf das neueste Ereignis zurück', static function (): void {
    $service = emergencyService(emergencyPdo());
    $repo = $service->repository;
    $first = emergencyStart($service);
    $second = emergencyStart($service);
    Assert::same($second, (int) $repo->dashboard(999999, 'active', 1)['event']['id']);
    Assert::same($first, (int) $repo->dashboard($first, 'active', 1)['event']['id']);
    Assert::same([], $repo->dashboard(999999, 'closed', 1)['logs']);
});

Runner::test('KAEP-Dashboard: 30 Leitungsbereiche sind möglich, bestehende bleiben änderbar', static function (): void {
    $service = emergencyService(emergencyPdo());
    $id = emergencyStart($service);
    $event = $service->repository->event($id);
    for ($i = 1; $i <= 30; $i++) {
        $event['coordination'] = KaepDashboard::change($event, [
            'revision' => 1, 'action' => 'leadership', 'role' => 'Bereich ' . $i, 'person' => 'Person ' . $i,
        ])['coordination'];
    }
    Assert::same(30, count($event['coordination']['leadership']));
    $rejected = false;
    try {
        KaepDashboard::change($event, ['revision' => 1, 'action' => 'leadership', 'role' => 'Bereich 31', 'person' => 'Weitere Person']);
    } catch (ValidationException) {
        $rejected = true;
    }
    Assert::true($rejected);
    $change = KaepDashboard::change($event, ['revision' => 1, 'action' => 'leadership', 'role' => 'Bereich 1', 'person' => 'Ablösung']);
    Assert::same('Ablösung', $change['coordination']['leadership']['Bereich 1']['person']);
    Assert::same(30, count($change['coordination']['leadership']));
});

Runner::test('KAEP-Dashboard: Jeder Endpunkt prüft Rechte, Schreiben zusätzlich CSRF', static function (): void {
    $session = $_SESSION ?? [];
    $instances = new ReflectionProperty(Container::class, 'instances');
    $original = $instances->getValue();
    try {
        $pdo = emergencyPdo();
        $service = emergencyService($pdo);
        $id = emergencyStart($service);
        $sso = new SsoAuth(new PhonebookRepository($pdo), ['enabled' => false]);
        foreach ([null, 'redaktion', 'kaep', 'admin'] as $role) {
            $_SESSION = ['_admin_user_id' => 1, '_admin_username' => 'tester', '_admin_last_activity' => time()];
            $auth = new Auth(new FakeAdminUserStore($role === null ? null : ['id' => 1, 'username' => 'tester', 'role' => $role]));
            $instances->setValue(null, [Auth::class => $auth, SsoAuth::class => $sso, EmergencyPlanService::class => $service]);
            $controller = new KaepDashboardController();
            if ($role === null || $role === 'redaktion') {
                foreach (['index', 'data', 'journal', 'stream', 'update'] as $method) {
                    emergencyThrows(fn () => $controller->$method(new Request('GET', '/kaep-dashboard')), 403);
                }
            } else {
                emergencyThrows(fn () => $controller->update(new Request('POST', '/kaep-dashboard/aktion')), 419);
                Assert::same(200, $controller->data(new Request('GET', '/kaep-dashboard/daten'))->status());
                Assert::same('text/event-stream', $controller->stream(new Request('GET', '/kaep-dashboard/live'))->headers()['Content-Type']);
                $response = $controller->update(new Request('POST', '/kaep-dashboard/aktion', [], [
                    '_token' => Csrf::token(), 'id' => $id, 'revision' => $service->repository->event($id)['revision'],
                    'action' => 'journal', 'comment' => 'Vom Controller',
                ]));
                Assert::same(200, $response->status());
                Assert::same('no-store', $response->headers()['Cache-Control']);
            }
        }
    } finally {
        $_SESSION = $session;
        $instances->setValue(null, $original);
    }
});

Runner::test('KAEP-Dashboard: Footer ist rollenabhängig und Dashboard enthält keine externen Ressourcen', static function (): void {
    $base = ['content' => '', 'appName' => 'Test', 'themeCss' => '', 'assetVersion' => '1', 'kaepDashboardVisible' => false];
    Assert::false(str_contains(View::render('layouts.base', $base), 'href="/kaep-dashboard"'));
    $html = View::render('layouts.base', array_replace($base, ['kaepDashboardVisible' => true]));
    Assert::contains('href="/kaep-dashboard" target="_blank" rel="noopener"', $html);
    $dashboard = View::render('emergency.dashboard', ['assetVersion' => '1', 'actor' => 'local:<script>']);
    Assert::contains('data-actor="local:&lt;script&gt;"', $dashboard);
    Assert::contains('<dialog', $dashboard);
    Assert::same(9, substr_count($dashboard, 'data-kd-panel='));
    Assert::contains('data-kd-panel="metrics"', $dashboard);
    Assert::contains('data-kd-panel="schedule"', $dashboard);
    Assert::contains('id="kd-journal-type"', $dashboard);
    Assert::contains('/assets/js/kaep-dashboard-layout.js', $dashboard);
    Assert::false(str_contains($dashboard, 'https://'));
    $response = Response::eventStream(static function (): void {});
    Assert::same('no-store, no-transform', $response->headers()['Cache-Control']);
    Assert::same('no', $response->headers()['X-Accel-Buffering']);
});
