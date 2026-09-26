<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */
/** @var string $term */
/** @var array{has_email:bool,is_active:bool,has_phone:bool,is_visible:bool} $filters */
?>
<form method="get" action="/admin/telefonliste" class="search" role="search" data-phonebook-filter-form>
    <label class="search__label" for="phonebook-admin-search">Eintrag suchen</label>
    <div class="search__control">
        <input type="search"
               id="phonebook-admin-search"
               name="q"
               class="search__input"
               value="<?= Html::e($term) ?>"
               placeholder="Name, Abteilung oder Nummer"
               autocomplete="off">
        <button type="submit" class="button button--primary">Suchen</button>
    </div>
    <fieldset class="fieldset fieldset--filters">
        <legend>Filter</legend>
        <div class="field-row">
            <div class="field field--check">
                <input type="checkbox" id="filter-has-email" name="has_email" value="1" data-phonebook-filter <?= $filters['has_email'] ? 'checked' : '' ?>>
                <label for="filter-has-email">Hat E-Mail-Adresse</label>
            </div>
            <div class="field field--check">
                <input type="checkbox" id="filter-is-active" name="is_active" value="1" data-phonebook-filter <?= $filters['is_active'] ? 'checked' : '' ?>>
                <label for="filter-is-active">Ist aktiv</label>
            </div>
            <div class="field field--check">
                <input type="checkbox" id="filter-has-phone" name="has_phone" value="1" data-phonebook-filter <?= $filters['has_phone'] ? 'checked' : '' ?>>
                <label for="filter-has-phone">Hat Telefonnummer</label>
            </div>
            <div class="field field--check">
                <input type="checkbox" id="filter-is-visible" name="is_visible" value="1" data-phonebook-filter <?= $filters['is_visible'] ? 'checked' : '' ?>>
                <label for="filter-is-visible">Nur eingeblendete</label>
            </div>
        </div>
    </fieldset>
</form>

<?php if ($items === []) { ?>
    <p class="empty-state">Keine Einträge gefunden.</p>
<?php } else { ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der Telefonbucheinträge (<?= count($items) ?> Einträge)</caption>
            <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Abteilung</th>
                <th scope="col">Telefon</th>
                <th scope="col">Status</th>
                <th scope="col">Sichtbarkeit</th>
                <th scope="col">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item) {
                $id = (int) $item['id'];
                $active = (bool) $item['active'];
                $visible = (bool) $item['visible']; ?>
                <tr>
                    <td>
                        <strong><?= Html::e((string) $item['display_name']) ?></strong>
                        <?php if ((string) $item['email'] !== '') { ?>
                            <div class="table__hint"><?= Html::e((string) $item['email']) ?></div>
                        <?php } ?>
                    </td>
                    <td>
                        <?= Html::e((string) $item['department']) ?>
                        <?php if ((string) ($item['source'] ?? '') !== '') { ?>
                            <div class="table__hint"><?= Html::e((string) $item['source']) ?></div>
                        <?php } ?>
                    </td>
                    <td>
                        <?= Html::e((string) $item['phone']) ?>
                        <?php if ((string) $item['mobile'] !== '') { ?>
                            <div class="table__hint"><?= Html::e((string) $item['mobile']) ?></div>
                        <?php } ?>
                    </td>
                    <td>
                        <span class="badge <?= $active ? 'badge--ok' : 'badge--muted' ?>">
                            <?= $active ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $visible ? 'badge--ok' : 'badge--muted' ?>"
                              data-phonebook-visible="<?= $visible ? '1' : '0' ?>">
                            <?= $visible ? 'eingeblendet' : 'ausgeblendet' ?>
                        </span>
                    </td>
                    <td>
                        <form method="post" action="/admin/telefonliste/status" class="inline-form" data-phonebook-toggle>
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="visible" value="<?= $visible ? 0 : 1 ?>">
                            <input type="hidden" name="q" value="<?= Html::e($term) ?>">
                            <input type="hidden" name="has_email" value="<?= $filters['has_email'] ? '1' : '0' ?>">
                            <input type="hidden" name="is_active" value="<?= $filters['is_active'] ? '1' : '0' ?>">
                            <input type="hidden" name="has_phone" value="<?= $filters['has_phone'] ? '1' : '0' ?>">
                            <input type="hidden" name="is_visible" value="<?= $filters['is_visible'] ? '1' : '0' ?>">
                            <button type="submit" class="button button--ghost" data-phonebook-toggle-button>
                                <?= $visible ? 'Ausblenden' : 'Einblenden' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
<?php } ?>
