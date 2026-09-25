<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array{id:int,name:string,description:string,apps:list<string>,groups:list<string>} $package */
/** @var list<array<string,mixed>> $apps */
/** @var array<string,string> $errors */

$selected = array_fill_keys($package['apps'], true);
$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field__error" id="package-' . Html::e($key) . '-error">' . Html::e($errors[$key]) . '</p>'
    : '';
$invalid = static fn (string $key): string => isset($errors[$key])
    ? 'aria-invalid="true" aria-describedby="package-' . Html::e($key) . '-error"'
    : '';
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/apps#pakete">Zurück zu den Office-Apps</a>
</div>

<form method="post" action="/admin/office/apps/paket" class="form form--wide" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $package['id'] ?>">

    <div class="field">
        <label for="package_name">Name</label>
        <input type="text" id="package_name" name="name" required maxlength="120" value="<?= Html::e($package['name']) ?>" <?= $invalid('name') ?>>
        <?= $error('name') ?>
    </div>
    <div class="field">
        <label for="package_description">Beschreibung</label>
        <input type="text" id="package_description" name="description" maxlength="255" value="<?= Html::e($package['description']) ?>">
    </div>

    <fieldset class="fieldset" <?= isset($errors['apps']) ? 'aria-describedby="package-apps-error"' : '' ?>>
        <legend>Enthaltene Apps</legend>
        <?php foreach ($apps as $app) {
            $key = (string) $app['key'];
            $checkId = 'package-app-' . $key;
            ?>
            <div class="field field--check">
                <input type="checkbox" id="<?= Html::e($checkId) ?>" name="apps[]" value="<?= Html::e($key) ?>"
                       aria-describedby="<?= Html::e($checkId) ?>-hint" <?= isset($selected[$key]) ? 'checked' : '' ?>>
                <label for="<?= Html::e($checkId) ?>"><?= Html::e((string) $app['title']) ?></label>
                <p class="field__hint" id="<?= Html::e($checkId) ?>-hint"><?= Html::e((string) $app['short_description']) ?></p>
            </div>
        <?php } ?>
        <?= $error('apps') ?>
    </fieldset>

    <fieldset class="fieldset">
        <legend>AD-Gruppen</legend>
        <div class="field group-suggest">
            <label for="package_groups">Gruppennamen (kommagetrennt)</label>
            <input type="text" id="package_groups" name="groups" maxlength="2000"
                   value="<?= Html::e(implode(', ', $package['groups'])) ?>"
                   autocomplete="off" spellcheck="false" aria-describedby="package_groups-hint"
                   data-group-suggest="/admin/ad/gruppen">
            <p class="field__hint" id="package_groups-hint">
                Mitglieder dieser Gruppen (auch verschachtelt) erhalten alle Apps des Pakets.
                Ohne Gruppe gibt das Paket keine App frei.
            </p>
        </div>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Paket speichern</button>
        <a class="button button--ghost" href="/admin/office/apps#pakete">Abbrechen</a>
    </div>
</form>
