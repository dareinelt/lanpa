<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var list<array<string,mixed>> $groups */
/** @var list<array<string,mixed>> $history */
/** @var bool $hasPassword */
/** @var bool $hasEnvPassword */
/** @var bool $hasSinglePassword */
?>
<section class="card">
    <h2 class="card__title">SMS-Gateway</h2>
    <p class="card__hint">Zieladresse, Benutzername und Passwort werden in die Gateway-URL eingesetzt. Das Passwort wird niemals angezeigt.</p>

    <form method="post" action="/admin/alarmierung" class="form form--wide">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="alarm_host">Zieladresse <span aria-hidden="true">*</span></label>
            <input type="text" id="alarm_host" name="alarm_host" maxlength="253"
                   value="<?= Html::e((string) $values['alarm_host']) ?>"
                   placeholder="sms.example.de"
                   <?= isset($errors['alarm_host']) ? 'aria-invalid="true" aria-describedby="alarm_host-error"' : '' ?>>
            <p class="field__hint">Hostname oder IP-Adresse, optional mit Port (z. B. sms.example.de:8080).</p>
            <?php if (isset($errors['alarm_host'])) { ?>
                <p class="field__error" id="alarm_host-error"><?= Html::e($errors['alarm_host']) ?></p>
            <?php } ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="alarm_username">Benutzername <span aria-hidden="true">*</span></label>
                <input type="text" id="alarm_username" name="alarm_username" maxlength="255"
                       value="<?= Html::e((string) $values['alarm_username']) ?>"
                       <?= isset($errors['alarm_username']) ? 'aria-invalid="true" aria-describedby="alarm_username-error"' : '' ?>>
                <?php if (isset($errors['alarm_username'])) { ?>
                    <p class="field__error" id="alarm_username-error"><?= Html::e($errors['alarm_username']) ?></p>
                <?php } ?>
            </div>

            <div class="field">
                <label for="alarm_password">Passwort</label>
                <?php if ($hasEnvPassword) { ?>
                    <input type="password" id="alarm_password" name="alarm_password" value="" disabled
                           placeholder="aus Umgebungsvariable">
                    <p class="field__hint">Das Passwort wird über die Umgebung bereitgestellt und kann hier nicht geändert werden.</p>
                <?php } else { ?>
                    <input type="password" id="alarm_password" name="alarm_password" maxlength="255"
                           value="" autocomplete="new-password"
                           placeholder="<?= $hasPassword ? '••••••••' : '' ?>"
                           <?= isset($errors['alarm_password']) ? 'aria-invalid="true" aria-describedby="alarm_password-error"' : '' ?>>
                    <p class="field__hint">Leer lassen, um das vorhandene Passwort beizubehalten.</p>
                    <?php if (isset($errors['alarm_password'])) { ?>
                        <p class="field__error" id="alarm_password-error"><?= Html::e($errors['alarm_password']) ?></p>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>

        <fieldset class="fieldset" data-single-form>
            <legend class="fieldset__legend">Einzelnummern-Versand</legend>
            <p class="card__hint">Versand an einzelne Rufnummern (<code>mode=number</code>). Ohne abweichende Angaben werden Zieladresse, Benutzername und Passwort der bisherigen Einstellungen verwendet.</p>

            <div class="field field--check">
                <input type="checkbox" id="alarm_single_custom" name="alarm_single_custom" value="1"
                       data-single-toggle
                       <?= $values['alarm_single_custom'] === '1' ? 'checked' : '' ?>>
                <label for="alarm_single_custom">Einzelversand benötigt andere Einstellungen</label>
            </div>

            <div class="field" data-single-field>
                <label for="alarm_single_host">Abweichende Zieladresse</label>
                <input type="text" id="alarm_single_host" name="alarm_single_host" maxlength="253"
                       value="<?= Html::e((string) $values['alarm_single_host']) ?>"
                       placeholder="sms.example.de"
                       <?= isset($errors['alarm_single_host']) ? 'aria-invalid="true" aria-describedby="alarm_single_host-error"' : '' ?>>
                <p class="field__hint">Hostname oder IP-Adresse, optional mit Port (z. B. sms.example.de:8080).</p>
                <?php if (isset($errors['alarm_single_host'])) { ?>
                    <p class="field__error" id="alarm_single_host-error"><?= Html::e($errors['alarm_single_host']) ?></p>
                <?php } ?>
            </div>

            <div class="field-row">
                <div class="field" data-single-field>
                    <label for="alarm_single_username">Abweichender Benutzername</label>
                    <input type="text" id="alarm_single_username" name="alarm_single_username" maxlength="255"
                           value="<?= Html::e((string) $values['alarm_single_username']) ?>"
                           <?= isset($errors['alarm_single_username']) ? 'aria-invalid="true" aria-describedby="alarm_single_username-error"' : '' ?>>
                    <?php if (isset($errors['alarm_single_username'])) { ?>
                        <p class="field__error" id="alarm_single_username-error"><?= Html::e($errors['alarm_single_username']) ?></p>
                    <?php } ?>
                </div>

                <div class="field" data-single-field>
                    <label for="alarm_single_password">Abweichendes Passwort</label>
                    <input type="password" id="alarm_single_password" name="alarm_single_password" maxlength="255"
                           value="" autocomplete="new-password"
                           placeholder="<?= $hasSinglePassword ? '••••••••' : '' ?>"
                           <?= isset($errors['alarm_single_password']) ? 'aria-invalid="true" aria-describedby="alarm_single_password-error"' : '' ?>>
                    <p class="field__hint">Leer lassen, um das vorhandene Passwort beizubehalten.</p>
                    <?php if (isset($errors['alarm_single_password'])) { ?>
                        <p class="field__error" id="alarm_single_password-error"><?= Html::e($errors['alarm_single_password']) ?></p>
                    <?php } ?>
                </div>
            </div>
        </fieldset>

        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card__title">Gruppen &amp; Rufnummern</h2>
    <div class="toolbar">
        <a class="button button--primary" href="/admin/alarmierung/gruppen/neu">Neues Ziel</a>
    </div>

    <?php if ($groups === []) { ?>
        <p class="empty-state">Es sind noch keine Gruppen oder Rufnummern vorhanden.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Liste der Gruppen und Rufnummern</caption>
                <thead>
                <tr>
                    <th scope="col">Typ</th>
                    <th scope="col">Gruppennummer / Rufnummer</th>
                    <th scope="col">Beschreibung</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $group) {
                    $groupId = (int) $group['id'];
                    $groupType = (string) ($group['type'] ?? 'group'); ?>
                    <tr>
                        <td>
                            <span class="badge <?= $groupType === 'number' ? 'badge--ok' : 'badge--muted' ?>">
                                <?= $groupType === 'number' ? 'Rufnummer' : 'Gruppe' ?>
                            </span>
                        </td>
                        <td><code><?= Html::e((string) $group['group_number']) ?></code></td>
                        <td><?= Html::e((string) $group['description']) ?></td>
                        <td>
                            <span class="badge <?= (int) $group['active'] === 1 ? 'badge--ok' : 'badge--muted' ?>">
                                <?= (int) $group['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="/admin/alarmierung/gruppen/bearbeiten?id=<?= $groupId ?>">Bearbeiten</a>
                                <form method="post" action="/admin/alarmierung/gruppen/status" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $groupId ?>">
                                    <button type="submit" class="button button--ghost">
                                        <?= (int) $group['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                                    </button>
                                </form>
                                <form method="post" action="/admin/alarmierung/gruppen/loeschen" class="inline-form"
                                      data-confirm="Soll das Ziel wirklich gelöscht werden?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $groupId ?>">
                                    <button type="submit" class="button button--danger">Löschen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<section class="card">
    <h2 class="card__title">Alarmierungsverlauf</h2>

    <?php if ($history === []) { ?>
        <p class="empty-state">Es wurden noch keine Alarmierungen ausgelöst.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Alarmierungsverlauf</caption>
                <thead>
                <tr>
                    <th scope="col">Zeit</th>
                    <th scope="col">Kachel</th>
                    <th scope="col">Text</th>
                    <th scope="col">Ziel</th>
                    <th scope="col">Status</th>
                    <th scope="col">Meldung</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $entry) { ?>
                    <tr>
                        <td><?= Html::e((string) $entry['triggered_at']) ?></td>
                        <td><?= Html::e((string) $entry['title']) ?></td>
                        <td><?= Html::e((string) $entry['alarm_text']) ?></td>
                        <td>
                            <?= Html::e((string) $entry['group_description']) ?>
                            <div class="table__hint">
                                <?= Html::e((string) $entry['group_number']) ?>
                                <?= (string) ($entry['mode'] ?? 'group') === 'number' ? ' · Rufnummer' : '' ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= (string) $entry['status'] === 'success' ? 'badge--ok' : 'badge--warn' ?>">
                                <?= (string) $entry['status'] === 'success' ? 'erfolgreich' : 'fehlgeschlagen' ?>
                            </span>
                        </td>
                        <td><?= Html::e((string) ($entry['message'] ?? '')) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
