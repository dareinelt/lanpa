<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$isMine = $mode === 'mine';
$hasFilter = false;
foreach (['q', 'status', 'priority_id', 'assignee_user_id', 'created_from', 'created_to'] as $key) {
    if (!empty($filters[$key])) {
        $hasFilter = true;
        break;
    }
}
$resetUrl = $basePath . '?view=' . e($filters['view']);
$hint = static fn (string $key): string => $key === 'unassigned' ? ' is-danger' : '';
$canAssign = $can('helpdesk.tasks.assign');
?>
<div class="page-header">
    <div>
        <h1><?= $isMine ? 'Meine Aufgaben' : 'Alle Aufgaben' ?></h1>
        <p class="page-subtitle text-muted mb-0"><?= (int) $paginator->total ?> Aufgaben · <?= e($views[$filters['view']] ?? 'Alle') ?></p>
    </div>
    <div class="page-actions">
        <?php if ($isMine): ?>
            <a class="btn btn-secondary" href="/helpdesk/aufgaben"><?= icon('inbox') ?> Alle Aufgaben</a>
        <?php else: ?>
            <a class="btn btn-secondary" href="/helpdesk/aufgaben/meine"><?= icon('user') ?> Meine Aufgaben</a>
        <?php endif; ?>
    </div>
</div>

<div class="status-tabs" role="tablist" aria-label="Ansicht">
    <?php foreach ($views as $key => $label): $n = $viewCounts[$key] ?? null; ?>
        <a class="status-tab<?= $filters['view'] === $key ? ' is-active' : '' ?>" href="<?= e(query_url($basePath, $query, ['view' => $key, 'page' => null])) ?>" role="tab"><?= e($label) ?><?php if ($n !== null): ?> <span class="status-tab-count<?= $n > 0 ? $hint($key) : '' ?>"><?= (int) $n ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e($basePath) ?>" class="filter-bar filter-bar-dense" role="search">
    <input type="hidden" name="view" value="<?= e($filters['view']) ?>">
    <?php if ($sort !== 'updated_at' || $dir !== 'desc'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
    <?php foreach (['created_from', 'created_to'] as $hidden): ?>
        <?php if (!empty($filters[$hidden])): ?><input type="hidden" name="<?= $hidden ?>" value="<?= e($filters[$hidden]) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Aufgabennummer, Betreff, Ticketnummer …">
    </div>
    <div class="form-group">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($statuses as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($filters['status'], $key) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-priority">Priorität</label>
        <select id="filter-priority" name="priority_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($priorities as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected($filters['priority_id'], $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php if (!$isMine): ?>
    <div class="form-group">
        <label for="filter-assignee">Zugewiesen an</label>
        <select id="filter-assignee" name="assignee_user_id" data-autosubmit>
            <option value="">Alle</option>
            <option value="none"<?= selected($filters['assignee_user_id'], 'none') ?>>– nicht zugewiesen –</option>
            <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected($filters['assignee_user_id'], $a['id']) ?>><?= e($a['display_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="<?= $resetUrl ?>"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>

<div class="card card-flush">
<?php if ($rows === []): ?>
    <div class="table-empty">Keine Aufgaben gefunden.</div>
<?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr>
            <th><?= sort_link($basePath, $query, 'number', 'Nummer') ?></th>
            <th><?= sort_link($basePath, $query, 'subject', 'Betreff') ?></th>
            <th><?= sort_link($basePath, $query, 'status', 'Status') ?></th>
            <th><?= sort_link($basePath, $query, 'priority', 'Priorität') ?></th>
            <?php if (!$isMine): ?><th><?= sort_link($basePath, $query, 'assignee', 'Zugewiesen an') ?></th><?php endif; ?>
            <th><?= sort_link($basePath, $query, 'due_at', 'Fällig') ?></th>
            <th>Quelle</th>
            <th><?= sort_link($basePath, $query, 'updated_at', 'Aktualisiert') ?></th>
            <th class="nowrap">Aktion</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $final = in_array((string) $r['status'], \App\Services\Helpdesk\TaskService::FINAL, true);
            $unassigned = $r['assignee_user_id'] === null;
            $overdue = !$final && $r['due_at'] !== null && strtotime((string) $r['due_at']) < time();
            $rowClass = trim('ticket-row' . ($final ? ' is-closed' : '') . ($overdue ? ' is-overdue' : ''));
            $detailUrl = '/helpdesk/aufgaben/' . (int) $r['id'];
        ?>
            <tr data-href="<?= $detailUrl ?>" class="<?= $rowClass ?>">
                <td class="ticket-number"><a href="<?= $detailUrl ?>"><?= e($r['number']) ?></a></td>
                <td class="ticket-subject">
                    <?php if ($r['category_name'] !== null): ?><span class="badge badge-neutral task-category"><?= e($r['category_name']) ?></span> <?php endif; ?><a href="<?= $detailUrl ?>"><?= e($r['subject']) ?></a>
                    <?php if ((int) $r['comment_count'] > 0): ?><div class="ticket-meta"><span class="badge badge-neutral"><?= icon('pen', 'icon icon-xs') ?> <?= (int) $r['comment_count'] ?></span><span><?= e(mb_strimwidth(trim((string) $r['last_comment']), 0, 90, ' …')) ?></span></div><?php endif; ?>
                </td>
                <td class="nowrap"><?= badge($statuses[$r['status']] ?? $r['status'], $statusColors[$r['status']] ?? 'neutral') ?></td>
                <td class="nowrap">
                    <?php if ($r['priority_name'] !== null): ?><span class="prio-dot is-<?= e($r['priority_color']) ?>"></span><?= e($r['priority_name']) ?><?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <?php if (!$isMine): ?>
                <td>
                    <?php if ($r['assignee_name'] !== null): ?>
                        <?= e($r['assignee_name']) ?>
                        <?php if ($r['assigned_by_name'] !== null): ?><div class="text-xs text-muted">von <?= e($r['assigned_by_name']) ?></div><?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">– nicht zugewiesen –</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="nowrap">
                    <?php if ($r['due_at'] === null): ?><span class="text-muted">–</span>
                    <?php else: ?><span<?= $overdue ? ' class="sla-remaining is-overdue"' : '' ?>><?= fmt_date($r['due_at']) ?></span><?php endif; ?>
                </td>
                <td class="nowrap">
                    <?php if ($r['source_ticket_id'] !== null): ?>
                        <a href="/helpdesk/tickets/<?= (int) $r['source_ticket_id'] ?>"><?= e($r['source_ticket_number']) ?></a>
                    <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <td class="nowrap text-sm"><?= fmt_datetime($r['updated_at']) ?></td>
                <td class="nowrap">
                    <?php if (!$final && $unassigned): ?>
                        <form method="post" action="<?= $detailUrl ?>/annehmen">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('check') ?> Annehmen</button>
                        </form>
                    <?php elseif ($canAssign && !$final): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= $detailUrl ?>"><?= icon('user') ?> Zuweisen</a>
                    <?php else: ?>
                        <a class="btn btn-ghost btn-sm" href="<?= $detailUrl ?>"><?= icon('eye') ?> Details</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../../partials/pagination.php'; ?>

<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
