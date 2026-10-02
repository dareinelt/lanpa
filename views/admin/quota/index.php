<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Office\StorageQuotaService;
use App\Support\Dates;
use App\Support\Html;

/** @var bool $officeEnabled */
/** @var int $defaultMb */
/** @var array{total:int,above:int,overrides:int,groups:int} $summary */
/** @var list<array{id:int,group_name:string,quota_mb:int,reason:string,updated_by:string,updated_at:string,members:int}> $groupRules */
/** @var list<array<string,mixed>> $aboveDefault */
/** @var list<array<string,mixed>> $belowDefault */
/** @var string $term */
/** @var list<array<string,mixed>> $results */
/** @var array{ok:bool,message:string,at:string,fingerprint:string,in_sync:bool}|null $lastPush */
/** @var list<array<string,mixed>> $recentHistory */
/** @var array<string,string> $errors */
/** @var array<string,string> $values */

$fmt = static fn (?int $mb): string => StorageQuotaService::formatMb($mb);
$value = static fn (string $key, string $fallback = ''): string => $values[$key] ?? $fallback;
$fieldError = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field__error" id="' . Html::e($key) . '-error">' . Html::e($errors[$key]) . '</p>'
    : '';
$invalid = static fn (string $key): string => isset($errors[$key])
    ? ' aria-invalid="true" aria-describedby="' . Html::e($key) . '-error"'
    : '';

require __DIR__ . '/partials.php';
?>
<p class="card__hint">
    Hier legen Sie fest, wie viel Speicherplatz jeder Benutzer in Nextcloud (Office) belegen darf.
    Es gilt: <strong>individuelles Kontingent</strong> vor <strong>AD-Gruppe</strong> (bei mehreren Gruppen das größte
    Kontingent) vor <strong>Standard</strong>. Jede Änderung wird im
    <a href="/admin/speicherplatz/verlauf">Verlauf</a> protokolliert und sofort an Nextcloud übertragen.
</p>
<?php if (!$officeEnabled) { ?>
    <p class="flash flash--info">Office ist nicht aktiviert (<code>OFFICE_ENABLED</code>). Die Kontingente werden gespeichert und übertragen, sobald Office aktiv ist.</p>
<?php } ?>

<div class="cards">
    <section class="card">
        <h2 class="card__title">Standard</h2>
        <p class="metric"><?= Html::e($fmt($defaultMb)) ?></p>
        <p class="card__hint">je Benutzer ohne Gruppenregel oder individuelles Kontingent</p>
    </section>
    <section class="card">
        <h2 class="card__title">Mehr als Standard</h2>
        <p class="metric"><?= (int) $summary['above'] ?></p>
        <p class="card__hint">von <?= (int) $summary['total'] ?> Benutzern mit Nextcloud-Kennung</p>
    </section>
    <section class="card">
        <h2 class="card__title">Regeln</h2>
        <p class="metric"><?= (int) $summary['overrides'] ?></p>
        <p class="card__hint">individuelle Kontingente, <?= (int) $summary['groups'] ?> AD-Gruppenregel(n)</p>
    </section>
</div>

<section class="card" id="standard" aria-labelledby="standard-title">
    <h2 class="card__title" id="standard-title">Standard und Übertragung an Nextcloud</h2>
    <form method="post" action="/admin/speicherplatz/standard" class="form">
        <?= Csrf::field() ?>
        <div class="field-row">
            <div class="field">
                <label for="default_quota">Standard-Kontingent je Benutzer</label>
                <input type="text" id="default_quota" name="default_quota" maxlength="20" required
                       value="<?= Html::e($value('default_quota', $fmt($defaultMb))) ?>"<?= $invalid('default_quota') ?>>
                <p class="field__hint">z. B. „500 MB“, „2 GB“ oder „1,5 GB“ (1 GB = 1024 MB). Vorgabe: 500 MB.</p>
                <?= $fieldError('default_quota') ?>
            </div>
            <div class="field">
                <label for="default_reason">Anmerkung (optional)</label>
                <input type="text" id="default_reason" name="default_reason" maxlength="1000">
            </div>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Standard speichern</button>
        </div>
    </form>

    <h3>Stand in Nextcloud</h3>
    <?php if ($lastPush === null) { ?>
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
    <p class="card__hint">
        Geänderte AD-Gruppenmitgliedschaften (AD-Synchronisation) gleicht die Office-Gesundheitsprüfung
        automatisch ab. Benutzer, die sich noch nie in Nextcloud angemeldet haben, erhalten ihr Kontingent bei der
        ersten Anmeldung.
    </p>
    <form method="post" action="/admin/speicherplatz/uebertragen" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="button button--ghost"<?= $officeEnabled ? '' : ' disabled' ?>>Jetzt an Nextcloud übertragen</button>
    </form>
</section>

<section class="card" id="gruppen" aria-labelledby="groups-title">
    <h2 class="card__title" id="groups-title">Kontingente je AD-Gruppe</h2>
    <p class="card__hint">
        Mitglieder dieser AD-Gruppen (auch verschachtelt, Stand der AD-Synchronisation) erhalten statt des Standards
        das hier festgelegte Kontingent. Ist ein Benutzer in mehreren Gruppen, gilt das größte. Ein individuelles
        Kontingent hat immer Vorrang.
    </p>
    <?php if ($groupRules === []) { ?>
        <p class="empty-state">Es sind keine Gruppenregeln angelegt – alle Benutzer ohne individuelles Kontingent erhalten den Standard.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Kontingente je AD-Gruppe</caption>
                <thead>
                <tr>
                    <th scope="col">AD-Gruppe</th>
                    <th scope="col">Kontingent</th>
                    <th scope="col">Mitglieder</th>
                    <th scope="col">Geändert</th>
                    <th scope="col">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($groupRules as $rule) { ?>
                    <tr>
                        <td>
                            <?= Html::e($rule['group_name']) ?>
                            <?php if ($rule['reason'] !== '') { ?>
                                <div class="table__hint"><?= Html::e($rule['reason']) ?></div>
                            <?php } ?>
                        </td>
                        <td><?= Html::e($fmt($rule['quota_mb'])) ?></td>
                        <td>
                            <?php if ($rule['members'] === 0) { ?>
                                <span class="badge badge--warn">keine</span>
                            <?php } else { ?>
                                <?= (int) $rule['members'] ?>
                            <?php } ?>
                        </td>
                        <td><span class="table__hint"><?= Html::e(Dates::formatDateTime($rule['updated_at'])) ?><?= $rule['updated_by'] !== '' ? ' · ' . Html::e($rule['updated_by']) : '' ?></span></td>
                        <td>
                            <form method="post" action="/admin/speicherplatz/gruppen/loeschen" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                                <button type="submit" class="button button--danger"
                                        data-confirm="Regel für „<?= Html::e($rule['group_name']) ?>“ entfernen? Die Mitglieder erhalten wieder den Standard (sofern keine andere Regel greift).">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <form method="post" action="/admin/speicherplatz/gruppen" class="form">
        <?= Csrf::field() ?>
        <fieldset class="fieldset">
            <legend>Gruppenregel anlegen oder ändern</legend>
            <div class="field-row">
                <div class="field group-suggest">
                    <label for="group_name">AD-Gruppe</label>
                    <input type="text" id="group_name" name="group_name" maxlength="190" required
                           value="<?= Html::e($value('group_name')) ?>" autocomplete="off" spellcheck="false"
                           data-group-suggest="/admin/ad/gruppen"<?= $invalid('group_name') ?>>
                    <p class="field__hint">Vorschläge aus der AD-Synchronisation. Eine bestehende Regel für dieselbe Gruppe wird überschrieben.</p>
                    <?= $fieldError('group_name') ?>
                </div>
                <div class="field">
                    <label for="group_quota">Kontingent</label>
                    <input type="text" id="group_quota" name="group_quota" maxlength="20" required placeholder="z. B. 2 GB"
                           value="<?= Html::e($value('group_quota')) ?>"<?= $invalid('group_quota') ?>>
                    <?= $fieldError('group_quota') ?>
                </div>
            </div>
            <div class="field">
                <label for="group_reason">Begründung (optional)</label>
                <input type="text" id="group_reason" name="group_reason" maxlength="1000" value="<?= Html::e($value('group_reason')) ?>">
            </div>
        </fieldset>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Gruppenregel speichern</button>
        </div>
    </form>
</section>

<section class="card" id="benutzer" aria-labelledby="users-title">
    <h2 class="card__title" id="users-title">Individuelles Kontingent vergeben</h2>
    <p class="card__hint">Benutzer suchen (Name, Benutzername oder Abteilung) und das Kontingent mit Begründung anpassen.</p>
    <form method="get" action="/admin/speicherplatz#benutzer" class="form" role="search">
        <div class="field">
            <label for="suche">Benutzer suchen</label>
            <input type="search" id="suche" name="suche" maxlength="100" value="<?= Html::e($term) ?>">
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Suchen</button>
        </div>
    </form>
    <?php if ($term !== '') { ?>
        <?php if ($results === []) { ?>
            <p class="empty-state">Kein aktiver Benutzer mit Nextcloud-Kennung gefunden.</p>
        <?php } else {
            $quotaTable($results, 'Suchergebnis', true);
        } ?>
    <?php } ?>
</section>

<section class="card" id="uebersicht" aria-labelledby="above-title">
    <h2 class="card__title" id="above-title">Benutzer mit mehr als dem Standard (<?= Html::e($fmt($defaultMb)) ?>)</h2>
    <?php if ($aboveDefault === []) { ?>
        <p class="empty-state">Kein Benutzer besitzt mehr als den Standard.</p>
    <?php } else {
        $quotaTable($aboveDefault, 'Benutzer mit mehr als dem Standard', false);
    } ?>

    <?php if ($belowDefault !== []) { ?>
        <h3>Individuelle Kontingente bis zum Standard</h3>
        <?php $quotaTable($belowDefault, 'Individuelle Kontingente bis zum Standard', false); ?>
    <?php } ?>
</section>

<section class="card" id="verlauf" aria-labelledby="history-title">
    <h2 class="card__title" id="history-title">Letzte Änderungen</h2>
    <?php if ($recentHistory === []) { ?>
        <p class="empty-state">Noch keine Änderungen.</p>
    <?php } else {
        $historyTable($recentHistory);
    } ?>
    <div class="form__actions">
        <a class="button button--ghost" href="/admin/speicherplatz/verlauf">Gesamten Verlauf anzeigen</a>
    </div>
</section>
