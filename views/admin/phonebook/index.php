<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */
/** @var string $term */
?>
<form method="get" action="/admin/telefonliste" class="search" role="search">
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
                    <td><?= Html::e((string) $item['department']) ?></td>
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
                        <span class="badge <?= $visible ? 'badge--ok' : 'badge--muted' ?>">
                            <?= $visible ? 'eingeblendet' : 'ausgeblendet' ?>
                        </span>
                    </td>
                    <td>
                        <form method="post" action="/admin/telefonliste/status" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="visible" value="<?= $visible ? 0 : 1 ?>">
                            <input type="hidden" name="q" value="<?= Html::e($term) ?>">
                            <button type="submit" class="button button--ghost">
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
