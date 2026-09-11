<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */
?>
<div class="toolbar">
    <a class="button button--primary" href="/admin/wichtige-links/neu">Neuer Link</a>
</div>

<p class="field__hint">Die Liste wird auf der Startseite automatisch alphabetisch sortiert; eine manuelle Reihenfolge ist hier nicht vorgesehen.</p>

<?php if ($items === []) { ?>
    <p class="empty-state">Es sind noch keine wichtigen Links vorhanden.</p>
<?php } else { ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der wichtigen Links</caption>
            <thead>
            <tr>
                <th scope="col">Icon</th>
                <th scope="col">Titel</th>
                <th scope="col">URL</th>
                <th scope="col">Status</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item) {
                $id = (int) $item['id'];
                $hasIcon = (string) ($item['icon_file'] ?? '') !== '';
                ?>
                <tr>
                    <td>
                        <?php if ($hasIcon) { ?>
                            <img class="important-links__admin-icon" src="/wichtige-links/icon?id=<?= $id ?>" alt="" width="20" height="20">
                        <?php } else { ?>
                            <span class="table__hint">–</span>
                        <?php } ?>
                    </td>
                    <td><strong><?= Html::e((string) $item['title']) ?></strong></td>
                    <td><span class="table__hint"><?= Html::e((string) $item['url']) ?></span></td>
                    <td>
                        <span class="badge <?= (int) $item['active'] === 1 ? 'badge--ok' : 'badge--muted' ?>">
                            <?= (int) $item['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="button button--ghost" href="/admin/wichtige-links/bearbeiten?id=<?= $id ?>">Bearbeiten</a>
                            <form method="post" action="/admin/wichtige-links/status" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="button button--ghost">
                                    <?= (int) $item['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/wichtige-links/loeschen" class="inline-form"
                                  data-confirm="Soll der Link wirklich gelöscht werden?">
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
