<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */
?>
<div class="toolbar">
    <a class="button button--primary" href="/admin/notfallnummern/neu">Neue Notfallnummer</a>
</div>

<?php if ($items === []) { ?>
    <p class="empty-state">Es sind noch keine Notfallnummern vorhanden.</p>
<?php } else { ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der Notfallnummern</caption>
            <thead>
            <tr>
                <th scope="col">Reihenfolge</th>
                <th scope="col">Bezeichnung</th>
                <th scope="col">Rufnummer</th>
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
                            <form method="post" action="/admin/notfallnummern/sortieren" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="icon-button" <?= $index === 0 ? 'disabled' : '' ?>
                                        aria-label="<?= Html::e((string) $item['label']) ?> nach oben verschieben">▲</button>
                            </form>
                            <form method="post" action="/admin/notfallnummern/sortieren" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="icon-button" <?= $index === count($items) - 1 ? 'disabled' : '' ?>
                                        aria-label="<?= Html::e((string) $item['label']) ?> nach unten verschieben">▼</button>
                            </form>
                        </div>
                    </td>
                    <td>
                        <strong><?= Html::e((string) $item['label']) ?></strong>
                        <?php if ((string) $item['description'] !== '') { ?>
                            <div class="table__hint"><?= Html::e((string) $item['description']) ?></div>
                        <?php } ?>
                    </td>
                    <td><?= Html::e((string) $item['phone']) ?></td>
                    <td>
                        <span class="badge <?= (int) $item['active'] === 1 ? 'badge--ok' : 'badge--muted' ?>">
                            <?= (int) $item['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="button button--ghost" href="/admin/notfallnummern/bearbeiten?id=<?= $id ?>">Bearbeiten</a>
                            <form method="post" action="/admin/notfallnummern/status" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="button button--ghost">
                                    <?= (int) $item['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/notfallnummern/loeschen" class="inline-form"
                                  data-confirm="Soll die Notfallnummer wirklich gelöscht werden?">
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
