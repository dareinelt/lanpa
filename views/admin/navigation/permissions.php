<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var list<array<string,mixed>> $users bereits zugeordnete Einzelbenutzer */
/** @var list<string> $assignedGroupNames */
$groupValue = Html::e(implode(', ', $assignedGroupNames));
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/navigation">Zurück zur Navigation</a>
</div>

<h2>Berechtigungen für „<?= Html::e((string) $item['title']) ?>“</h2>
<p class="field__hint">
    Ohne Eintrag ist das Element für alle sichtbar. Sobald mindestens eine
    AD-Gruppe zugeordnet ist, sehen nur Mitglieder dieser Gruppen das Element.
</p>

<form method="post" action="/admin/navigation/berechtigungen" class="form form--wide">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">

    <fieldset class="fieldset">
        <legend>Sichtbarkeit über AD-Gruppen</legend>
        <div class="field group-suggest">
            <label for="groups">Gruppennamen (kommagetrennt)</label>
            <input type="text" id="groups" name="groups" maxlength="1000" value="<?= $groupValue ?>"
                   autocomplete="off" spellcheck="false" aria-describedby="groups-hint"
                   data-group-suggest="/admin/ad/gruppen">
            <p class="field__hint" id="groups-hint">
                Namen der Active-Directory-Gruppen, deren Mitglieder das Element sehen dürfen (z. B. Verwaltung, Geschäftsleitung).
                Beim Tippen werden die per AD-Synchronisation übernommenen Gruppen vorgeschlagen
                (Pfeiltasten und Eingabetaste zur Auswahl, Tab übernimmt die Ergänzung).
                Leer lassen, damit das Element für alle sichtbar ist.
            </p>
        </div>
    </fieldset>

    <?php if ($users !== []) { ?>
        <fieldset class="fieldset">
            <legend>Bisher zugeordnete Einzelbenutzer</legend>
            <p class="field__hint">
                Einzelbenutzer werden nicht mehr neu zugeordnet. Haken entfernen, um eine bestehende Zuordnung aufzuheben.
            </p>
            <div class="permission-list">
                <?php foreach ($users as $user) {
                    $userId = (int) $user['id'];
                    $label = (string) $user['display_name'];
                    $details = array_filter([
                        (string) ($user['department'] ?? ''),
                        (string) (($sourceLabels ?? [])[(int) ($user['identity_source_id'] ?? 0)] ?? ''),
                    ], static fn (string $part): bool => $part !== '');
                    if ($details !== []) {
                        $label .= ' (' . implode(', ', $details) . ')';
                    }
                    ?>
                    <label class="permission-list__item">
                        <input type="checkbox" name="users[]" value="<?= $userId ?>" checked>
                        <span><?= Html::e($label) ?></span>
                        <span class="permission-list__hint"><?= Html::e((string) ($user['samaccount_name'] ?? '')) ?></span>
                    </label>
                <?php } ?>
            </div>
        </fieldset>
    <?php } ?>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Berechtigungen speichern</button>
        <a class="button button--ghost" href="/admin/navigation">Abbrechen</a>
    </div>
</form>