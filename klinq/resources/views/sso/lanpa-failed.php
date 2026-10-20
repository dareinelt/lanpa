<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
/** Hinweis, wenn der Einstieg aus dem Intranet nicht möglich ist. */
?>
<div class="login-page">
    <div class="login-card text-center">
        <h1 class="text-lg"><?= e($title ?? 'Anmeldung nicht möglich') ?></h1>
        <p class="text-muted"><?= e($message ?? 'Bitte starten Sie KLINQ erneut über das Intranet.') ?></p>
        <div class="cluster justify-center mt-4">
            <a class="btn btn-primary" href="/login">Zur Anmeldung</a>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../partials/layout.php'; ?>
