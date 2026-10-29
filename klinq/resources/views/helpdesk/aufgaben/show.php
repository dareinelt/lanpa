<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$id = (int) $task['id'];
$final = in_array((string) $task['status'], \App\Services\Helpdesk\TaskService::FINAL, true);
$unassigned = $task['assignee_user_id'] === null;
$overdue = !$final && $task['due_at'] !== null && strtotime((string) $task['due_at']) < time();
$canClaim = $can('helpdesk.tasks') && !$final && $unassigned;
$canChangeStatus = ($isMine || $canAssign) && !$final;
$eventLabels = [
    'created' => 'Aufgabe angelegt',
    'converted_from_ticket' => 'Aus Ticket umgewandelt',
    'claimed' => 'Angenommen',
    'assigned' => 'Zugewiesen',
    'unassigned' => 'Zuweisung entfernt',
    'status_changed' => 'Status geändert',
    'category_changed' => 'Kategorie geändert',
];
$eventClass = static fn (string $t): string => match ($t) {
    'status_changed', 'category_changed' => ' is-warning',
    'converted_from_ticket' => ' is-success',
    default => '',
};
?>
<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="/helpdesk/aufgaben">Aufgaben</a> › <span class="ticket-number"><?= e($task['number']) ?></span></div>
        <h1><?= e($task['subject']) ?></h1>
        <div class="ticket-header-badges">
            <?php if ($task['category_name'] !== null): ?>
                <span class="badge badge-neutral"><?= icon('tag', 'icon icon-xs') ?> <?= e($task['category_name']) ?></span>
            <?php endif; ?>
            <?= badge($statuses[$task['status']] ?? $task['status'], $statusColors[$task['status']] ?? 'neutral') ?>
            <?php if ($task['priority_name'] !== null): ?>
                <span class="badge is-<?= e($task['priority_color']) ?>"><span class="prio-dot"></span><?= e($task['priority_name']) ?></span>
            <?php endif; ?>
            <?php if ($unassigned): ?>
                <span class="badge badge-neutral">Nicht zugewiesen</span>
            <?php else: ?>
                <span class="badge badge-info"><?= icon('user', 'icon icon-xs') ?> <?= e($task['assignee_name']) ?></span>
            <?php endif; ?>
            <?php if ($overdue): ?><span class="badge badge-danger"><?= icon('clock', 'icon icon-xs') ?> Fällig <?= fmt_date($task['due_at']) ?></span><?php endif; ?>
            <span class="text-muted text-sm">erstellt <?= fmt_datetime($task['created_at']) ?> von <?= e($task['created_by_name'] ?? 'system') ?></span>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($canClaim): ?>
            <form method="post" action="/helpdesk/aufgaben/<?= $id ?>/annehmen" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?> Annehmen</button></form>
        <?php endif; ?>
        <?php if ($canAssign && !$final): ?>
            <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="assign-dialog"><?= icon('user') ?> Zuweisen</button>
        <?php endif; ?>
        <?php if ($task['source_ticket_id'] !== null): ?>
            <a class="btn btn-ghost btn-sm" href="/helpdesk/tickets/<?= (int) $task['source_ticket_id'] ?>"><?= icon('ticket') ?> Ticket</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($task['source_ticket_id'] !== null): ?>
    <div class="alert alert-info"><?= icon('info') ?> Diese Aufgabe entstand aus Ticket <a href="/helpdesk/tickets/<?= (int) $task['source_ticket_id'] ?>"><?= e($task['source_ticket_number']) ?></a> und ist damit verknüpft.</div>
<?php endif; ?>

<div class="ticket-layout">
<div class="ticket-main">

    <section class="card">
        <h2 class="card-title">Beschreibung</h2>
        <div class="ticket-description"><?= nl2br_e($task['description']) ?></div>
    </section>

    <section class="card" id="kommentare">
        <h2 class="card-title">Kommentare <span class="text-muted">(<?= count($comments) ?>)</span></h2>
        <?php if ($comments === []): ?>
            <p class="empty-state">Noch keine Kommentare.</p>
        <?php else: ?>
        <ol class="comment-list" data-hd-pin-list>
            <?php foreach ($comments as $c): ?>
            <?php $pinned = (int) ($c['is_pinned'] ?? 0) === 1; ?>
            <li class="comment<?= $pinned ? ' is-pinned' : '' ?>" data-hd-pin-item data-hd-id="<?= (int) $c['id'] ?>" data-hd-created="<?= e((string) $c['created_at']) ?>" id="kommentar-<?= (int) $c['id'] ?>">
                <?php if ($can('helpdesk.tasks')): ?>
                <form method="post" action="/helpdesk/aufgaben/<?= $id ?>/kommentare/<?= (int) $c['id'] ?>/pin" class="comment-pin-form" data-hd-pin-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="pinned" value="<?= $pinned ? '0' : '1' ?>">
                    <button type="submit" class="comment-pin<?= $pinned ? ' is-active' : '' ?>" title="<?= $pinned ? 'Anheftung lösen' : 'Oben anheften' ?>" aria-label="<?= $pinned ? 'Anheftung lösen' : 'Oben anheften' ?>" aria-pressed="<?= $pinned ? 'true' : 'false' ?>"><?= icon('pin') ?></button>
                </form>
                <?php endif; ?>
                <div class="comment-head">
                    <span class="comment-author"><?= e($c['author_name']) ?></span>
                    <?php if ($pinned): ?><span class="badge badge-info" data-hd-pin-badge><?= icon('pin', 'icon icon-xs') ?> Angeheftet</span><?php endif; ?>
                    <span class="text-muted text-xs"><?= fmt_datetime($c['created_at']) ?></span>
                </div>
                <div class="comment-body"><?= rich_text($c['body']) ?></div>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>

        <?php if ($can('helpdesk.tasks')): ?>
        <form method="post" action="/helpdesk/aufgaben/<?= $id ?>/kommentare" class="comment-form">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="task-comment-body" class="sr-only">Kommentar</label>
                <textarea id="task-comment-body" name="body" rows="3" maxlength="2000000" placeholder="Kommentar eingeben …" required><?= e(old('body')) ?></textarea>
                <?= field_error('body') ?>
                <p class="form-hint">Kommentare erscheinen chronologisch; angeheftete Einträge stehen oben.</p>
            </div>
            <div class="comment-form-footer">
                <span></span>
                <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?> Kommentar speichern</button>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <section class="card" id="history">
        <h2 class="card-title">Verlauf</h2>
        <?php if ($events === []): ?>
            <p class="empty-state">Kein Verlauf.</p>
        <?php else: ?>
        <ul class="timeline">
            <?php foreach (array_reverse($events) as $ev): ?>
            <li class="<?= trim($eventClass((string) $ev['type'])) ?>">
                <span class="timeline-dot"></span>
                <span class="timeline-time"><?= fmt_datetime($ev['created_at']) ?> · <?= e($ev['user_name']) ?></span>
                <span class="timeline-title"><?= e($eventLabels[$ev['type']] ?? ucfirst(str_replace('_', ' ', (string) $ev['type']))) ?></span>
                <?php if ($ev['old_value'] !== null || $ev['new_value'] !== null): ?>
                    <span class="timeline-detail">
                        <?php if ($ev['old_value'] !== null && $ev['new_value'] !== null): ?>
                            <s class="text-muted"><?= e($ev['old_value']) ?></s> → <strong><?= e($ev['new_value']) ?></strong>
                        <?php elseif ($ev['new_value'] !== null): ?>
                            <?= e($ev['new_value']) ?>
                        <?php else: ?>
                            <s class="text-muted"><?= e($ev['old_value']) ?></s>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</div>

<aside class="ticket-side">

    <section class="card">
        <h2 class="card-title">Details</h2>
        <dl class="detail-list">
            <dt>Nummer</dt><dd class="mono"><?= e($task['number']) ?></dd>
            <dt>Status</dt><dd><?= badge($statuses[$task['status']] ?? $task['status'], $statusColors[$task['status']] ?? 'neutral') ?></dd>
            <dt>Kategorie</dt><dd><?= $task['category_name'] !== null ? e($task['category_name']) : '–' ?></dd>
            <dt>Priorität</dt><dd><?= $task['priority_name'] !== null ? e($task['priority_name']) : '–' ?></dd>
            <dt>Zugewiesen an</dt><dd><?= $task['assignee_name'] !== null ? e($task['assignee_name']) : '–' ?></dd>
            <?php if ($task['assigned_by_name'] !== null): ?>
                <dt>Zugewiesen von</dt><dd><?= e($task['assigned_by_name']) ?><div class="text-xs text-muted"><?= fmt_datetime($task['assigned_at']) ?></div></dd>
            <?php endif; ?>
            <?php if ($task['claimed_at'] !== null): ?>
                <dt>Angenommen</dt><dd><?= fmt_datetime($task['claimed_at']) ?></dd>
            <?php endif; ?>
            <dt>Fällig</dt><dd><?= fmt_date($task['due_at']) ?></dd>
            <?php if ($task['completed_at'] !== null): ?>
                <dt>Abgeschlossen</dt><dd><?= fmt_datetime($task['completed_at']) ?><?php if ($task['completed_by_name'] !== null): ?><div class="text-xs text-muted">von <?= e($task['completed_by_name']) ?></div><?php endif; ?></dd>
            <?php endif; ?>
            <?php if ($task['source_ticket_id'] !== null): ?>
                <dt>Quelle</dt><dd><a href="/helpdesk/tickets/<?= (int) $task['source_ticket_id'] ?>"><?= e($task['source_ticket_number']) ?></a><div class="text-xs text-muted"><?= e($task['source_ticket_subject']) ?></div></dd>
            <?php endif; ?>
        </dl>
    </section>

    <?php if ($can('helpdesk.tasks')): ?>
    <section class="card">
        <h2 class="card-title">Kategorie</h2>
        <form method="post" action="/helpdesk/aufgaben/<?= $id ?>/kategorie" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="version" value="<?= (int) $task['version'] ?>">
            <div class="form-group">
                <label for="task-category">Kategorie</label>
                <select id="task-category" name="category_id">
                    <option value="">– keine Kategorie –</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= selected($task['category_id'], $c['id']) ?>><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?> Speichern</button>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($canChangeStatus): ?>
    <section class="card">
        <h2 class="card-title">Status ändern</h2>
        <form method="post" action="/helpdesk/aufgaben/<?= $id ?>/status" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="version" value="<?= (int) $task['version'] ?>">
            <div class="form-group">
                <label for="task-status">Status</label>
                <select id="task-status" name="status">
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= selected($task['status'], $key) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?> Speichern</button>
        </form>
    </section>
    <?php endif; ?>
</aside>
</div>

<?php if ($canAssign && !$final): ?>
<dialog id="assign-dialog" class="dialog">
    <form method="post" action="/helpdesk/aufgaben/<?= $id ?>/zuweisen">
        <?= csrf_field() ?>
        <div class="dialog-header">
            <h2><?= icon('user') ?> Aufgabe zuweisen</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button>
        </div>
        <div class="dialog-body">
            <div class="form-group">
                <label for="assign-dialog-agent">Agent</label>
                <select id="assign-dialog-agent" name="assignee_user_id">
                    <option value="">– Zuweisung aufheben –</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= (int) $a['id'] ?>"<?= selected($task['assignee_user_id'], $a['id']) ?>><?= e($a['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">Der Agent erhält die Aufgabe zugewiesen und sieht sie unter „Meine Aufgaben“, ohne sie annehmen zu müssen. Ohne Auswahl wird die Zuweisung aufgehoben.</p>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary"><?= icon('user') ?> Zuweisen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
