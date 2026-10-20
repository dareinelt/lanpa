<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$hasFilter = false;
foreach (['q', 'status_id', 'priority_id', 'type_id', 'group_id', 'assignee_user_id', 'category_id', 'requester_employee_id', 'employee_id', 'asset_id', 'tag', 'sla', 'created_from', 'created_to'] as $key) {
    if (!empty($filters[$key])) {
        $hasFilter = true;
        break;
    }
}
$resetUrl = '/helpdesk/tickets?view=' . e($filters['view']);
$hint = static fn (string $key): string => in_array($key, ['overdue', 'critical'], true) ? ' is-danger' : '';
?>
<div class="page-header">
    <div>
        <h1>Tickets</h1>
        <p class="page-subtitle text-muted mb-0"><?= (int) $paginator->total ?> Tickets · <?= e($views[$filters['view']] ?? 'Alle') ?></p>
    </div>
    <div class="page-actions">
        <?php if ($can('helpdesk.export')): ?><a class="btn btn-secondary" href="<?= e(query_url('/helpdesk/tickets/export', $query, ['page' => null])) ?>"><?= icon('download') ?> CSV</a><?php endif; ?>
        <?php if ($can('helpdesk.create')): ?><a class="btn btn-primary" href="/helpdesk/tickets/new"><?= icon('plus') ?> Neues Ticket</a><?php endif; ?>
    </div>
</div>

<div class="status-tabs" role="tablist" aria-label="Ansicht">
    <?php foreach ($views as $key => $label): $n = $viewCounts[$key] ?? null; ?>
        <a class="status-tab<?= $filters['view'] === $key ? ' is-active' : '' ?>" href="<?= e(query_url('/helpdesk/tickets', $query, ['view' => $key, 'page' => null])) ?>" role="tab"><?= e($label) ?><?php if ($n !== null): ?> <span class="status-tab-count<?= $n > 0 ? $hint($key) : '' ?>"><?= (int) $n ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="/helpdesk/tickets" class="filter-bar filter-bar-dense" role="search">
    <input type="hidden" name="view" value="<?= e($filters['view']) ?>">
    <?php if ($sort !== 'created_at' || $dir !== 'desc'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
    <?php foreach (['requester_employee_id', 'employee_id', 'asset_id', 'created_from', 'created_to'] as $hidden): ?>
        <?php if (!empty($filters[$hidden])): ?><input type="hidden" name="<?= $hidden ?>" value="<?= e($filters[$hidden]) ?>"><?php endif; ?>
    <?php endforeach; ?>
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Ticketnummer, Betreff, Melder, Inventarnummer …">
    </div>
    <div class="form-group">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($statuses as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($filters['status_id'], $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-priority">Priorität</label>
        <select id="filter-priority" name="priority_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($priorities as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected($filters['priority_id'], $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-type">Typ</label>
        <select id="filter-type" name="type_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($filters['type_id'], $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-group">Gruppe</label>
        <select id="filter-group" name="group_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= selected($filters['group_id'], $g['id']) ?>><?= e($g['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-assignee">Bearbeiter</label>
        <select id="filter-assignee" name="assignee_user_id" data-autosubmit>
            <option value="">Alle</option>
            <option value="none"<?= selected($filters['assignee_user_id'], 'none') ?>>– nicht zugewiesen –</option>
            <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected($filters['assignee_user_id'], $a['id']) ?>><?= e($a['display_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-category">Kategorie</label>
        <select id="filter-category" name="category_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($filters['category_id'], $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-sla">SLA</label>
        <select id="filter-sla" name="sla" data-autosubmit>
            <option value="">Alle</option>
            <option value="ok"<?= selected($filters['sla'], 'ok') ?>>Im Plan</option>
            <option value="warning"<?= selected($filters['sla'], 'warning') ?>>Warnung</option>
            <option value="breached"<?= selected($filters['sla'], 'breached') ?>>Verletzt</option>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-tag">Tag</label>
        <input id="filter-tag" name="tag" value="<?= e($filters['tag']) ?>" list="tag-suggest" data-hd-tags placeholder="Tag">
        <datalist id="tag-suggest"></datalist>
    </div>
    <label class="filter-toggle"><input type="checkbox" name="include_merged" value="1"<?= checked($filters['include_merged']) ?> data-autosubmit> Zusammengeführte anzeigen</label>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="<?= $resetUrl ?>"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>

<div class="card card-flush">
<?php if ($rows === []): ?>
    <div class="table-empty">Keine Tickets gefunden.</div>
<?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr>
            <th><?= sort_link($basePath, $query, 'number', 'Nummer') ?></th>
            <th><?= sort_link($basePath, $query, 'subject', 'Betreff') ?></th>
            <th><?= sort_link($basePath, $query, 'status', 'Status') ?></th>
            <th><?= sort_link($basePath, $query, 'priority', 'Priorität') ?></th>
            <th><?= sort_link($basePath, $query, 'requester', 'Melder') ?></th>
            <th><?= sort_link($basePath, $query, 'assignee', 'Bearbeiter') ?></th>
            <th><?= sort_link($basePath, $query, 'resolution_due_at', 'SLA') ?></th>
            <th><?= sort_link($basePath, $query, 'updated_at', 'Aktualisiert') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $closed = in_array($r['status_category'], ['resolved', 'closed', 'cancelled'], true);
            $slaState = $r['sla_resolution_state'] === 'breached' || $r['sla_response_state'] === 'breached' ? 'breached' : ($r['sla_resolution_state'] === 'warning' || $r['sla_response_state'] === 'warning' ? 'warning' : 'ok');
            $rowClass = trim('ticket-row' . ($closed ? ' is-closed' : '') . (!$closed && $slaState === 'breached' ? ' is-overdue' : '') . (!$closed && $slaState === 'warning' ? ' is-warning' : ''));
            $remaining = $slaService->remainingMinutes($r['resolution_due_at'], $now);
        ?>
            <tr data-href="/helpdesk/tickets/<?= (int) $r['id'] ?>" class="<?= $rowClass ?>">
                <td class="ticket-number"><a href="/helpdesk/tickets/<?= (int) $r['id'] ?>"><?= e($r['number']) ?></a><?php if ($r['merged_into_ticket_id'] !== null): ?><div class="text-xs text-muted">→ <?= e($r['merged_into_number']) ?></div><?php endif; ?></td>
                <td class="ticket-subject">
                    <a href="/helpdesk/tickets/<?= (int) $r['id'] ?>"><?= e($r['subject']) ?></a>
                    <div class="ticket-meta">
                        <span><?= e($r['type_name']) ?></span>
                        <?php if ($r['category_name']): ?><span>· <?= e($r['category_name']) ?><?= $r['subcategory_name'] ? ' / ' . e($r['subcategory_name']) : '' ?></span><?php endif; ?>
                        <?php if ((int) $r['comment_count'] > 0): ?><span title="Kommentare">· <?= (int) $r['comment_count'] ?> Kommentare</span><?php endif; ?>
                        <?php if ((int) $r['attachment_count'] > 0): ?><span title="Anhänge">· <?= icon('paperclip', 'icon icon-xs') ?> <?= (int) $r['attachment_count'] ?></span><?php endif; ?>
                        <?php if ((int) $r['asset_count'] > 0): ?><span>· <?= (int) $r['asset_count'] ?> Asset(s)</span><?php endif; ?>
                        <?php if (!empty($r['tag_names'])): ?><span class="tag-list"><?php foreach (explode(',', (string) $r['tag_names']) as $tagName): ?><a class="tag-chip" href="<?= e(query_url('/helpdesk/tickets', $query, ['tag' => trim($tagName), 'page' => null])) ?>"><?= e(trim($tagName)) ?></a><?php endforeach; ?></span><?php endif; ?>
                    </div>
                </td>
                <td class="nowrap"><?= badge($r['status_name'], $r['status_color']) ?></td>
                <td class="nowrap"><span class="prio-dot is-<?= e($r['priority_color']) ?>"></span><?= e($r['priority_name']) ?></td>
                <td><?= e($r['requester_name'] ?? $r['created_by_name'] ?? '–') ?><?php if (!empty($r['requester_department'])): ?><div class="text-xs text-muted"><?= e($r['requester_department']) ?></div><?php endif; ?></td>
                <td><?= $r['assignee_name'] ? e($r['assignee_name']) : '<span class="text-muted">–</span>' ?><?php if ($r['group_name']): ?><div class="text-xs text-muted"><?= e($r['group_name']) ?></div><?php endif; ?></td>
                <td class="sla-cell">
                    <?php if ($closed): ?>
                        <?= $r['sla_resolution_state'] === 'met' ? badge('Eingehalten', 'success') : ($r['sla_resolution_state'] === 'breached' ? badge('Verletzt', 'danger') : '<span class="text-muted">–</span>') ?>
                    <?php elseif ($r['resolution_due_at'] === null): ?>
                        <span class="text-muted">–</span>
                    <?php elseif ($r['sla_paused_at'] !== null): ?>
                        <span class="sla-paused"><?= icon('clock', 'icon icon-xs') ?> pausiert</span>
                    <?php else: ?>
                        <span class="sla-remaining<?= $remaining !== null && $remaining < 0 ? ' is-overdue' : '' ?>" data-hd-due="<?= e($r['resolution_due_at']) ?>"><?= e(\App\Services\Helpdesk\TicketSlaService::humanRemaining($remaining)) ?></span>
                        <div class="text-xs text-muted"><?= fmt_datetime($r['resolution_due_at']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="nowrap text-sm"><?= fmt_datetime($r['updated_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
