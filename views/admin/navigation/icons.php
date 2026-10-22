<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $icons */
/** @var array<string,string> $errors */
/** @var string $alias */
/** @var int $maxIconKb */
?>
<p class="field__hint">
    Eigene SVG-Icons für Navigationskacheln. Der Alias entspricht dem Wert im Feld „Icon“ der Kachel-Einstellung.
    Dateien werden außerhalb des Webverzeichnisses gespeichert und über die Anwendung ausgeliefert; SVG-Dateien
    werden auf aktive Inhalte geprüft. Bitte im SVG eine eigene Füll- oder Konturfarbe verwenden, da
    <code>currentColor</code> in eingebundenen Dateien nicht übernommen wird.
</p>

<form method="post" action="/admin/navigation/icons" enctype="multipart/form-data" class="form form--wide">
    <?= Csrf::field() ?>
    <fieldset class="fieldset">
        <legend>Icon hinzufügen</legend>
        <div class="field-row">
            <div class="field">
                <label for="alias">Alias <span aria-hidden="true">*</span></label>
                <input type="text" id="alias" name="alias" required maxlength="32" pattern="[a-z0-9-]{1,32}"
                       value="<?= Html::e($alias) ?>"
                       aria-describedby="alias-hint">
                <p class="field__hint" id="alias-hint">
                    Nur Kleinbuchstaben, Ziffern und Bindestriche (max. 32 Zeichen), z. B. <code>haus</code>.
                    Bereits vergebene Standard-Icons können nicht überschrieben werden.
                </p>
                <?php if (isset($errors['alias'])) { ?>
                    <p class="field__error"><?= Html::e($errors['alias']) ?></p>
                <?php } ?>
            </div>
            <div class="field">
                <label for="svg">SVG-Datei <span aria-hidden="true">*</span></label>
                <input type="file" id="svg" name="svg" accept="image/svg+xml,.svg" required>
                <p class="field__hint">Maximal <?= (int) $maxIconKb ?> KB. Skripte, Event-Handler und externe Referenzen sind nicht erlaubt.</p>
                <?php if (isset($errors['svg'])) { ?>
                    <p class="field__error"><?= Html::e($errors['svg']) ?></p>
                <?php } ?>
            </div>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Icon hinzufügen</button>
        </div>
    </fieldset>
</form>

<?php if ($icons === []) { ?>
    <p class="empty-state">Es sind noch keine eigenen Icons vorhanden.</p>
<?php } else { ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der eigenen Icons</caption>
            <thead>
            <tr>
                <th scope="col">Vorschau</th>
                <th scope="col">Alias</th>
                <th scope="col">Angelegt</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($icons as $icon) {
                $id = (int) $icon['id'];
                $iconAlias = (string) $icon['alias'];
                $previewUrl = '/kachel-icon?alias=' . rawurlencode($iconAlias);
                ?>
                <tr>
                    <td>
                        <img class="nav-icon-preview" src="<?= Html::e($previewUrl) ?>" alt="" width="32" height="32">
                    </td>
                    <td>
                        <strong><?= Html::e($iconAlias) ?></strong>
                    </td>
                    <td><span class="table__hint"><?= Html::e((string) $icon['created_at']) ?></span></td>
                    <td>
                        <div class="nav-icon-actions">
                            <form method="post" action="/admin/navigation/icons/ersetzen" enctype="multipart/form-data">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="file" name="svg" accept="image/svg+xml,.svg" required
                                       aria-label="Neue SVG-Datei für <?= Html::e($iconAlias) ?>">
                                <button type="submit" class="button button--ghost">Ersetzen</button>
                            </form>
                            <form method="post" action="/admin/navigation/icons/umbenennen">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="text" name="alias" value="<?= Html::e($iconAlias) ?>" required
                                       maxlength="32" pattern="[a-z0-9-]{1,32}"
                                       aria-label="Neuer Alias für <?= Html::e($iconAlias) ?>">
                                <button type="submit" class="button button--ghost">Umbenennen</button>
                            </form>
                            <form method="post" action="/admin/navigation/icons/loeschen"
                                  data-confirm="Soll das Icon „<?= Html::e($iconAlias) ?>“ wirklich gelöscht werden?">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
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
