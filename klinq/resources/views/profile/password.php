<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isLdap = $account['auth_source'] === 'ldap'; ?>
<div class="page-header">
    <div><h1>Passwort ändern</h1><p class="page-subtitle text-muted mb-0">Konto <span class="mono"><?= e($account['username']) ?></span></p></div>
</div>
<div class="card card-form">
    <?php if ($isLdap): ?>
        <p class="mb-0">Sie melden sich über das Active Directory an. Ihr Passwort wird dort verwaltet und kann hier nicht geändert werden.</p>
    <?php else: ?>
    <form method="post" action="/profile/password" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="f-current">Aktuelles Passwort *</label>
            <input id="f-current" name="current_password" type="password" required autocomplete="current-password" autofocus>
            <?= field_error('current_password') ?>
        </div>
        <div class="form-group">
            <label for="f-password">Neues Passwort *</label>
            <input id="f-password" name="password" type="password" required minlength="<?= (int) $passwordMin ?>" autocomplete="new-password">
            <?= field_error('password') ?>
        </div>
        <div class="form-group">
            <label for="f-password2">Neues Passwort wiederholen *</label>
            <input id="f-password2" name="password_confirmation" type="password" required autocomplete="new-password">
            <?= field_error('password_confirmation') ?>
        </div>
        <p class="form-hint">Mindestens <?= (int) $passwordMin ?> Zeichen mit Buchstaben und mindestens einer Ziffer oder einem Sonderzeichen.</p>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Passwort ändern</button>
            <a class="btn btn-ghost" href="/dashboard">Abbrechen</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
