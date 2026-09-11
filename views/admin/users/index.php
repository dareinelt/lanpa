<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */
/** @var int|null $currentUserId */
$currentUserId = $currentUserId ?? null;
$roleLabels = [
    'admin' => 'Administrator',
    'redaktion' => 'Redaktion',
];
?>
<div class="toolbar">
    <a class="button button--primary" href="/admin/benutzer/neu">Neuer Benutzer</a>
</div>

<p class="field__hint">Administratoren dürfen den gesamten Adminbereich verwalten. Die Gruppe „Redaktion“ darf ausschließlich die wichtigen Links bearbeiten.</p>

<?php if ($items === []) { ?>
    <p class="empty-state">Es sind noch keine Benutzer vorhanden.</p>
<?php } else { ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der Administrationskonten</caption>
            <thead>
            <tr>
                <th scope="col">Benutzername</th>
                <th scope="col">Rolle</th>
                <th scope="col">Status</th>
                <th scope="col">Letzte Anmeldung</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item) {
                $id = (int) $item['id'];
                $role = (string) $item['role'];
                ?>
                <tr>
                    <td><strong><?= Html::e((string) $item['username']) ?></strong><?= $id === $currentUserId ? ' <span class="table__hint">(Sie)</span>' : '' ?></td>
                    <td><?= Html::e($roleLabels[$role] ?? $role) ?></td>
                    <td>
                        <span class="badge <?= (int) $item['active'] === 1 ? 'badge--ok' : 'badge--muted' ?>">
                            <?= (int) $item['active'] === 1 ? 'aktiv' : 'inaktiv' ?>
                        </span>
                    </td>
                    <td><span class="table__hint"><?= Html::e((string) ($item['last_login_at'] ?? '–') ?: '–') ?></span></td>
                    <td>
                        <div class="row-actions">
                            <a class="button button--ghost" href="/admin/benutzer/bearbeiten?id=<?= $id ?>">Bearbeiten</a>
                            <form method="post" action="/admin/benutzer/status" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button type="submit" class="button button--ghost">
                                    <?= (int) $item['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/benutzer/loeschen" class="inline-form"
                                  data-confirm="Soll der Benutzer wirklich gelöscht werden?">
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
