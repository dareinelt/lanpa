<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Office\StorageQuotaService;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,mixed> $user */
/** @var int $defaultMb */
/** @var list<array<string,mixed>> $history */
/** @var array<string,string> $errors */
/** @var array<string,string> $values */

$fmt = static fn (?int $mb): string => StorageQuotaService::formatMb($mb);
$override = is_array($user['override'] ?? null) ? $user['override'] : null;
$fieldError = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field__error" id="' . Html::e($key) . '-error">' . Html::e($errors[$key]) . '</p>'
    : '';
$invalid = static fn (string $key): string => isset($errors[$key])
    ? ' aria-invalid="true" aria-describedby="' . Html::e($key) . '-error"'
    : '';
$groups = is_array($user['groups'] ?? null) ? $user['groups'] : [];

require __DIR__ . '/partials.php';
?>
<p><a href="/admin/speicherplatz#benutzer">← Zurück zur Übersicht</a></p>

<section class="card" aria-labelledby="user-title">
    <h2 class="card__title" id="user-title"><?= Html::e((string) $user['display_name']) ?></h2>
    <dl class="cert-dl">
        <dt>Nextcloud-Kennung</dt>
        <dd><code><?= Html::e((string) $user['uid']) ?></code></dd>
        <?php if ((string) $user['department'] !== '') { ?>
            <dt>Abteilung</dt>
            <dd><?= Html::e((string) $user['department']) ?></dd>
        <?php } ?>
        <?php if ((string) $user['source_label'] !== '') { ?>
            <dt>Verzeichnis</dt>
            <dd><?= Html::e((string) $user['source_label']) ?></dd>
        <?php } ?>
        <dt>Aktuelles Kontingent</dt>
        <dd><strong><?= Html::e($fmt((int) $user['effective_mb'])) ?></strong>
            (<?= $override !== null ? 'individuell' : ($user['origin'] === 'group' ? 'AD-Gruppe „' . Html::e((string) $user['group_name']) . '“' : 'Standard') ?>)
        </dd>
        <dt>Ohne individuelles Kontingent</dt>
        <dd>
            <?= Html::e($fmt((int) $user['base_mb'])) ?>
            <?= $user['group_name'] !== '' ? '(AD-Gruppe „' . Html::e((string) $user['group_name']) . '“)' : '(Standard ' . Html::e($fmt($defaultMb)) . ')' ?>
        </dd>
        <?php if ($groups !== []) { ?>
            <dt>Gruppen mit Kontingentregel</dt>
            <dd><?= Html::e(implode(', ', array_map('strval', $groups))) ?></dd>
        <?php } ?>
    </dl>
</section>

<section class="card" aria-labelledby="override-title">
    <h2 class="card__title" id="override-title"><?= $override === null ? 'Individuelles Kontingent vergeben' : 'Individuelles Kontingent ändern' ?></h2>
    <?php if ($override !== null) { ?>
        <p class="card__hint">
            Aktuell <?= Html::e($fmt((int) $override['quota_mb'])) ?> – vergeben von
            <?= Html::e((string) $override['created_by']) ?> am <?= Html::e(Dates::formatDateTime((string) $override['created_at'])) ?><?php if ((string) $override['updated_by'] !== '' && (string) $override['updated_at'] !== (string) $override['created_at']) { ?>,
                zuletzt geändert von <?= Html::e((string) $override['updated_by']) ?> am <?= Html::e(Dates::formatDateTime((string) $override['updated_at'])) ?><?php } ?>.
            Begründung: „<?= Html::e((string) $override['reason']) ?>“
        </p>
    <?php } ?>
    <form method="post" action="/admin/speicherplatz/benutzer" class="form">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
        <div class="field">
            <label for="quota">Kontingent</label>
            <input type="text" id="quota" name="quota" maxlength="20" required placeholder="z. B. 2 GB"
                   value="<?= Html::e($values['quota'] ?? ($override !== null ? $fmt((int) $override['quota_mb']) : '')) ?>"<?= $invalid('quota') ?>>
            <p class="field__hint">z. B. „1 GB“, „1,5 GB“ oder „750 MB“. Das individuelle Kontingent hat Vorrang vor Gruppe und Standard.</p>
            <?= $fieldError('quota') ?>
            <?= $fieldError('user') ?>
        </div>
        <div class="field">
            <label for="reason">Begründung <span aria-hidden="true">*</span></label>
            <textarea id="reason" name="reason" rows="3" maxlength="1000" required minlength="5"<?= $invalid('reason') ?>><?= Html::e($values['reason'] ?? '') ?></textarea>
            <p class="field__hint">Pflichtfeld – wird mit Ihrem Benutzernamen im Verlauf gespeichert.</p>
            <?= $fieldError('reason') ?>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<?php if ($override !== null) { ?>
    <section class="card" aria-labelledby="remove-title">
        <h2 class="card__title" id="remove-title">Individuelles Kontingent entfernen</h2>
        <p class="card__hint">Danach gilt wieder <?= Html::e($fmt((int) $user['base_mb'])) ?> (Gruppe bzw. Standard).</p>
        <form method="post" action="/admin/speicherplatz/benutzer/entfernen" class="form">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
            <input type="hidden" name="uid" value="<?= Html::e((string) $user['uid']) ?>">
            <div class="field">
                <label for="remove_reason">Begründung <span aria-hidden="true">*</span></label>
                <input type="text" id="remove_reason" name="remove_reason" maxlength="1000" required minlength="5"
                       value="<?= Html::e($values['remove_reason'] ?? '') ?>"<?= $invalid('remove_reason') ?>>
                <?= $fieldError('remove_reason') ?>
            </div>
            <div class="form__actions">
                <button type="submit" class="button button--danger" data-confirm="Individuelles Kontingent entfernen?">Entfernen</button>
            </div>
        </form>
    </section>
<?php } ?>

<section class="card" aria-labelledby="user-history-title">
    <h2 class="card__title" id="user-history-title">Verlauf</h2>
    <?php if ($history === []) { ?>
        <p class="empty-state">Für diesen Benutzer wurden noch keine individuellen Kontingente vergeben.</p>
    <?php } else {
        $historyTable($history);
    } ?>
</section>
