<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk';
$areaLabel = 'Help Desk';
$scripts = ['/js/helpdesk.js'];
$counts = $data['counts'] ?? [];
$ticketUrl = static fn (array $ticket): string => '/helpdesk/tickets/' . (int) ($ticket['id'] ?? 0);
$slaRemaining = static function (?string $due) use ($now): string {
    return \App\Services\Helpdesk\TicketSlaService::humanRemaining($due ? (new \App\Services\Helpdesk\TicketSlaService())->remainingMinutes($due, $now) : null);
};
$listTable = static function (array $rows) use ($ticketUrl, $slaRemaining): void { ?>
    <?php if ($rows === []): ?>
        <div class="table-empty">Keine Tickets vorhanden.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Nr.</th><th>Betreff</th><th>Prio</th><th>Status</th><th>SLA</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $closed = in_array((string) ($r['status_category'] ?? ''), ['resolved', 'closed', 'cancelled'], true);
                $state = (string) ($r['sla_resolution_state'] ?? '');
                $rowClass = trim('ticket-row ' . ($closed ? 'is-closed ' : '') . (!$closed && $state === 'breached' ? 'is-overdue ' : (!$closed && $state === 'warning' ? 'is-warning ' : '')));
            ?>
                <tr class="<?= e($rowClass) ?>" data-href="<?= e($ticketUrl($r)) ?>">
                    <td class="ticket-number"><a href="<?= e($ticketUrl($r)) ?>"><?= e($r['number'] ?? '–') ?></a></td>
                    <td class="ticket-subject"><a href="<?= e($ticketUrl($r)) ?>"><?= e($r['subject'] ?? '–') ?></a><div class="ticket-meta"><?= e($r['requester_name'] ?? $r['requester_user_name'] ?? '–') ?> · <?= fmt_datetime($r['updated_at'] ?? null) ?></div></td>
                    <td class="nowrap"><span class="prio-dot is-<?= e($r['priority_color'] ?? 'neutral') ?>"></span><?= e($r['priority_name'] ?? '–') ?></td>
                    <td><?= badge($r['status_name'] ?? '–', $r['status_color'] ?? 'neutral') ?></td>
                    <td class="sla-cell">
                        <?php if ($closed): ?>
                            <?= $state === 'met' ? badge('Eingehalten', 'success') : ($state === 'breached' ? badge('Verletzt', 'danger') : '<span class="text-muted">–</span>') ?>
                        <?php else: ?>
                            <span class="sla-remaining<?= $state === 'breached' ? ' is-overdue' : '' ?>"<?= !empty($r['resolution_due_at']) ? ' data-hd-due="' . e($r['resolution_due_at']) . '"' : '' ?>><?= e($slaRemaining($r['resolution_due_at'] ?? null)) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php };
$barList = static function (array $rows): void {
    $max = max(1, ...array_map(static fn (array $r): int => (int) ($r['cnt'] ?? 0), $rows ?: [['cnt' => 1]])); ?>
    <?php if ($rows === []): ?><p class="text-muted mb-0">Keine Daten.</p><?php else: ?>
    <ul class="bar-list">
        <?php foreach ($rows as $r): ?>
            <li><label><?= e($r['label'] ?? '–') ?></label><progress class="bar-progress" value="<?= (int) ($r['cnt'] ?? 0) ?>" max="<?= (int) $max ?>"></progress><span class="mono"><?= (int) ($r['cnt'] ?? 0) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
<?php }; ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Help Desk</h1>
        <p class="page-subtitle text-muted">Tickets, Warteschlangen und SLA-Status im Überblick.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" data-dialog-open="shift-dialog"><?= icon('shield') ?> Zuständigkeit</button>
        <?php if ($can('helpdesk.create')): ?><a class="btn btn-primary" href="/helpdesk/tickets/new"><?= icon('plus') ?> Neues Ticket</a><?php endif; ?>
        <a class="btn btn-secondary" href="/helpdesk/tickets"><?= icon('list') ?> Ticketliste</a>
    </div>
</div>
<?php if ($isFirstLevel && ($supportShift['second'] ?? null) !== null): ?>
<div class="alert alert-info mb-4"><?= icon('users', 'icon icon-xs') ?> Zuständiger 2nd Level für dich ist heute <strong><?= e($supportShift['second']['user_display_name']) ?></strong>.</div>
<?php endif; ?>
<div class="grid grid-4 mb-4">
    <a class="card stat-card is-info" href="/helpdesk/tickets?view=mine_open"><span class="stat-value"><?= (int) ($counts['new_count'] ?? 0) ?></span><span class="stat-label">Neu</span></a>
    <a class="card stat-card" href="/helpdesk/tickets?view=open"><span class="stat-value"><?= (int) ($counts['open_count'] ?? 0) ?> <span class="text-sm text-muted">/ <?= (int) ($counts['in_progress'] ?? 0) ?></span></span><span class="stat-label">Offen / in Bearbeitung</span></a>
    <a class="card stat-card is-warning" href="/helpdesk/tickets?view=waiting_user"><span class="stat-value"><?= (int) ($counts['waiting_user'] ?? 0) ?></span><span class="stat-label">Wartet</span></a>
    <a class="card stat-card is-danger" href="/helpdesk/tickets?view=overdue"><span class="stat-value"><?= (int) ($counts['overdue'] ?? 0) ?></span><span class="stat-label">Überfällig</span></a>
    <a class="card stat-card is-danger" href="/helpdesk/tickets?view=critical"><span class="stat-value"><?= (int) ($counts['critical'] ?? 0) ?></span><span class="stat-label">Kritisch</span></a>
    <a class="card stat-card is-success" href="/helpdesk/tickets?view=mine_open"><span class="stat-value"><?= (int) ($counts['mine'] ?? 0) ?></span><span class="stat-label">Meine offenen</span></a>
    <a class="card stat-card is-warning" href="/helpdesk/tickets?view=unassigned"><span class="stat-value"><?= (int) ($counts['unassigned'] ?? 0) ?></span><span class="stat-label">Nicht zugewiesen</span></a>
    <div class="card stat-card"><span class="stat-value"><?= (int) ($counts['created_today'] ?? 0) ?> / <?= (int) ($counts['resolved_today'] ?? 0) ?></span><span class="stat-label">Heute erstellt / gelöst</span></div>
</div>
<div class="status-tabs mb-4" aria-label="Schnellansichten">
    <?php foreach ($views as $key => $label): ?>
        <a class="status-tab" href="/helpdesk/tickets?view=<?= e($key) ?>"><?= e($label) ?> <span class="status-tab-count"><?= (int) ($viewCounts[$key] ?? 0) ?></span></a>
    <?php endforeach; ?>
</div>
<div class="hd-lists">
    <div class="card card-flush"><div class="card-header"><h2>Meine Tickets</h2></div><?php $listTable($data['mine'] ?? []); ?></div>
    <div class="card card-flush"><div class="card-header"><h2>Nicht zugewiesen</h2></div><?php $listTable($data['unassigned'] ?? []); ?></div>
    <div class="card card-flush"><div class="card-header"><h2>Überfällig (SLA)</h2></div><?php $listTable($data['overdue'] ?? []); ?></div>
    <div class="card card-flush"><div class="card-header"><h2>Aktuell geändert</h2></div><?php $listTable($data['recent'] ?? []); ?></div>
    <div class="card"><div class="card-header"><h2>Nach Priorität</h2></div><?php $barList($data['by_priority'] ?? []); ?></div>
    <div class="card"><div class="card-header"><h2>Nach Gruppe</h2></div><?php $barList($data['by_group'] ?? []); ?></div>
    <div class="card">
        <div class="card-header"><h2>Letzte 14 Tage</h2></div>
        <ul class="bar-list">
            <?php $maxDay = max(1, ...array_map(static fn (array $r): int => max((int) ($r['created_count'] ?? 0), (int) ($r['resolved_count'] ?? 0)), $data['per_day'] ?: [['created_count' => 1, 'resolved_count' => 1]])); ?>
            <?php foreach (($data['per_day'] ?? []) as $r): ?>
                <li><label><?= fmt_date($r['day'] ?? null) ?></label><progress class="bar-progress" value="<?= (int) ($r['created_count'] ?? 0) ?>" max="<?= (int) $maxDay ?>"></progress><span class="mono">+<?= (int) ($r['created_count'] ?? 0) ?> / ✓<?= (int) ($r['resolved_count'] ?? 0) ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card">
        <div class="card-header"><h2>SLA</h2></div>
        <?php $sla = $data['sla'] ?? []; ?>
        <div class="kpi-row">
            <div><span class="kpi-value"><?= (int) ($sla['with_sla'] ?? 0) ?></span><span class="kpi-label">mit SLA</span></div>
            <div><span class="kpi-value"><?= (int) ($sla['resolution_breached'] ?? 0) ?></span><span class="kpi-label">Lösung verletzt</span></div>
            <div><span class="kpi-value"><?= (int) ($sla['response_breached'] ?? 0) ?></span><span class="kpi-label">Reaktion verletzt</span></div>
            <div><span class="kpi-value"><?= (int) ($sla['escalated'] ?? 0) ?></span><span class="kpi-label">eskaliert</span></div>
        </div>
    </div>
</div>
<dialog id="shift-dialog" class="dialog">
    <div class="dialog-header"><h2>Zuständigkeit übernehmen</h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button></div>
    <div class="dialog-body">
        <p class="text-muted text-sm">Übernimm die Zuständigkeit für den 1st- oder 2nd-Level-Support des heutigen Tages (<?= fmt_date($supportShift['date']) ?>). Die Zuständigkeit endet automatisch um 19:00 Uhr.</p>
        <ul class="side-list">
            <li>
                <div class="side-list-main">
                    <strong>1st Level Support</strong>
                    <div class="text-xs text-muted"><?= ($supportShift['first'] ?? null) !== null ? 'Aktuell: ' . e($supportShift['first']['user_display_name']) : 'Noch nicht übernommen' ?></div>
                </div>
                <form method="post" action="/helpdesk/support-shift/claim" class="inline-form"><?= csrf_field() ?><input type="hidden" name="level" value="1"><button type="submit" class="btn btn-secondary btn-sm">Übernehmen</button></form>
            </li>
            <li>
                <div class="side-list-main">
                    <strong>2nd Level Support</strong>
                    <div class="text-xs text-muted"><?= ($supportShift['second'] ?? null) !== null ? 'Aktuell: ' . e($supportShift['second']['user_display_name']) : 'Noch nicht übernommen' ?></div>
                </div>
                <form method="post" action="/helpdesk/support-shift/claim" class="inline-form" data-confirm="Beim Übernehmen des 2nd Level Supports werden dir automatisch alle offenen Tickets zugewiesen, die nicht von heute stammen. Fortfahren?"><?= csrf_field() ?><input type="hidden" name="level" value="2"><button type="submit" class="btn btn-secondary btn-sm">Übernehmen</button></form>
            </li>
        </ul>
    </div>
    <div class="dialog-footer"><button type="button" class="btn btn-secondary" data-dialog-close>Schließen</button></div>
</dialog>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
