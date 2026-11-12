<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk';
$areaLabel = 'Help Desk';
$scripts = ['/js/helpdesk.js', '/js/helpdesk-calendar.js'];
$counts = $data['counts'] ?? [];
$ticketUrl = static fn (array $ticket): string => '/helpdesk/tickets/' . (int) ($ticket['id'] ?? 0);
$slaRemaining = static function (array $row) use ($now, $slaService): string {
    $rule = \App\Services\Helpdesk\TicketSlaService::ruleFromRow($row);

    return \App\Services\Helpdesk\TicketSlaService::humanRemaining(
        $slaService->remainingMinutes($row['resolution_due_at'] ?? null, $now, $rule),
        (string) ($row['sla_resolution_state'] ?? '') === 'breached'
    );
};
$slaProgress = static function (array $row) use ($now, $slaService): ?array {
    $rule = \App\Services\Helpdesk\TicketSlaService::ruleFromRow($row);

    return $rule !== null ? $slaService->businessProgress($rule, (string) $row['created_at'], $row['resolution_due_at'] ?? null, $now) : null;
};
$listTable = static function (array $rows) use ($ticketUrl, $slaRemaining, $slaProgress): void { ?>
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
                    <td class="nowrap" title="Priorität: <?= e($r['priority_name'] ?? '–') ?>"><span class="prio-dot is-<?= e($r['priority_color'] ?? 'neutral') ?>"></span><span class="prio-name"><?= e($r['priority_name'] ?? '–') ?></span></td>
                    <td><?= badge($r['status_name'] ?? '–', $r['status_color'] ?? 'neutral') ?></td>
                    <td class="sla-cell">
                        <?php if ($closed): ?>
                            <?= $state === 'met' ? badge('Eingehalten', 'success') : ($state === 'breached' ? badge('Verletzt', 'danger') : '<span class="text-muted">–</span>') ?>
                        <?php else: ?>
                            <span class="sla-remaining<?= $state === 'breached' ? ' is-overdue' : '' ?>"<?= !empty($r['resolution_due_at']) ? ' data-hd-due="' . e($r['resolution_due_at']) . '"' : '' ?><?= sla_progress_attrs($slaProgress($r)) ?>><?= e($slaRemaining($r)) ?></span>
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
            <li><label><?= e($r['label'] ?? '–') ?></label><progress class="bar-progress" value="<?= (int) ($r['cnt'] ?? 0) ?>" max="<?= (int) $max ?>"></progress><span class="tabular"><?= (int) ($r['cnt'] ?? 0) ?></span></li>
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
<?php if (($pendingForwards ?? []) !== []): ?>
<section class="card hd-forward-card mb-4">
    <h2 class="card-title"><?= icon('swap', 'icon icon-xs') ?> An Sie weitergeleitet <span class="badge is-warning"><?= count($pendingForwards) ?></span></h2>
    <ul class="hd-forward-list">
        <?php foreach ($pendingForwards as $f): ?>
        <li class="hd-forward-item">
            <div>
                <a href="<?= e($ticketUrl($f)) ?>"><strong><?= e($f['number'] ?? '–') ?></strong> <?= e($f['subject'] ?? '–') ?></a>
                <div class="text-xs text-muted">
                    von <?= e($f['forwarded_by_name'] ?? 'Unbekannt') ?> · <?= fmt_datetime($f['forwarded_at'] ?? null) ?>
                    <?php if (!empty($f['group_name'])): ?> · <?= e($f['group_name']) ?><?php endif; ?>
                    · <?= e($f['priority_name'] ?? '–') ?>
                </div>
                <?php if (!empty($f['forward_reason'])): ?><div class="text-xs">Grund: <?= e($f['forward_reason']) ?></div><?php endif; ?>
            </div>
            <form method="post" action="<?= e($ticketUrl($f)) ?>/take" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?> Übernehmen</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php if (($mentions ?? []) !== []): ?>
<section class="card hd-forward-card hd-mention-card mb-4" id="erwaehnungen">
    <div class="hd-mention-head">
        <h2 class="card-title"><?= icon('bell', 'icon icon-xs') ?> Sie wurden erwähnt <span class="badge is-info"><?= count($mentions) ?></span></h2>
        <form method="post" action="/helpdesk/erwaehnungen/gelesen" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm"><?= icon('check') ?> Alle als gelesen markieren</button>
        </form>
    </div>
    <ul class="hd-forward-list">
        <?php foreach ($mentions as $m):
            $isTask = $m['task_id'] !== null;
            $mentionUrl = $isTask
                ? '/helpdesk/aufgaben/' . (int) $m['task_id'] . '#kommentar-' . (int) $m['comment_id']
                : '/helpdesk/tickets/' . (int) $m['ticket_id'] . '#comment-' . (int) $m['comment_id'];
            $number = $isTask ? ($m['task_number'] ?? '–') : ($m['ticket_number'] ?? '–');
            $subject = $isTask ? ($m['task_subject'] ?? '–') : ($m['ticket_subject'] ?? '–');
        ?>
        <li class="hd-forward-item">
            <div>
                <a href="<?= e($mentionUrl) ?>"><strong><?= e($number) ?></strong> <?= e($subject) ?></a>
                <div class="text-xs text-muted">
                    <?= $isTask ? 'Aufgabenkommentar' : 'Interne Notiz' ?> von <?= e($m['mentioned_by_name'] ?? 'Unbekannt') ?> · <?= fmt_datetime($m['created_at'] ?? null) ?>
                </div>
                <?php if (!empty($m['excerpt'])): ?><div class="text-xs hd-mention-excerpt"><?= e($m['excerpt']) ?></div><?php endif; ?>
            </div>
            <form method="post" action="/helpdesk/erwaehnungen/<?= (int) $m['id'] ?>/gelesen" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('check') ?> Gelesen</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<div class="grid grid-4 mb-4 hd-kpis">
    <a class="card stat-card is-info" href="/helpdesk/tickets?view=mine_open"><span class="stat-value"><?= (int) ($counts['new_count'] ?? 0) ?></span><span class="stat-label">Neu</span></a>
    <a class="card stat-card" href="/helpdesk/tickets?view=open"><span class="stat-value"><?= (int) ($counts['open_count'] ?? 0) ?> <span class="text-sm text-muted">/ <?= (int) ($counts['in_progress'] ?? 0) ?></span></span><span class="stat-label">Offen / in Bearbeitung</span></a>
    <a class="card stat-card is-warning" href="/helpdesk/tickets?view=paused"><span class="stat-value"><?= (int) ($counts['paused'] ?? 0) ?></span><span class="stat-label">Pausiert</span></a>
    <a class="card stat-card is-danger" href="/helpdesk/tickets?view=overdue"><span class="stat-value"><?= (int) ($counts['overdue'] ?? 0) ?></span><span class="stat-label">Überfällig</span></a>
    <a class="card stat-card is-danger" href="/helpdesk/tickets?view=critical"><span class="stat-value"><?= (int) ($counts['critical'] ?? 0) ?></span><span class="stat-label">Kritisch</span></a>
    <a class="card stat-card is-success" href="/helpdesk/tickets?view=mine_open"><span class="stat-value"><?= (int) ($counts['mine'] ?? 0) ?></span><span class="stat-label">Meine offenen</span></a>
    <a class="card stat-card is-warning" href="/helpdesk/tickets?view=unassigned"><span class="stat-value"><?= (int) ($counts['unassigned'] ?? 0) ?></span><span class="stat-label">Nicht zugewiesen</span></a>
    <div class="card stat-card"><span class="stat-value"><?= (int) ($counts['created_today'] ?? 0) ?> / <?= (int) ($counts['resolved_today'] ?? 0) ?></span><span class="stat-label">Heute erstellt / gelöst</span></div>
</div>
<div class="status-tabs mb-4" aria-label="Schnellansichten">
    <?php foreach ($views as $key => $label):
        // „Kritisch“ fehlt in den Ansichtszählern; die Kennzahl oben zählt dieselbe Ansicht.
        $n = $viewCounts[$key] ?? ($key === 'critical' && isset($counts['critical']) ? (int) $counts['critical'] : null); ?>
        <a class="status-tab" href="/helpdesk/tickets?view=<?= e($key) ?>"><?= e($label) ?><?php if ($n !== null): ?> <span class="status-tab-count"><?= (int) $n ?></span><?php endif; ?></a>
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
                <li><label><?= fmt_date($r['day'] ?? null) ?></label><progress class="bar-progress" value="<?= (int) ($r['created_count'] ?? 0) ?>" max="<?= (int) $maxDay ?>"></progress><span class="tabular">+<?= (int) ($r['created_count'] ?? 0) ?> / ✓<?= (int) ($r['resolved_count'] ?? 0) ?></span></li>
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
<?php if (!empty($todayEvents)):
    $nowLocal = new \DateTimeImmutable('now', $calendarTimezone);
    $todayLocal = $nowLocal->format('Y-m-d');
?>
<dialog id="today-events-dialog" class="dialog hd-cal-today-dialog" data-dialog-autoopen aria-labelledby="today-events-title">
    <div class="dialog-header"><h2 id="today-events-title"><?= icon('calendar') ?> Termine heute</h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button></div>
    <div class="dialog-body">
        <p class="text-muted text-sm"><?= e(\App\Services\Helpdesk\HelpdeskCalendarService::longDay($todayLocal)) ?> · <?= count($todayEvents) === 1 ? '1 Termin' : count($todayEvents) . ' Termine' ?> im Help-Desk-Kalender</p>
        <ul class="hd-cal-agenda">
            <?php foreach ($todayEvents as $ev):
                $past = $ev['end_local'] <= $nowLocal;
                $running = !$past && $ev['start_local'] <= $nowLocal;
            ?>
            <li class="hd-cal-agenda-item<?= $past ? ' is-past' : '' ?><?= $running ? ' is-running' : '' ?><?= !empty($ev['ticket_id']) ? ' is-ticket' : '' ?>">
                <span class="hd-cal-agenda-time"><?= e(\App\Services\Helpdesk\HelpdeskCalendarService::timeLabel($ev, $todayLocal)) ?></span>
                <div class="hd-cal-agenda-main">
                    <a href="/helpdesk/kalender/<?= (int) $ev['id'] ?><?= !empty($ev['is_series']) ? '?termin=' . e(rawurlencode((string) $ev['occurrence'])) : '' ?>"><strong><?= e($ev['title']) ?></strong></a>
                    <?php if ($running): ?> <?= badge('läuft', 'success') ?><?php elseif ($past): ?> <?= badge('vorbei', 'neutral') ?><?php endif; ?>
                    <?php if ((int) ($ev['checklist_total'] ?? 0) > 0): ?> <?= badge('Checkliste ' . (int) $ev['checklist_confirmed'] . '/' . (int) $ev['checklist_total'], (int) $ev['checklist_confirmed'] < (int) $ev['checklist_total'] ? 'warning' : 'success') ?><?php endif; ?>
                    <div class="text-xs text-muted">
                        <?php if (!empty($ev['location'])): ?><?= icon('map', 'icon icon-xs') ?> <?= e($ev['location']) ?><?php endif; ?>
                        <?php if (!empty($ev['ticket_id'])): ?> <?= icon('ticket', 'icon icon-xs') ?> <a href="/helpdesk/tickets/<?= (int) $ev['ticket_id'] ?>"><?= e($ev['ticket_number'] ?? 'Ticket') ?></a><?php endif; ?>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="dialog-footer">
        <a class="btn btn-secondary" href="/helpdesk/kalender?ansicht=tag&amp;datum=<?= e($todayLocal) ?>"><?= icon('calendar') ?> Zum Kalender</a>
        <button type="button" class="btn btn-primary" data-dialog-close autofocus>Verstanden</button>
    </div>
</dialog>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
