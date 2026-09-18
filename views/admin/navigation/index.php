<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */
/** @var bool $navTreeMode */
?>
<div class="toolbar">
    <a class="button button--primary" href="/admin/navigation/neu">Neues Element</a>

    <form method="post" action="/admin/navigation/ansicht" class="view-switch-form">
        <?= Csrf::field() ?>
        <label class="switch">
            <input type="checkbox" name="nav_tree_mode" value="1" data-auto-submit <?= $navTreeMode ? 'checked' : '' ?>>
            <span class="switch__track" aria-hidden="true"><span class="switch__thumb"></span></span>
            <span class="switch__label">Baumansicht</span>
        </label>
        <noscript>
            <button type="submit" class="button button--ghost">Übernehmen</button>
        </noscript>
    </form>
</div>

<?php if ($navTreeMode) { ?>
    <?php require __DIR__ . '/tree.php'; ?>
<?php } elseif ($items === []) { ?>
    <p class="empty-state">Es sind noch keine Navigationselemente vorhanden.</p>
<?php } else { ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der Navigationselemente</caption>
            <thead>
            <tr>
                <th scope="col">Reihenfolge</th>
                <th scope="col">Titel</th>
                <th scope="col">Ziel</th>
                <th scope="col">Typ</th>
                <th scope="col">Status</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $index => $item) {
                $id = (int) $item['id'];
                $itemType = (string) $item['type'];
                $typeLabels = [
                    'external' => 'extern',
                    'internal' => 'intern',
                    'subpage' => 'Unterseite',
                    'page' => 'Textseite',
                ];
                $typeLabel = $typeLabels[$itemType] ?? $itemType;
                $target = match ($itemType) {
                    'subpage' => '/unterseite?id=' . $id,
                    'page' => '/seite?id=' . $id,
                    default => (string) $item['url'],
                }; ?>
                <tr>
                    <td>
                        <div class="order-controls">
                            <span class="order-controls__value"><?= (int) $item['sort_order'] ?></span>
                            <form method="post" action="/admin/navigation/sortieren" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="icon-button" <?= $index === 0 ? 'disabled' : '' ?>
                                        aria-label="<?= Html::e((string) $item['title']) ?> nach oben verschieben">▲</button>
                            </form>
                            <form method="post" action="/admin/navigation/sortieren" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="icon-button" <?= $index === count($items) - 1 ? 'disabled' : '' ?>
                                        aria-label="<?= Html::e((string) $item['title']) ?> nach unten verschieben">▼</button>
                            </form>
                        </div>
                    </td>
                    <td>
                        <strong><?= Html::e((string) $item['title']) ?></strong>
                        <?php if ($item['parent_id'] !== null) { ?>
                            <div class="table__hint">untergeordnet (ID <?= (int) $item['parent_id'] ?>)</div>
                        <?php } ?>
                        <?php if ((string) $item['short_description'] !== '') { ?>
                            <div class="table__hint"><?= Html::e((string) $item['short_description']) ?></div>
                        <?php } ?>
                    </td>
                    <td class="table__url"><?= Html::e($target) ?></td>
                    <td><?= Html::e($typeLabel) ?></td>
                    <td>
                        <span class="badge <?= (int) $item['active'] === 1 ? 'badge--ok' : 'badge--muted' ?>">
                            <?= (int) $item['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="button button--ghost" href="/admin/navigation/bearbeiten?id=<?= $id ?>">Bearbeiten</a>
                            <form method="post" action="/admin/navigation/status" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="button button--ghost">
                                    <?= (int) $item['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/navigation/loeschen" class="inline-form"
                                  data-confirm="Soll das Element wirklich gelöscht werden?">
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
