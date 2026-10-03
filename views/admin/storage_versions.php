<?php

declare(strict_types=1);

use App\Repositories\StorageRepository;
use App\Security\Csrf;
use App\Services\Storage\StorageHealth;
use App\Support\Dates;
use App\Support\Html;

/** @var array{limit:int,from:string,to:string,user:string,path:string,status:string,deleted:bool} $filter */
/** @var list<array<string,mixed>> $rows */
/** @var int $total */
/** @var list<string> $users */
/** @var array<string,mixed> $snapshot */
/** @var array<string,string> $results */

$statusLabel = static fn (string $status): string => [
    'complete' => 'gesichert',
    'pending' => 'vorgemerkt',
    'unavailable' => 'wartet auf Speicher',
    'failed' => 'fehlgeschlagen',
][$status] ?? $status;
$statusBadge = static fn (string $status): string => match ($status) {
    'complete' => 'badge--ok',
    'pending', 'unavailable' => 'badge--warn',
    'failed' => 'badge--error',
    default => 'badge--muted',
};
$stateBadge = static fn (string $state): string => match ($state) {
    'online' => 'badge--ok',
    'offline', 'invalid' => 'badge--error',
    default => 'badge--muted',
};
$hidden = static function () use ($filter): string {
    $html = '';
    foreach ($filter as $key => $value) {
        $value = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        if ($value !== '') {
            $html .= '<input type="hidden" name="filter_' . Html::e($key) . '" value="' . Html::e($value) . '">';
        }
    }

    return $html;
};
$relative = static function (string $path): string {
    // <benutzer>/files/<pfad> -> <pfad> (Benutzer steht in eigener Spalte)
    $parts = explode('/', $path, 3);

    return count($parts) === 3 && $parts[1] === 'files' ? $parts[2] : $path;
};
?>
<div class="storage-page storage-versions">
<section class="card">
    <div class="storage-section-head">
        <div>
            <p class="storage-eyebrow">Snapshot-Speicher</p>
            <h1 class="card__title">Dateiversionen</h1>
        </div>
        <span class="badge <?= $stateBadge((string) $snapshot['state']) ?>"><?= Html::e((string) $snapshot['state_label']) ?></span>
    </div>
    <p class="card__hint">Vorgängerversionen von Nextcloud-Dateien, die vor einer Änderung oder Löschung gesichert wurden. Eine Wiederherstellung ersetzt die aktuelle Datei durch die gewählte Version (bzw. legt eine gelöschte Datei wieder an). Sie ist ein administrativer Vorgang: Es wird dabei keine neue Dateiversion erzeugt.</p>
    <p class="card__hint"><a href="/admin/speicher-ha#snapshots">Zurück zu Speicher (HA)</a></p>
    <?php if (!$snapshot['enabled']) { ?>
        <p class="flash flash--info">Der Snapshot-Speicher ist nicht aktiviert. Bereits gesicherte Versionen werden weiterhin angezeigt, Wiederherstellungen sind erst nach dem Einschalten möglich.</p>
    <?php } elseif ($snapshot['state'] !== 'online') { ?>
        <p class="flash flash--error">Der Snapshot-Speicher ist derzeit nicht erreichbar<?= $snapshot['message'] !== '' ? ': ' . Html::e((string) $snapshot['message']) : '.' ?> Wiederherstellungen werden ausgeführt, sobald er wieder verfügbar ist.</p>
    <?php } ?>

    <form method="get" action="/admin/speicher-ha/dateiversionen" class="form storage-versions__filter" role="search">
        <fieldset class="fieldset fieldset--filters">
            <legend>Filter</legend>
            <div class="storage-fields">
                <div class="field">
                    <label for="from">Gesichert von</label>
                    <input type="date" id="from" name="from" value="<?= Html::e($filter['from']) ?>">
                </div>
                <div class="field">
                    <label for="to">bis</label>
                    <input type="date" id="to" name="to" value="<?= Html::e($filter['to']) ?>">
                </div>
                <div class="field">
                    <label for="user">Benutzer</label>
                    <input type="text" id="user" name="user" maxlength="100" list="snapshot-users" autocomplete="off" value="<?= Html::e($filter['user']) ?>">
                    <datalist id="snapshot-users">
                        <?php foreach ($users as $user) { ?><option value="<?= Html::e($user) ?>"></option><?php } ?>
                    </datalist>
                </div>
                <div class="field">
                    <label for="path">Pfad enthält</label>
                    <input type="search" id="path" name="path" maxlength="300" value="<?= Html::e($filter['path']) ?>">
                </div>
                <div class="field">
                    <label for="status">Zustand</label>
                    <select id="status" name="status">
                        <option value="">alle</option>
                        <?php foreach (StorageRepository::SNAPSHOT_STATUSES as $status) { ?>
                            <option value="<?= Html::e($status) ?>" <?= $filter['status'] === $status ? 'selected' : '' ?>><?= Html::e($statusLabel($status)) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="field">
                    <label for="limit">Einträge</label>
                    <select id="limit" name="limit">
                        <?php foreach (StorageRepository::SNAPSHOT_LIMITS as $limit) { ?>
                            <option value="<?= (int) $limit ?>" <?= $filter['limit'] === $limit ? 'selected' : '' ?>><?= (int) $limit ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="field field--check">
                    <input type="checkbox" id="deleted" name="deleted" value="1" <?= $filter['deleted'] ? 'checked' : '' ?>>
                    <label for="deleted">Nur gelöschte Dateien</label>
                </div>
            </div>
        </fieldset>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Filtern</button>
            <a class="button button--ghost" href="/admin/speicher-ha/dateiversionen">Zurücksetzen</a>
        </div>
    </form>

    <p class="card__hint" aria-live="polite"><?= number_format($total, 0, ',', '.') ?> Version(en) gefunden<?= $total > count($rows) ? ', die neuesten ' . count($rows) . ' werden angezeigt' : '' ?>.</p>

    <?php if ($rows === []) { ?>
        <p class="empty-state">Keine Dateiversionen für diese Auswahl.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table storage-versions__table">
                <caption class="visually-hidden">Gesicherte Dateiversionen</caption>
                <thead>
                    <tr>
                        <th scope="col">Gesichert</th>
                        <th scope="col">Benutzer</th>
                        <th scope="col">Datei</th>
                        <th scope="col">Version</th>
                        <th scope="col">Größe</th>
                        <th scope="col">Zustand</th>
                        <th scope="col"><span class="visually-hidden">Aktion</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) {
                    $uid = (string) $row['uid'];
                    $deleted = (int) $row['file_deleted'] === 1;
                    $result = $results[$uid] ?? null;
                    $restorable = $snapshot['enabled'] && $row['status'] === 'complete' && $result !== '';
                    ?>
                    <tr data-uid="<?= Html::e($uid) ?>">
                        <td class="table__hint"><?= Html::e(Dates::formatDateTime((string) $row['created_at'])) ?></td>
                        <td><?= $row['user'] !== '' ? Html::e((string) $row['user']) : '<span class="table__hint">–</span>' ?></td>
                        <td>
                            <code><?= Html::e($relative((string) $row['path'])) ?></code>
                            <div class="table__hint">
                                Stand <?= Html::e(Dates::formatDateTime((string) $row['file_mtime'])) ?>
                                <?php if ($deleted) { ?> · <span class="badge badge--warn">Datei gelöscht</span><?php } ?>
                                <?php if (($row['restored_at'] ?? null) !== null) { ?> · wiederhergestellt <?= Html::e(Dates::formatDateTime((string) $row['restored_at'])) ?> (<?= Html::e((string) $row['restored_by']) ?>)<?php } ?>
                            </div>
                            <?php if ($result !== null) { ?>
                                <div class="table__hint <?= $result === '' ? '' : (str_starts_with($result, 'Fehler') ? 'storage-versions__result--error' : 'storage-versions__result--ok') ?>"><?= $result === '' ? 'Wiederherstellung läuft …' : Html::e($result) ?></div>
                            <?php } elseif (($row['error'] ?? '') !== '') { ?>
                                <div class="table__hint storage-versions__result--error"><?= Html::e((string) $row['error']) ?></div>
                            <?php } ?>
                        </td>
                        <td><?= (int) $row['version'] ?></td>
                        <td><?= Html::e(StorageHealth::formatBytes((int) $row['size'])) ?></td>
                        <td><span class="badge <?= $statusBadge((string) $row['status']) ?>"><?= Html::e($statusLabel((string) $row['status'])) ?></span></td>
                        <td>
                            <?php if ($restorable) { ?>
                                <form method="post" action="/admin/speicher-ha/dateiversionen/wiederherstellen" class="inline-form" data-restore-form>
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="uid" value="<?= Html::e($uid) ?>">
                                    <?= $hidden() ?>
                                    <button type="submit" class="button button--ghost"
                                            data-restore-path="<?= Html::e((string) $row['path']) ?>"
                                            data-restore-version="<?= (int) $row['version'] ?>"
                                            data-restore-deleted="<?= $deleted ? '1' : '0' ?>">Wiederherstellen</button>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<dialog id="restore-dialog" class="storage-versions__dialog" aria-labelledby="restore-dialog-title">
    <h2 id="restore-dialog-title">Dateiversion wiederherstellen?</h2>
    <p id="restore-dialog-text"></p>
    <p class="card__hint">Die aktuelle Datei wird durch diese Vorgängerversion ersetzt. Dabei entsteht keine neue Dateiversion.</p>
    <div class="form__actions">
        <button type="button" class="button button--primary" id="restore-dialog-yes">Ja, wiederherstellen</button>
        <button type="button" class="button button--ghost" id="restore-dialog-no" autofocus>Nein</button>
    </div>
</dialog>
</div>
