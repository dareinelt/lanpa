<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var list<array<string,mixed>> $items */
?>
<section class="card">
    <h2 class="card__title">SMS-Code</h2>
    <p class="card__hint">Der sechsstellige Zugangscode wechselt täglich um 00:01 Uhr. Hier können Textvorlage und Gültigkeitsdauer der Code-SMS bearbeitet werden.</p>

    <form method="post" action="/admin/aktivierungs-rufnummern" class="form form--wide">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="sms_code_template">Textvorlage der Code-SMS</label>
            <textarea id="sms_code_template" name="sms_code_template" rows="3" maxlength="255"
                      <?= isset($errors['sms_code_template']) ? 'aria-invalid="true" aria-describedby="sms_code_template-error"' : '' ?>><?= Html::e((string) $values['sms_code_template']) ?></textarea>
            <p class="field__hint">Platzhalter: <code>{code}</code> (sechsstelliger Code) und <code>{title}</code> (Kacheltitel).</p>
            <?php if (isset($errors['sms_code_template'])) { ?>
                <p class="field__error" id="sms_code_template-error"><?= Html::e($errors['sms_code_template']) ?></p>
            <?php } ?>
        </div>

        <div class="field">
            <label for="sms_code_timeout">Gültigkeitsdauer (Sekunden)</label>
            <input type="number" id="sms_code_timeout" name="sms_code_timeout" min="30" max="600" step="1"
                   value="<?= Html::e((string) $values['sms_code_timeout']) ?>"
                   <?= isset($errors['sms_code_timeout']) ? 'aria-invalid="true" aria-describedby="sms_code_timeout-error"' : '' ?>>
            <p class="field__hint">Zeit, in der der Code nach der Anforderung eingegeben werden kann (30–600 Sekunden, Standard 120).</p>
            <?php if (isset($errors['sms_code_timeout'])) { ?>
                <p class="field__error" id="sms_code_timeout-error"><?= Html::e($errors['sms_code_timeout']) ?></p>
            <?php } ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card__title">Aktivierungs-Rufnummern</h2>
    <p class="card__hint">Rufnummern, an die der Zugangscode gesendet werden darf. Jede Rufnummer wird einer Gruppe aus den Alarmierungseinstellungen zugeordnet.</p>

    <div class="toolbar">
        <a class="button button--primary" href="/admin/aktivierungs-rufnummern/neu">Neue Rufnummer</a>
    </div>

    <?php if ($items === []) { ?>
        <p class="empty-state">Es sind noch keine Aktivierungs-Rufnummern vorhanden.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Liste der Aktivierungs-Rufnummern</caption>
                <thead>
                <tr>
                    <th scope="col">Reihenfolge</th>
                    <th scope="col">Rufnummer</th>
                    <th scope="col">Gruppe</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $index => $item) {
                    $id = (int) $item['id']; ?>
                    <tr>
                        <td>
                            <div class="order-controls">
                                <span class="order-controls__value"><?= (int) $item['sort_order'] ?></span>
                                <form method="post" action="/admin/aktivierungs-rufnummern/sortieren" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="icon-button" <?= $index === 0 ? 'disabled' : '' ?>
                                            aria-label="Rufnummer nach oben verschieben">▲</button>
                                </form>
                                <form method="post" action="/admin/aktivierungs-rufnummern/sortieren" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="icon-button" <?= $index === count($items) - 1 ? 'disabled' : '' ?>
                                            aria-label="Rufnummer nach unten verschieben">▼</button>
                                </form>
                            </div>
                        </td>
                        <td><code><?= Html::e((string) $item['phone']) ?></code></td>
                        <td>
                            <?= Html::e((string) ($item['group_description'] ?? '')) ?>
                            <div class="table__hint"><?= Html::e((string) ($item['group_number'] ?? '')) ?></div>
                        </td>
                        <td>
                            <span class="badge <?= (int) $item['active'] === 1 ? 'badge--ok' : 'badge--muted' ?>">
                                <?= (int) $item['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="/admin/aktivierungs-rufnummern/bearbeiten?id=<?= $id ?>">Bearbeiten</a>
                                <form method="post" action="/admin/aktivierungs-rufnummern/status" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button type="submit" class="button button--ghost">
                                        <?= (int) $item['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                                    </button>
                                </form>
                                <form method="post" action="/admin/aktivierungs-rufnummern/loeschen" class="inline-form"
                                      data-confirm="Soll die Rufnummer wirklich gelöscht werden?">
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
</section>
