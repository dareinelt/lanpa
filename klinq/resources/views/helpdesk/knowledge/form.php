<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk-knowledge';
$areaLabel = 'Help Desk';
$scripts = ['/js/helpdesk.js'];
$isNew = $article === null;
$back = $isNew ? '/helpdesk/knowledge' : '/helpdesk/knowledge/' . (int) $article['id'];
$val = static fn (string $key, string $default = ''): string => form_value($article, $key, $prefill[$key] ?? $default);
$tagValue = is_array($tags) ? implode(', ', array_map(static fn (mixed $t): string => is_array($t) ? (string) ($t['name'] ?? '') : (string) $t, $tags)) : (string) $tags;
?>
<div class="page-header"><div><h1 class="page-title"><?= e($title) ?></h1></div><div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div></div>
<div class="card card-form card-form-wide">
    <form method="post" action="<?= e($isNew ? '/helpdesk/knowledge' : '/helpdesk/knowledge/' . (int) $article['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($sourceTicketId !== null): ?><input type="hidden" name="source_ticket_id" value="<?= (int) $sourceTicketId ?>"><?php endif; ?>
        <div class="form-group<?= has_error('title') ? ' has-error' : '' ?>"><label for="f-title">Titel <span class="required">*</span></label><input id="f-title" name="title" value="<?= e($val('title')) ?>" maxlength="255" required autofocus><?= field_error('title') ?></div>
        <div class="form-group<?= has_error('summary') ? ' has-error' : '' ?>"><label for="f-summary">Kurzfassung</label><textarea id="f-summary" name="summary" rows="3" maxlength="500"><?= e($val('summary')) ?></textarea><?= field_error('summary') ?></div>
        <div class="form-group<?= has_error('body') ? ' has-error' : '' ?>"><label for="f-body">Inhalt <span class="required">*</span></label><textarea id="f-body" name="body" rows="14" required><?= e($val('body')) ?></textarea><?= field_error('body') ?></div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('category_id') ? ' has-error' : '' ?>"><label for="f-category">Kategorie</label><select id="f-category" name="category_id"><option value="">– keine –</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($val('category_id'), $c['id']) ?>><?= e(($c['parent_name'] ?? null) ? $c['parent_name'] . ' / ' . $c['name'] : $c['name']) ?></option><?php endforeach; ?></select><?= field_error('category_id') ?></div>
            <div class="form-group<?= has_error('status') ? ' has-error' : '' ?>"><label for="f-status">Status</label><select id="f-status" name="status"><?php foreach ($statuses as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($val('status', 'draft'), $key) ?>><?= e($label) ?></option><?php endforeach; ?></select><?= field_error('status') ?></div>
            <div class="form-group<?= has_error('visibility') ? ' has-error' : '' ?>"><label for="f-visibility">Sichtbarkeit</label><select id="f-visibility" name="visibility"><?php foreach ($visibilities as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($val('visibility', 'internal'), $key) ?>><?= e($label) ?></option><?php endforeach; ?></select><?= field_error('visibility') ?></div>
        </div>
        <div class="form-row">
            <div class="form-group<?= has_error('tags') ? ' has-error' : '' ?>"><label for="f-tags">Tags</label><input id="f-tags" name="tags" value="<?= e(form_value(null, 'tags', $prefill['tags'] ?? $tagValue)) ?>" maxlength="500" list="tag-suggestions" data-hd-tags><datalist id="tag-suggestions"></datalist><p class="form-hint">Mehrere Tags mit Komma trennen.</p><?= field_error('tags') ?></div>
            <div class="form-group<?= has_error('slug') ? ' has-error' : '' ?>"><label for="f-slug">Kurzlink (optional)</label><input id="f-slug" name="slug" value="<?= e($val('slug')) ?>" maxlength="200" class="mono"><?= field_error('slug') ?></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isNew ? 'Artikel anlegen' : 'Speichern' ?></button><a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a></div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
