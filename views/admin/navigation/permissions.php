<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var list<array<string,mixed>> $users */
/** @var list<int> $assignedUserIds */
/** @var list<string> $assignedGroupNames */
$assigned = array_fill_keys(array_map('intval', $assignedUserIds), true);
$groupValue = Html::e(implode(', ', $assignedGroupNames));
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/navigation">Zurück zur Navigation</a>
</div>

<h2>Berechtigungen für „<?= Html::e((string) $item['title']) ?>“</h2>
<p class="field__hint">
    Ohne Berechtigungseintrag ist die Kachel für alle sichtbar. Sobald mindestens
    ein Benutzer oder eine Gruppe zugeordnet ist, sehen nur diese Benutzer bzw.
    Mitglieder dieser AD-Gruppen die Kachel.
</p>

<form method="post" action="/admin/navigation/berechtigungen" class="form form--wide">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">

    <fieldset class="fieldset">
        <legend>Benutzer</legend>
        <?php if ($users === []) { ?>
            <p class="empty-state">Es sind noch keine Benutzer mit hinterlegtem Windows-Benutzernamen (SamAccountName) importiert. Führen Sie zuerst die AD-Synchronisation durch.</p>
        <?php } else { ?>
            <div class="permission-list">
                <?php foreach ($users as $user) {
                    $userId = (int) $user['id'];
                    $label = (string) $user['display_name'];
                    if ((string) ($user['department'] ?? '') !== '') {
                        $label .= ' (' . (string) $user['department'] . ')';
                    }
                    ?>
                    <label class="permission-list__item">
                        <input type="checkbox" name="users[]" value="<?= $userId ?>"
                            <?= isset($assigned[$userId]) ? 'checked' : '' ?>>
                        <span><?= Html::e($label) ?></span>
                        <span class="permission-list__hint"><?= Html::e((string) ($user['samaccount_name'] ?? '')) ?></span>
                    </label>
                <?php } ?>
            </div>
        <?php } ?>
    </fieldset>

    <fieldset class="fieldset">
        <legend>AD-Gruppen</legend>
        <div class="field">
            <label for="groups">Gruppennamen (kommagetrennt)</label>
            <input type="text" id="groups" name="groups" maxlength="1000" value="<?= $groupValue ?>">
            <p class="field__hint">Namen der Active-Directory-Gruppen, deren Mitglieder die Kachel sehen dürfen (z. B. Verwaltung, Geschäftsleitung).</p>
        </div>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Berechtigungen speichern</button>
        <a class="button button--ghost" href="/admin/navigation">Abbrechen</a>
    </div>
</form>
