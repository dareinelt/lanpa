<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Dates;
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

<?php
/** @var array<string,mixed> $directory */
/** @var bool $ssoEnabled */
/** @var bool $officeEnabled */
$directory = $directory ?? ['available' => false];
$errors = $errors ?? [];
$values = $values ?? [];

$groupSection = static function (string $target, string $title, string $intro, array $rules, array $members, string $memberHint) use ($errors, $values): void {
    $field = $target . '_group';
    ?>
    <section class="card" id="ad-<?= Html::e($target) ?>" aria-labelledby="ad-<?= Html::e($target) ?>-title">
        <h2 class="card__title" id="ad-<?= Html::e($target) ?>-title"><?= Html::e($title) ?></h2>
        <p class="card__hint"><?= $intro ?></p>

        <?php if ($rules === []) { ?>
            <p class="empty-state">Es ist keine AD-Gruppe eingetragen.</p>
        <?php } else { ?>
            <div class="table-wrapper">
                <table class="table">
                    <caption class="visually-hidden"><?= Html::e($title) ?></caption>
                    <thead>
                    <tr>
                        <th scope="col">AD-Gruppe</th>
                        <th scope="col">Mitglieder</th>
                        <th scope="col">Eingetragen</th>
                        <th scope="col">Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rules as $rule) { ?>
                        <tr>
                            <td><strong><?= Html::e((string) $rule['group_name']) ?></strong></td>
                            <td>
                                <?php if ((int) $rule['members'] === 0) { ?>
                                    <span class="badge badge--warn">keine</span>
                                <?php } else { ?>
                                    <?= (int) $rule['members'] ?>
                                <?php } ?>
                            </td>
                            <td><span class="table__hint"><?= Html::e(Dates::formatDateTime((string) $rule['created_at'])) ?><?= (string) $rule['created_by'] !== '' ? ' · ' . Html::e((string) $rule['created_by']) : '' ?></span></td>
                            <td>
                                <form method="post" action="/admin/benutzer/ad-gruppen/loeschen" class="inline-form"
                                      data-confirm="AD-Gruppe „<?= Html::e((string) $rule['group_name']) ?>“ entfernen? Ihre Mitglieder verlieren die Administratorrechte (sofern keine andere Gruppe greift).">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                    <button type="submit" class="button button--danger">Entfernen</button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($members !== []) { ?>
            <details>
                <summary><?= count($members) ?> <?= Html::e($memberHint) ?></summary>
                <ul>
                    <?php foreach ($members as $member) { ?>
                        <li>
                            <?= Html::e((string) $member['display_name']) ?>
                            <span class="table__hint">(<?= Html::e((string) $member['uid']) ?><?= (string) $member['source_label'] !== '' ? ', ' . Html::e((string) $member['source_label']) : '' ?>; über <?= Html::e(implode(', ', $member['groups'])) ?>)</span>
                        </li>
                    <?php } ?>
                </ul>
            </details>
        <?php } ?>

        <form method="post" action="/admin/benutzer/ad-gruppen" class="form">
            <?= Csrf::field() ?>
            <input type="hidden" name="target" value="<?= Html::e($target) ?>">
            <div class="field group-suggest">
                <label for="<?= Html::e($field) ?>">AD-Gruppe hinzufügen</label>
                <input type="text" id="<?= Html::e($field) ?>" name="group_name" maxlength="190" required
                       value="<?= Html::e($values[$field] ?? '') ?>" autocomplete="off" spellcheck="false"
                       data-group-suggest="/admin/ad/gruppen"<?= isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . Html::e($field) . '-error"' : '' ?>>
                <p class="field__hint">Vorschläge aus der AD-Synchronisation. Verschachtelte Mitgliedschaften zählen mit.</p>
                <?php if (isset($errors[$field])) { ?>
                    <p class="field__error" id="<?= Html::e($field) ?>-error"><?= Html::e($errors[$field]) ?></p>
                <?php } ?>
            </div>
            <div class="form__actions">
                <button type="submit" class="button button--primary">Gruppe hinzufügen</button>
            </div>
        </form>
    <?php
};
?>

<p class="card__hint" id="ad-gruppen">Zusätzlich zu den Konten oben können ganze <strong>AD-Gruppen</strong> zu Administratoren gemacht werden – für den Adminbereich des Intranets und für Nextcloud (Office).</p>
<?php if (empty($directory['available'])) { ?>
    <p class="flash flash--info">Die Datenbank-Migration für Administratoren aus AD-Gruppen steht noch aus (<code>php scripts/migrate.php</code>).</p>
<?php } else { ?>
    <?php
    $groupSection(
        'intranet',
        'Intranet-Administratoren',
        'Mitglieder dieser AD-Gruppen dürfen den gesamten Adminbereich verwalten. Sie melden sich auf der Anmeldeseite per '
            . '<strong>„Mit Windows-Anmeldung anmelden“</strong> an – ohne eigenes Konto und Passwort. Die Berechtigung wird bei jedem '
            . 'Aufruf geprüft: Wer aus der Gruppe entfernt wird (Stand der AD-Synchronisation), verliert den Zugriff sofort.'
            . ($ssoEnabled ? '' : ' <strong>Hinweis:</strong> Die Windows-Anmeldung (SSO) ist nicht aktiviert – ohne sie ist diese Anmeldung nicht möglich.'),
        $directory['intranet_rules'],
        $directory['intranet_members'],
        'Benutzer dürfen sich derzeit per Windows-Anmeldung am Adminbereich anmelden'
    );
    ?>
    </section>

    <?php
    $groupSection(
        'nextcloud',
        'Nextcloud-Administratoren',
        'Mitglieder dieser AD-Gruppen werden in Nextcloud (Office) Mitglied der Gruppe <code>admin</code>. Wer herausfällt, '
            . 'wird wieder entfernt – aber nur, wenn das Intranet ihn aufgenommen hat; bestehende Nextcloud-Administratoren bleiben '
            . 'unangetastet. Benutzer, die sich noch nie in Nextcloud angemeldet haben, erhalten die Rechte bei der ersten Anmeldung.',
        $directory['nextcloud_rules'],
        $directory['nextcloud_members'],
        'Benutzer sind derzeit Nextcloud-Administratoren'
    );
    $lastPush = $directory['nextcloud_last_push'];
    ?>
        <h3>Stand in Nextcloud</h3>
        <?php if (!$officeEnabled) { ?>
            <p class="card__hint">Office ist nicht aktiviert (<code>OFFICE_ENABLED</code>). Die Gruppen werden gespeichert und übertragen, sobald Office aktiv ist.</p>
        <?php } elseif ($lastPush === null) { ?>
            <p class="card__hint">Noch nicht übertragen.</p>
        <?php } else { ?>
            <p>
                <span class="badge <?= $lastPush['ok'] ? ($lastPush['in_sync'] ? 'badge--ok' : 'badge--warn') : 'badge--error' ?>">
                    <?= $lastPush['ok'] ? ($lastPush['in_sync'] ? 'aktuell' : 'Änderungen ausstehend') : 'fehlgeschlagen' ?>
                </span>
                <span class="table__hint">Letzte Übertragung: <?= Html::e(Dates::formatDateTime($lastPush['at'])) ?></span>
            </p>
            <p class="card__hint"><?= Html::e($lastPush['message']) ?></p>
        <?php } ?>
        <p class="card__hint">Geänderte Gruppenmitgliedschaften (AD-Synchronisation) gleicht die Office-Gesundheitsprüfung automatisch ab.</p>
        <form method="post" action="/admin/benutzer/nextcloud-uebertragen" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--ghost"<?= $officeEnabled ? '' : ' disabled' ?>>Jetzt an Nextcloud übertragen</button>
        </form>
    </section>
<?php } ?>
