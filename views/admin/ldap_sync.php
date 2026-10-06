<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var bool $ldapExtensionAvailable */
/** @var list<array<string,mixed>> $syncRuns */
/** @var int $phonebookCount */
/** @var int|null $groupCount */
?>
<?php if (!$ldapExtensionAvailable) { ?>
    <p class="flash flash--error">Die PHP-Erweiterung <code>ldap</code> ist nicht installiert. Eine Synchronisation ist nicht möglich.</p>
<?php } ?>

<section class="card">
    <h2 class="card__title">Manuelle Synchronisation</h2>
    <p class="card__hint">
        Aktive Telefonbucheinträge: <?= (int) $phonebookCount ?>
        <?php if (($groupCount ?? null) !== null) { ?> · AD-Gruppen für die Rechtevergabe: <?= (int) $groupCount ?><?php } ?>
    </p>

    <form method="post" action="/admin/ad/sync" class="inline-form" data-ad-sync>
        <?= Csrf::field() ?>
        <button type="submit" class="button button--primary" <?= $ldapExtensionAvailable ? '' : 'disabled' ?>>
            Manuelle Synchronisation starten
        </button>
    </form>

    <?php require __DIR__ . '/ldap/sync-dialog.php'; ?>
</section>

<form method="post" action="/admin/ad/synchronisation" class="form form--wide">
    <?= Csrf::field() ?>

    <fieldset class="fieldset">
        <legend>Automatische Synchronisation</legend>
        <div class="field">
            <label for="ldap_sync_interval">Synchronisationsintervall (Sekunden)</label>
            <input type="number" id="ldap_sync_interval" name="ldap_sync_interval" min="60" max="86400"
                   value="<?= Html::e($values['ldap_sync_interval']) ?>"
                   <?= isset($errors['ldap_sync_interval']) ? 'aria-invalid="true" aria-describedby="ldap_sync_interval-error"' : '' ?>>
            <p class="field__hint">Wird vom Synchronisationsdienst (Container <code>sync</code>) ausgewertet.</p>
            <?php if (isset($errors['ldap_sync_interval'])) { ?><p class="field__error" id="ldap_sync_interval-error"><?= Html::e($errors['ldap_sync_interval']) ?></p><?php } ?>
        </div>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Intervall speichern</button>
    </div>
</form>

<section class="card">
    <h2 class="card__title">Protokoll</h2>
    <?php if ($syncRuns === []) { ?>
        <p class="card__hint">Es wurde noch keine Synchronisation ausgeführt.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Start</th>
                    <th scope="col">Ende</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aktualisiert</th>
                    <th scope="col">Deaktiviert</th>
                    <th scope="col">Meldung</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($syncRuns as $run) { ?>
                    <tr>
                        <td><?= Html::e(Dates::formatDateTime((string) $run['started_at'])) ?></td>
                        <td><?= $run['finished_at'] === null ? '–' : Html::e(Dates::formatDateTime((string) $run['finished_at'])) ?></td>
                        <td>
                            <span class="badge <?= (string) $run['status'] === 'success' ? 'badge--ok' : 'badge--warn' ?>">
                                <?= Html::e((string) $run['status']) ?>
                            </span>
                        </td>
                        <td><?= (int) $run['processed'] ?></td>
                        <td><?= (int) $run['deactivated'] ?></td>
                        <td class="table__hint"><?= Html::e((string) ($run['message'] ?? '')) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
