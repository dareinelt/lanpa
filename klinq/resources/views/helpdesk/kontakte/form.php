<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
use App\Services\Helpdesk\HelpdeskContactService;

$isNew = $contact === null;
$v = static fn (string $key): string => (string) ($values[$key] ?? '');
$field = static function (string $name, string $label, string $type = 'text', array $attrs = []) use ($v): string {
    $html = '<div class="form-group' . (has_error($name) ? ' has-error' : '') . '">';
    $html .= '<label for="ct-' . e($name) . '">' . $label . '</label>';
    $extra = '';
    foreach ($attrs as $k => $val) {
        $extra .= ' ' . e($k) . ($val === true ? '' : '="' . e((string) $val) . '"');
    }
    $html .= '<input id="ct-' . e($name) . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($v($name)) . '"' . $extra . '>';

    return $html . field_error($name) . '</div>';
};
$required = ' <span class="required">*</span>';
?>
<div class="page-header">
    <div>
        <h1><?= $isNew ? 'Neuer Kontakt' : e(trim($contact['first_name'] . ' ' . $contact['last_name'])) ?></h1>
        <p class="page-subtitle text-muted mb-0">
            <?php if ($isNew): ?>
                Kontakt im gemeinsamen Adressbuch des Help Desk eintragen
            <?php else: ?>
                <?= e($contact['company']) ?> · eingetragen von <?= e($contact['created_by_name'] ?? 'system') ?>
                am <?= e(fmt_datetime($contact['created_at'] ?? null)) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/helpdesk/kontakte"><?= icon('arrow-left') ?> Zum Kontaktverzeichnis</a>
    </div>
</div>

<div class="card card-form card-form-wide">
    <?php if ($canEdit): ?>
    <form method="post" action="<?= $isNew ? '/helpdesk/kontakte' : '/helpdesk/kontakte/' . (int) $contact['id'] ?>" novalidate>
        <?= csrf_field() ?>
        <?= $field('company', 'Firma' . $required, 'text', ['maxlength' => 200, 'required' => true, 'autocomplete' => 'organization']) ?>
        <div class="form-row">
            <?= $field('first_name', 'Vorname' . $required, 'text', ['maxlength' => 100, 'required' => true, 'autocomplete' => 'off']) ?>
            <?= $field('last_name', 'Name' . $required, 'text', ['maxlength' => 100, 'required' => true, 'autocomplete' => 'off']) ?>
        </div>
        <fieldset class="hd-contacts-reach<?= has_error('reach') ? ' has-error' : '' ?>">
            <legend>Erreichbarkeit <span class="text-muted text-sm">– mindestens eine Rufnummer oder E-Mail-Adresse</span></legend>
            <div class="form-row form-row-3">
                <?= $field('phone', 'Telefon', 'tel', ['maxlength' => 50, 'placeholder' => 'z. B. 0531 123456-0']) ?>
                <?= $field('mobile', 'Mobil', 'tel', ['maxlength' => 50, 'placeholder' => 'z. B. 0171 1234567']) ?>
                <?= $field('email', 'E-Mail', 'email', ['maxlength' => 255, 'placeholder' => 'name@firma.de']) ?>
            </div>
            <?= field_error('reach') ?>
        </fieldset>
        <div class="form-group<?= has_error('tags') ? ' has-error' : '' ?>">
            <label for="ct-tags">Stichworte <span class="text-muted">(optional, durch Komma getrennt)</span></label>
            <input id="ct-tags" name="tags" value="<?= e($v('tags')) ?>" list="ct-tags-list" placeholder="z. B. Drucker, Hotline, Wartungsvertrag" autocomplete="off" data-hd-contact-tags>
            <datalist id="ct-tags-list" data-hd-contact-tag-source="<?= e(json_encode(array_column($allTags, 'tag'), JSON_UNESCAPED_UNICODE)) ?>"></datalist>
            <?= field_error('tags') ?>
        </div>
        <div class="form-group<?= has_error('notes') ? ' has-error' : '' ?>">
            <label for="ct-notes">Bemerkung <span class="text-muted">(optional)</span></label>
            <textarea id="ct-notes" name="notes" rows="3" maxlength="2000" placeholder="z. B. Kundennummer, Servicezeiten, Zuständigkeit"><?= e($v('notes')) ?></textarea>
            <?= field_error('notes') ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isNew ? 'Kontakt anlegen' : 'Speichern' ?></button>
            <a class="btn btn-secondary" href="/helpdesk/kontakte">Abbrechen</a>
        </div>
    </form>
    <?php else: ?>
        <dl class="hd-contacts-readonly">
            <dt>Firma</dt><dd><?= e($contact['company']) ?></dd>
            <dt>Name</dt><dd><?= e(HelpdeskContactService::label($contact)) ?></dd>
            <?php foreach (['phone' => 'Telefon', 'mobile' => 'Mobil', 'email' => 'E-Mail', 'notes' => 'Bemerkung'] as $key => $label): ?>
                <?php if (!empty($contact[$key])): ?><dt><?= e($label) ?></dt><dd class="pre-wrap"><?= e($contact[$key]) ?></dd><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($contact['tags'] !== []): ?><dt>Stichworte</dt><dd><?= e(implode(', ', $contact['tags'])) ?></dd><?php endif; ?>
        </dl>
    <?php endif; ?>
</div>

<?php if (!$isNew && $canEdit): ?>
<form method="post" action="/helpdesk/kontakte/<?= (int) $contact['id'] ?>/loeschen" class="hd-contacts-delete" data-confirm="Diesen Kontakt wirklich aus dem Kontaktverzeichnis löschen?">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash') ?> Kontakt löschen</button>
</form>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
