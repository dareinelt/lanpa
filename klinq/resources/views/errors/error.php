<?php
require_once __DIR__ . '/../partials/helpers.php';
$titles = [403 => 'Zugriff verweigert', 404 => 'Nicht gefunden', 409 => 'Konflikt', 419 => 'Sitzung abgelaufen', 422 => 'Eingaben ungültig', 500 => 'Interner Fehler'];
$status = (int) ($status ?? 500);
ob_start();
?>
<div class="login-page">
    <div class="login-card text-center">
        <p class="error-code"><?= $status ?></p>
        <h1 class="text-lg"><?= e($titles[$status] ?? 'Fehler') ?></h1>
        <p class="text-muted"><?= e($message ?? '') ?></p>
        <div class="cluster justify-center mt-4">
            <a class="btn btn-secondary" href="javascript:history.back()">Zurück</a>
            <a class="btn btn-primary" href="/dashboard">Zum Dashboard</a>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../partials/layout.php'; ?>
