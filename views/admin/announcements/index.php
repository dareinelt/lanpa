<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Dates;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */
?>
<div class="toolbar">
    <a class="button button--primary" href="/admin/mitteilungen/neu">Neue Mitteilung</a>
</div>

<?php if ($items === []) { ?>
    <p class="empty-state">Es sind noch keine Mitteilungen vorhanden.</p>
<?php } else { ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der Mitteilungen</caption>
            <thead>
            <tr>
                <th scope="col">Titel</th>
                <th scope="col">Erstellt</th>
                <th scope="col">Status</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item) {
                $id = (int) $item['id'];
                $isActive = (int) $item['active'] === 1; ?>
                <tr>
                    <td>
                        <strong><?= Html::e((string) $item['title']) ?></strong>
                    </td>
                    <td><?= Html::e(Dates::formatDateTime((string) $item['created_at'])) ?></td>
                    <td>
                        <span class="badge <?= $isActive ? 'badge--ok' : 'badge--muted' ?>">
                            <?= $isActive ? 'aktiv' : 'archiviert' ?>
                        </span>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="button button--ghost" href="/admin/mitteilungen/bearbeiten?id=<?= $id ?>">Bearbeiten</a>
                            <form method="post" action="/admin/mitteilungen/status" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="button button--ghost">
                                    <?= $isActive ? 'Archivieren' : 'Reaktivieren' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/mitteilungen/loeschen" class="inline-form"
                                  data-confirm="Soll die Mitteilung wirklich gelöscht werden?">
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
