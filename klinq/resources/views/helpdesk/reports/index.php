<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk-reports';
$areaLabel = 'Help Desk';
$query = ['from' => $from, 'to' => $to];
$table = static function (string $empty, array $headers, array $rows, callable $render): void { ?>
    <?php if ($rows === []): ?><div class="table-empty"><?= e($empty) ?></div><?php else: ?>
    <div class="table-wrapper"><table class="table table-compact"><thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $r): ?><?= $render($r) ?><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
<?php };
$minutes = static fn (mixed $m): string => $m === null ? '–' : ((int) $m >= 60 ? intdiv((int) $m, 60) . ' h ' . ((int) $m % 60) . ' min' : (int) $m . ' min'); ?>
<div class="page-header">
    <div><h1 class="page-title">Help-Desk-Berichte</h1><p class="page-subtitle text-muted"><?= fmt_date($from) ?> bis <?= fmt_date($to) ?></p></div>
    <div class="page-actions">
        <?php if ($can('helpdesk.export')): ?><a class="btn btn-secondary" href="/helpdesk/reports/export?from=<?= e($from) ?>&amp;to=<?= e($to) ?>"><?= icon('download') ?> Export</a><?php endif; ?>
    </div>
</div>
<form method="get" action="/helpdesk/reports" class="filter-bar mb-4">
    <div class="form-group"><label for="f-from">Von</label><input id="f-from" type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-group"><label for="f-to">Bis</label><input id="f-to" type="date" name="to" value="<?= e($to) ?>"></div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Zeitraum anwenden</button>
</form>
<?php $sla = $report['sla'] ?? []; ?>
<div class="card mb-4">
    <div class="kpi-row">
        <div><span class="kpi-value"><?= (int) ($sla['total'] ?? 0) ?></span><span class="kpi-label">Tickets</span></div>
        <div><span class="kpi-value"><?= e($minutes($sla['avg_response_minutes'] ?? null)) ?></span><span class="kpi-label">Ø Reaktion</span></div>
        <div><span class="kpi-value"><?= e($minutes($sla['avg_resolution_minutes'] ?? null)) ?></span><span class="kpi-label">Ø Lösung</span></div>
        <div><span class="kpi-value"><?= (int) ($sla['response_breached'] ?? 0) ?> / <?= (int) ($sla['resolution_breached'] ?? 0) ?></span><span class="kpi-label">SLA verletzt (R/L)</span></div>
        <div><span class="kpi-value"><?= (int) ($sla['escalated'] ?? 0) ?></span><span class="kpi-label">Eskaliert</span></div>
        <div><span class="kpi-value"><?= (int) ($sla['reopened'] ?? 0) ?></span><span class="kpi-label">Wiedereröffnet</span></div>
    </div>
</div>
<div class="grid grid-2">
    <?php foreach (['by_status' => 'Nach Status', 'by_priority' => 'Nach Priorität', 'by_type' => 'Nach Typ'] as $key => $heading): ?>
    <div class="card card-flush"><div class="card-header"><h2><?= e($heading) ?></h2></div><?php $table('Keine Daten.', ['Bezeichnung', 'Anzahl'], $report[$key] ?? [], static fn (array $r): string => '<tr><td>' . badge($r['label'] ?? '–', $r['color'] ?? 'neutral') . '</td><td class="mono">' . (int) ($r['cnt'] ?? 0) . '</td></tr>'); ?></div>
    <?php endforeach; ?>
    <?php foreach (['by_category' => 'Nach Kategorie', 'by_group' => 'Nach Gruppe', 'by_assignee' => 'Nach Bearbeiter', 'by_requester' => 'Nach Melder'] as $key => $heading): ?>
    <div class="card card-flush"><div class="card-header"><h2><?= e($heading) ?></h2></div><?php $table('Keine Daten.', ['Bezeichnung', 'Tickets', 'Gelöst'], $report[$key] ?? [], static fn (array $r): string => '<tr><td>' . e($r['label'] ?? '–') . (!empty($r['department']) ? '<div class="text-xs text-muted">' . e($r['department']) . '</div>' : '') . '</td><td class="mono">' . (int) ($r['cnt'] ?? 0) . '</td><td class="mono">' . (array_key_exists('resolved_count', $r) ? (int) $r['resolved_count'] : '–') . '</td></tr>'); ?></div>
    <?php endforeach; ?>
    <div class="card card-flush"><div class="card-header"><h2>Pro Tag</h2></div><?php $table('Keine Daten.', ['Tag', 'Erstellt', 'Gelöst'], $report['per_day'] ?? [], static fn (array $r): string => '<tr><td>' . fmt_date($r['day'] ?? null) . '</td><td class="mono">' . (int) ($r['created_count'] ?? 0) . '</td><td class="mono">' . (int) ($r['resolved_count'] ?? 0) . '</td></tr>'); ?></div>
    <div class="card card-flush"><div class="card-header"><h2>Arbeitszeit</h2></div><?php $table('Keine Arbeitszeiten im Zeitraum.', ['Benutzer', 'Tickets', 'Einträge', 'Zeit'], $report['worklog'] ?? [], fn (array $r): string => '<tr><td>' . e($r['label'] ?? '–') . '</td><td class="mono">' . (int) ($r['tickets'] ?? 0) . '</td><td class="mono">' . (int) ($r['entries'] ?? 0) . '</td><td class="mono nowrap">' . e($minutes($r['minutes'] ?? null)) . '</td></tr>'); ?></div>
    <div class="card card-flush"><div class="card-header"><h2>SLA-Verletzungen</h2></div><?php $table('Keine SLA-Verletzungen.', ['Ticket', 'Status', 'Priorität', 'Fällig', 'Bearbeiter'], $report['sla_breaches'] ?? [], static fn (array $r): string => '<tr data-href="/helpdesk/tickets/' . (int) ($r['id'] ?? 0) . '"><td><a class="ticket-number" href="/helpdesk/tickets/' . (int) ($r['id'] ?? 0) . '">' . e($r['number'] ?? '–') . '</a><div class="text-xs text-muted">' . e($r['subject'] ?? '') . '</div></td><td>' . badge($r['status_name'] ?? '–', $r['status_color'] ?? 'neutral') . '</td><td>' . badge($r['priority_name'] ?? '–', $r['priority_color'] ?? 'neutral') . '</td><td class="nowrap">' . fmt_datetime($r['resolution_due_at'] ?? $r['response_due_at'] ?? null) . '</td><td>' . e($r['assignee_name'] ?? $r['group_name'] ?? '–') . '</td></tr>'); ?></div>
    <div class="card card-flush"><div class="card-header"><h2>Wiederkehrende Assets</h2></div><?php $table('Keine wiederkehrenden Asset-Meldungen.', ['Asset', 'Tickets'], $report['recurring_assets'] ?? [], static fn (array $r): string => '<tr data-href="/assets/' . (int) ($r['asset_id'] ?? 0) . '"><td><span class="mono">' . e($r['inventory_number'] ?? '–') . '</span><div class="text-xs text-muted">' . e($r['asset_name'] ?? '') . '</div></td><td class="mono">' . (int) ($r['cnt'] ?? 0) . '</td></tr>'); ?></div>
    <div class="card card-flush"><div class="card-header"><h2>Wiederkehrende Beziehungen</h2></div><?php $table('Keine wiederkehrenden Beziehungen.', ['Muster', 'Anzahl'], $report['recurring_relations'] ?? [], static fn (array $r): string => '<tr><td>' . e($r['label'] ?? $r['type'] ?? $r['subject'] ?? '–') . '</td><td class="mono">' . (int) ($r['cnt'] ?? $r['count'] ?? 0) . '</td></tr>'); ?></div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
