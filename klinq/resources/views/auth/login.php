<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <span class="brand-logo" aria-hidden="true"><?= icon('box') ?></span>
            <div>
                <h1 class="mb-0 text-lg"><?= e($appName ?? 'KLINQ') ?></h1>
                <p class="text-muted text-sm mb-0"><?= e($companyName ?? '') ?></p>
            </div>
        </div>
        <?php include __DIR__ . '/../partials/flash.php'; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="/login" autocomplete="on">
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= e($redirect ?? '') ?>">
            <div class="form-group">
                <label for="login-username">Benutzername</label>
                <input id="login-username" name="username" value="<?= e($username ?? '') ?>" autocomplete="username" autocapitalize="none" required autofocus>
            </div>
            <div class="form-group">
                <label for="login-password">Passwort</label>
                <input id="login-password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block">Anmelden</button>
        </form>
    </div>
</div>
<?php $content = ob_get_clean(); include __DIR__ . '/../partials/layout.php'; ?>
