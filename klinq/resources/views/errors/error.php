<?php
require_once __DIR__ . '/../partials/helpers.php';
$titles = [403 => 'Zugriff verweigert', 404 => 'Nicht gefunden', 409 => 'Konflikt', 419 => 'Sitzung abgelaufen', 422 => 'Eingaben ungültig', 500 => 'Interner Fehler'];
$status = (int) ($status ?? 500);
// Rückweg nur in einen Bereich, den der Benutzer auch betreten darf: Assetverwaltung,
// Help Desk oder Serviceportal (eigene Tickets).
$can = $can ?? static fn (string $permission): bool => false;
$authenticated = (int) ($user['id'] ?? 0) > 0;
$home = $authenticated
    ? \App\Support\ModuleNavigation::entryUrl('', $can, (bool) ($helpdeskEnabled ?? false))
    : '/login';
$homeLabel = !$authenticated ? 'Zur Anmeldung' : match (\App\Support\ModuleNavigation::moduleForPath((string) $home)) {
    \App\Support\ModuleNavigation::HELPDESK => $can('helpdesk.view') ? 'Zum Help Desk' : 'Zum Help-Desk-Portal',
    \App\Support\ModuleNavigation::SYSTEM => 'Zur Administration',
    default => 'Zum Dashboard',
};
ob_start();
?>
<div class="login-page">
    <div class="login-card text-center">
        <p class="error-code"><?= $status ?></p>
        <h1 class="text-lg"><?= e($titles[$status] ?? 'Fehler') ?></h1>
        <p class="text-muted"><?= e($message ?? '') ?></p>
        <div class="cluster justify-center mt-4">
            <a class="btn btn-secondary" href="javascript:history.back()">Zurück</a>
            <?php if ($home !== null): ?>
                <a class="btn btn-primary" href="<?= e($home) ?>"><?= e($homeLabel) ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../partials/layout.php'; ?>
