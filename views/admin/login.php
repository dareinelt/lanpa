<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var string|null $error */
/** @var string $username */
?>
<section class="auth-card">
    <h1 class="auth-card__title">Administration</h1>
    <p class="auth-card__text">Bitte melden Sie sich mit Ihrem Administrationskonto an.</p>

    <?php if (($error ?? null) !== null) { ?>
        <p class="flash flash--error" role="alert"><?= Html::e($error) ?></p>
    <?php } ?>

    <form method="post" action="/admin/login" class="form" autocomplete="off">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username" value="<?= Html::e($username) ?>"
                   required autocomplete="username" autocapitalize="none" spellcheck="false">
        </div>

        <div class="field">
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit" class="button button--primary button--block">Anmelden</button>
    </form>

    <p class="auth-card__footer"><a href="/">Zurück zur Landingpage</a></p>
</section>
