<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/benutzer/neu' : '/admin/benutzer/bearbeiten';
$role = (string) ($item['role'] ?? 'admin');
?>
<form method="post" action="<?= Html::e($action) ?>" class="form form--wide">
    <?= Csrf::field() ?>
    <?php if (!$isNew) { ?>
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php } ?>

    <div class="field">
        <label for="username">Benutzername <span aria-hidden="true">*</span></label>
        <input type="text" id="username" name="username" required maxlength="64"
               value="<?= Html::e((string) $item['username']) ?>"
               <?= isset($errors['username']) ? 'aria-invalid="true" aria-describedby="username-error"' : '' ?>>
        <?php if (isset($errors['username'])) { ?>
            <p class="field__error" id="username-error"><?= Html::e($errors['username']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="role">Rolle <span aria-hidden="true">*</span></label>
        <select id="role" name="role"
                <?= isset($errors['role']) ? 'aria-invalid="true" aria-describedby="role-error"' : 'aria-describedby="role-hint"' ?>>
            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Administrator (voller Zugriff)</option>
            <option value="redaktion" <?= $role === 'redaktion' ? 'selected' : '' ?>>Redaktion (nur wichtige Links)</option>
        </select>
        <p class="field__hint" id="role-hint">Die Gruppe „Redaktion“ darf ausschließlich die wichtigen Links bearbeiten und sieht keinen anderen Bereich der Administration.</p>
        <?php if (isset($errors['role'])) { ?>
            <p class="field__error" id="role-error"><?= Html::e($errors['role']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="password"><?= $isNew ? 'Passwort' : 'Neues Passwort (optional)' ?> <?= $isNew ? '<span aria-hidden="true">*</span>' : '' ?></label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="12"
               <?= $isNew ? 'required' : '' ?>
               <?= isset($errors['password']) ? 'aria-invalid="true" aria-describedby="password-error"' : 'aria-describedby="password-hint"' ?>>
        <p class="field__hint" id="password-hint">Mindestens 12 Zeichen. <?= $isNew ? '' : 'Leer lassen, um das bestehende Passwort beizubehalten.' ?></p>
        <?php if (isset($errors['password'])) { ?>
            <p class="field__error" id="password-error"><?= Html::e($errors['password']) ?></p>
        <?php } ?>
    </div>

    <div class="field field--check">
        <input type="checkbox" id="active" name="active" value="1" <?= (int) ($item['active'] ?? 1) === 1 ? 'checked' : '' ?>>
        <label for="active">Konto ist aktiv</label>
    </div>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
        <a class="button button--ghost" href="/admin/benutzer">Abbrechen</a>
    </div>
</form>
