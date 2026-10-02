<?php

declare(strict_types=1);

use App\Controllers\EmergencyPlanController;
use App\Core\Container;
use App\Core\Request;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\PhonebookRepository;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\SsoAuth;
use App\Services\EmergencyPlanDefinition;
use App\Services\EmergencyPlanPreview;
use Tests\Support\Assert;
use Tests\Support\FakeAdminUserStore;
use Tests\Support\Runner;

function emergencyPreviewInput(?array $definition = null, array $operations = []): array
{
    return ['definition' => $definition ?? emergencyDefinition(), 'operations' => $operations, 'started' => true, 'startedAt' => 1790964000];
}

function emergencyPreviewAction(string $node, string $action, array $values = []): array
{
    return $values + ['node' => $node, 'action' => $action, 'at' => 1790964100];
}

function emergencyPreviewInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (ValidationException) {
        Assert::true(true);
        return;
    }
    throw new RuntimeException('Erwarteter Validierungsfehler fehlt.');
}

Runner::test('Notfallplan-Vorschau: unvollständige Entwürfe ohne gelockerte Speicherprüfung', static function (): void {
    $draft = ['title' => '', 'nodes' => []];
    Assert::same([], EmergencyPlanPreview::build(emergencyPreviewInput($draft), [])['event']['snapshot']['nodes']);
    emergencyPreviewInvalid(fn () => EmergencyPlanDefinition::validate($draft));
    foreach (['action', 'checklist', 'sms'] as $type) {
        $draft['nodes'] = [array_replace(emergencyNode('neu', $type), ['title' => '', 'checks' => [], 'alarm_id' => 0])];
        $preview = EmergencyPlanPreview::build(emergencyPreviewInput($draft), []);
        Assert::same('', $preview['event']['snapshot']['nodes'][0]['title']);
        emergencyPreviewInvalid(fn () => EmergencyPlanDefinition::validate($draft));
    }
    $draft['nodes'][0]['link'] = 'javascript:alert(1)';
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build(emergencyPreviewInput($draft), []));
    $draft['nodes'] = array_fill(0, 81, emergencyNode('zu-viele'));
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build(emergencyPreviewInput($draft), []));
});

Runner::test('Notfallplan-Vorschau: Entscheidungen, ODER, Kommentare und Abschluss wie im Einsatz', static function (): void {
    $operations = [
        emergencyPreviewAction('entscheidung', 'status', ['status' => 'done', 'answer' => 'yes']),
        emergencyPreviewAction('ja', 'status', ['status' => 'done']),
        emergencyPreviewAction('ende', 'comment', ['comment' => 'Rückmeldung']),
        emergencyPreviewAction('ende', 'status', ['status' => 'done']),
        emergencyPreviewAction('', 'close'),
    ];
    $pdo = emergencyPdo();
    $service = emergencyService($pdo);
    $id = emergencyStart($service);
    foreach ($operations as $operation) {
        $event = $service->repository->event($id);
        $service->update($event, 'Vorschau', $operation + ['revision' => $event['revision']]);
    }
    $preview = EmergencyPlanPreview::build(emergencyPreviewInput(null, $operations), []);
    $actual = $service->repository->event($id);
    Assert::same($actual['state'], $preview['event']['state']);
    Assert::same($actual['status'], $preview['event']['status']);
    Assert::same('skipped', $preview['ready']['nein']);
    Assert::same('Rückmeldung', $preview['logs'][2]['message']);
    Assert::same('2026-10-02 18:01:40', $preview['event']['closed_at']);
    $operations[] = emergencyPreviewAction('ende', 'comment', ['comment' => 'Zu spät']);
    emergencyThrows(fn () => EmergencyPlanPreview::build(emergencyPreviewInput(null, $operations), []), 409);
});

Runner::test('Notfallplan-Vorschau: Pflichtantwort, Voraussetzungen und Abschlussbegründung', static function (): void {
    emergencyThrows(fn () => EmergencyPlanPreview::build(emergencyPreviewInput(null, [
        emergencyPreviewAction('ja', 'status', ['status' => 'done']),
    ]), []), 409);
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build(emergencyPreviewInput(null, [
        emergencyPreviewAction('entscheidung', 'status', ['status' => 'done']),
    ]), []));
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build(emergencyPreviewInput(null, [
        emergencyPreviewAction('', 'close'),
    ]), []));
    $closed = EmergencyPlanPreview::build(emergencyPreviewInput(null, [
        emergencyPreviewAction('', 'close', ['comment' => 'Übung beendet']),
    ]), []);
    Assert::same('closed', $closed['event']['status']);
});

Runner::test('Notfallplan-Vorschau: Checklistenprüfung und stabile Bestätigungszeiten', static function (): void {
    $definition = ['title' => 'Prüfung', 'nodes' => [emergencyNode('liste', 'checklist')]];
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build(emergencyPreviewInput($definition, [
        emergencyPreviewAction('liste', 'status', ['status' => 'done', 'checks' => ['0']]),
    ]), []));
    $operations = [
        emergencyPreviewAction('liste', 'status', ['status' => 'in_progress', 'checks' => ['0']]),
        emergencyPreviewAction('liste', 'status', ['status' => 'done', 'checks' => ['0', '1'], 'at' => 1790964200]),
    ];
    $preview = EmergencyPlanPreview::build(emergencyPreviewInput($definition, $operations), []);
    Assert::same([0, 1], $preview['event']['state']['liste']['checks']);
    Assert::same('2026-10-02 18:01:40', $preview['event']['state']['liste']['check_details'][0]['at']);
    Assert::same('2026-10-02 18:03:20', $preview['event']['state']['liste']['check_details'][1]['at']);
    $definition['nodes'][0]['text'] = 'Neue Anweisung';
    Assert::same($preview['event']['state'], EmergencyPlanPreview::build(emergencyPreviewInput($definition, $operations), [])['event']['state']);
    Assert::same(4, count($preview['logs']));
});

Runner::test('Notfallplan-Vorschau: SMS ausschließlich simuliert, kein Ereignis und kein Versand', static function (): void {
    $pdo = emergencyPdo();
    $definition = ['title' => 'SMS', 'nodes' => [emergencyNode('sms', 'sms')]];
    $operations = [emergencyPreviewAction('sms', 'sms'), emergencyPreviewAction('sms', 'status', ['status' => 'done'])];
    $preview = EmergencyPlanPreview::build(emergencyPreviewInput($definition, $operations), [
        ['id' => 1, 'title' => 'Demo', 'text' => 'Nur Simulation', 'target' => '100', 'mode' => 'group'],
    ]);
    Assert::same('Nur Simulation', $preview['event']['snapshot']['nodes'][0]['alarm']['alarm_text']);
    Assert::same('SIMULATION: SMS nicht versendet.', $preview['event']['sms']['sms']['message']);
    Assert::same('done', $preview['event']['state']['sms']['status']);
    Assert::same([], $preview['notifications']);
    foreach (['emergency_plans', 'emergency_events', 'emergency_log', 'emergency_sms', 'mail_outbox', 'emergency_password_attempts'] as $table) {
        Assert::same(0, (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn());
    }
    emergencyThrows(fn () => EmergencyPlanPreview::build(emergencyPreviewInput($definition, [
        emergencyPreviewAction('sms', 'sms'), emergencyPreviewAction('sms', 'sms'),
    ]), []), 409);
});

Runner::test('Notfallplan-Vorschau: Rendern ohne echte Endpunkte, Kennwort oder Live-Polling', static function (): void {
    $definition = ['title' => '<script>title</script>', 'nodes' => [emergencyNode('sms', 'sms'), emergencyNode('liste', 'checklist')]];
    $data = EmergencyPlanPreview::build(emergencyPreviewInput($definition), []) + [
        'preview' => true, 'manager' => false, 'base' => '/admin/notfallplan/vorschau', 'assetVersion' => '1',
    ];
    foreach (['emergency.plan', 'emergency.event'] as $template) {
        $html = View::render($template, $data);
        Assert::contains('&lt;script&gt;title&lt;/script&gt;', $html);
        Assert::false(str_contains($html, 'type="password"'));
        Assert::false(str_contains($html, '/massnahme'));
        Assert::false(str_contains($html, '/start'));
        Assert::false(str_contains($html, 'data-ep-event'));
        Assert::false(str_contains($html, 'data-status-url'));
    }
    $html = View::render('emergency.event', $data);
    Assert::contains('action="/admin/notfallplan/vorschau"', $html);
    Assert::contains('SMS-Versand simulieren', $html);
    Assert::contains('Keine E-Mail eingeplant oder versendet.', $html);
});

Runner::test('Notfallplan-Vorschau: begrenzte und validierte Simulationshistorie', static function (): void {
    $input = emergencyPreviewInput();
    $input['operations'] = array_fill(0, 201, emergencyPreviewAction('entscheidung', 'comment', ['comment' => 'Test']));
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build($input, []));
    $input['operations'] = [['node' => [], 'action' => 'comment']];
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build($input, []));
    $input['operations'] = [];
    $input['startedAt'] = 'not-a-date';
    emergencyPreviewInvalid(fn () => EmergencyPlanPreview::build($input, []));
});

Runner::test('Notfallplan-Vorschau: Controller prüft Verwaltungsrolle und CSRF', static function (): void {
    $session = $_SESSION ?? [];
    $instances = new ReflectionProperty(Container::class, 'instances');
    $original = $instances->getValue();
    try {
        $sso = new SsoAuth(new PhonebookRepository(emergencyPdo()), ['enabled' => false]);
        foreach ([null, Auth::ROLE_REDAKTION, Auth::ROLE_KAEP, Auth::ROLE_ADMIN] as $role) {
            $_SESSION = ['_admin_user_id' => 1, '_admin_username' => 'tester', '_admin_last_activity' => time()];
            $auth = new Auth(new FakeAdminUserStore($role === null ? null : ['id' => 1, 'username' => 'tester', 'role' => $role]));
            $instances->setValue(null, [Auth::class => $auth, SsoAuth::class => $sso]);
            $controller = new EmergencyPlanController();
            $request = new Request('POST', '/admin/notfallplan/vorschau', [], []);
            if ($role === null || $role === Auth::ROLE_REDAKTION) {
                emergencyThrows(fn () => $controller->previewRender($request), 403);
                emergencyThrows(fn () => $controller->preview($request), 403);
            } else {
                emergencyThrows(fn () => $controller->previewRender($request), 419);
                $response = $controller->previewRender(new Request('POST', '/admin/notfallplan/vorschau', [],
                    ['_token' => Csrf::token(), 'preview' => '{broken']));
                Assert::same(422, $response->status());
                Assert::same('no-store', $response->headers()['Cache-Control']);
            }
        }
    } finally {
        $_SESSION = $session;
        $instances->setValue(null, $original);
    }
});
