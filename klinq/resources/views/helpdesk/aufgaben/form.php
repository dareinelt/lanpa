<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$scripts = ['/js/helpdesk-media.js'];
$val = static fn (string $key, string $default = ''): string => form_value(null, $key, $default);
$oldPriority = $val('priority_id');
$defaultPriority = $oldPriority !== '' ? $oldPriority : ($defaultPriorityId !== null ? (string) $defaultPriorityId : '');
?>
<div class="page-header">
    <div>
        <h1>Neue Aufgabe</h1>
        <p class="page-subtitle text-muted mb-0">Aufgabe ohne Ticketbezug anlegen</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/helpdesk/aufgaben"><?= icon('arrow-left') ?> Zurück</a>
    </div>
</div>

<div class="card card-form card-form-wide">
    <form method="post" action="/helpdesk/aufgaben" novalidate>
        <?= csrf_field() ?>
        <div class="form-group<?= has_error('subject') ? ' has-error' : '' ?>">
            <label for="f-subject">Betreff <span class="required">*</span></label>
            <input id="f-subject" name="subject" value="<?= e($val('subject')) ?>" maxlength="255" required autofocus>
            <?= field_error('subject') ?>
        </div>
        <div class="form-group<?= has_error('description') ? ' has-error' : '' ?>">
            <label for="f-description">Beschreibung</label>
            <textarea id="f-description" name="description" rows="10" maxlength="2000000" data-hd-media-images data-hd-media-preview="#f-description-preview"><?= e($val('description')) ?></textarea>
            <div class="hd-media-preview" id="f-description-preview" hidden></div>
            <p class="form-hint">Screenshots lassen sich mit STRG+V direkt aus der Zwischenablage einfügen.</p>
            <?= field_error('description') ?>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('category_id') ? ' has-error' : '' ?>">
                <label for="f-category">Kategorie</label>
                <select id="f-category" name="category_id">
                    <option value="">– keine –</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= selected($val('category_id'), $c['id']) ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('category_id') ?>
            </div>
            <div class="form-group<?= has_error('priority_id') ? ' has-error' : '' ?>">
                <label for="f-priority">Priorität</label>
                <select id="f-priority" name="priority_id">
                    <?php foreach ($priorities as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"<?= selected($defaultPriority, $p['id']) ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('priority_id') ?>
            </div>
            <div class="form-group<?= has_error('due_at') ? ' has-error' : '' ?>">
                <label for="f-due">Fällig <span class="text-muted">(optional)</span></label>
                <input type="date" id="f-due" name="due_at" value="<?= e($val('due_at')) ?>">
                <?= field_error('due_at') ?>
            </div>
        </div>
        <p class="form-hint">Die Aufgabe erscheint anschließend unter „Alle Aufgaben“ und kann dort zugewiesen und bearbeitet werden.</p>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Aufgabe anlegen</button>
            <a class="btn btn-ghost" href="/helpdesk/aufgaben">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
