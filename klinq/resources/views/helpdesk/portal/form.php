<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'portal';
$areaLabel = 'Portal';
$scripts = ['/js/helpdesk.js'];
$val = static fn (string $key, string $default = ''): string => form_value(null, $key, $prefill[$key] ?? $default);
?>
<div class="page-header"><div><h1 class="page-title">Neue Anfrage</h1><p class="page-subtitle text-muted">Beschreiben Sie Ihr Anliegen so konkret wie möglich.</p></div><div class="page-actions"><a class="btn btn-ghost" href="/portal"><?= icon('arrow-left') ?> Zurück</a></div></div>
<?php if (!$hasEmployee): ?><div class="alert alert-warning"><?= icon('warning') ?> Ihrem Benutzer ist kein Mitarbeiterdatensatz zugeordnet. Die Anfrage wird trotzdem angelegt; Rückfragen laufen über Ihr Benutzerkonto.</div><?php endif; ?>
<div class="card card-form card-form-wide">
    <form method="post" action="/portal/tickets" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group"><label for="f-template">Vorlage</label><select id="f-template" name="template_id" data-hd-template="/portal/tickets/new"><option value="">– ohne Vorlage –</option><?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($templateId ?? $val('template_id'), $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group<?= has_error('ticket_type_id') ? ' has-error' : '' ?>"><label for="f-type">Art</label><select id="f-type" name="ticket_type_id"><option value="">Automatisch</option><?php foreach ($types as $type): ?><option value="<?= (int) $type['id'] ?>"<?= selected($val('ticket_type_id'), $type['id']) ?>><?= e($type['name']) ?></option><?php endforeach; ?></select><?= field_error('ticket_type_id') ?></div>
        </div>
        <div class="form-group<?= has_error('subject') ? ' has-error' : '' ?>"><label for="f-subject">Betreff <span class="required">*</span></label><input id="f-subject" name="subject" value="<?= e($val('subject')) ?>" maxlength="255" required autofocus data-hd-kb-suggest="#kb-suggest" placeholder="z. B. VPN funktioniert nicht"><div id="kb-suggest" class="kb-suggest" hidden></div><?= field_error('subject') ?></div>
        <div class="form-group<?= has_error('description') ? ' has-error' : '' ?>"><label for="f-description">Beschreibung <span class="required">*</span></label><textarea id="f-description" name="description" rows="8" required placeholder="Was ist passiert? Seit wann? Welche Fehlermeldung sehen Sie?"><?= e($val('description')) ?></textarea><?= field_error('description') ?></div>
        <div class="form-row">
            <div class="form-group<?= has_error('category_id') ? ' has-error' : '' ?>"><label for="f-category">Kategorie</label><select id="f-category" name="category_id" data-hd-category="#f-subcategory"><option value="">– bitte wählen –</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($val('category_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?= field_error('category_id') ?></div>
            <div class="form-group<?= has_error('subcategory_id') ? ' has-error' : '' ?>"><label for="f-subcategory">Unterkategorie</label><select id="f-subcategory" name="subcategory_id" data-selected="<?= e($val('subcategory_id')) ?>"><option value="">– keine Unterkategorie –</option><?php foreach ($subcategories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($val('subcategory_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?= field_error('subcategory_id') ?></div>
        </div>
        <?php if ($myAssets !== []): ?><div class="form-group<?= has_error('asset_id') ? ' has-error' : '' ?>"><label for="f-asset">Betroffenes Asset</label><select id="f-asset" name="asset_id"><option value="">– kein Asset –</option><?php foreach ($myAssets as $asset): ?><option value="<?= (int) $asset['id'] ?>"<?= selected($val('asset_id'), $asset['id']) ?>><?= e($asset['inventory_number'] ?? '') ?> · <?= e($asset['name'] ?? $asset['article_name'] ?? 'Asset') ?></option><?php endforeach; ?></select><?= field_error('asset_id') ?></div><?php endif; ?>
        <div class="form-row">
            <div class="form-group<?= has_error('impact') ? ' has-error' : '' ?>"><label>Auswirkung</label><div class="impact-grid"><?php foreach ($impactLabels as $key => $label): ?><label><input type="radio" name="impact" value="<?= (int) $key ?>"<?= checked($val('impact', '2') === (string) $key) ?>> <?= e($label) ?></label><?php endforeach; ?></div><?= field_error('impact') ?></div>
            <div class="form-group<?= has_error('urgency') ? ' has-error' : '' ?>"><label>Dringlichkeit</label><div class="impact-grid"><?php foreach ($urgencyLabels as $key => $label): ?><label><input type="radio" name="urgency" value="<?= (int) $key ?>"<?= checked($val('urgency', '2') === (string) $key) ?>> <?= e($label) ?></label><?php endforeach; ?></div><?= field_error('urgency') ?></div>
        </div>
        <div class="form-group<?= has_error('file') ? ' has-error' : '' ?>"><label for="f-file">Anhang</label><input id="f-file" type="file" name="file"><p class="form-hint">Optional: Screenshot oder Datei zur Fehlermeldung.</p><?= field_error('file') ?></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?> Anfrage absenden</button><a class="btn btn-ghost" href="/portal">Abbrechen</a></div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
