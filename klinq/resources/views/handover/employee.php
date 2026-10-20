<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$emp = $status['employee'];
$statusBadge = static fn (string $s): string => badge($statusLabels[$s] ?? $s, match ($s) { 'signed' => 'success', 'draft' => 'info', 'superseded' => 'neutral', default => 'danger' });
$stateTone = match ($status['state']) { 'ok' => 'success', 'outdated' => 'warning', 'draft' => 'info', 'missing' => 'danger', default => 'neutral' };
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/handover">Übergabeprotokolle</a></p>
        <h1 class="page-title"><?= e($emp['display_name']) ?> <?= badge($status['state_label'], $stateTone) ?></h1>
        <p class="text-muted mb-0"><?= e(implode(' · ', array_filter([$emp['personnel_number'] ?? null, $emp['department'] ?? null, $emp['position'] ?? null]))) ?></p>
    </div>
    <div class="page-actions">
        <?php if ($can('employees.view')): ?><a class="btn btn-secondary" href="/employees/<?= (int) $emp['id'] ?>"><?= icon('user') ?> Mitarbeiter</a><?php endif; ?>
        <?php if ($status['draft'] !== null): ?>
            <a class="btn btn-primary" href="/handover/<?= (int) $status['draft']['id'] ?>"><?= icon('pen') ?> Entwurf Version <?= (int) $status['draft']['version'] ?></a>
        <?php elseif ($can('handover.manage')): ?>
            <form method="post" action="/handover/employee/<?= (int) $emp['id'] ?>/draft" class="inline-form"><?= csrf_field() ?><button class="btn btn-primary" type="submit"<?= $status['items'] === [] && $status['current'] === null ? ' disabled title="Keine protokollrelevanten Arbeitsmittel"' : '' ?>><?= icon('plus') ?> Neue Version erstellen</button></form>
        <?php endif; ?>
    </div>
</div>

<?php if ($status['state'] === 'outdated'): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning') ?> <span>Der Bestand hat sich seit Version <?= (int) $status['current']['version'] ?> geändert (<?= count($diff['added']) ?> hinzugekommen, <?= count($diff['removed']) ?> entfernt). Es sollte eine neue Version erstellt und unterschrieben werden.</span></div>
<?php elseif ($status['state'] === 'missing'): ?>
    <div class="alert alert-danger mb-4"><?= icon('warning') ?> <span>Für diesen Mitarbeiter liegt noch kein unterschriebenes Übergabeprotokoll vor.</span></div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card card-flush">
        <div class="card-header"><h2>Aktueller Bestand</h2><span class="text-muted text-sm"><?= count($status['items']) ?> protokollrelevant</span></div>
        <?php if ($status['items'] === []): ?>
            <div class="table-empty">Keine protokollrelevanten Arbeitsmittel zugeordnet.</div>
        <?php else: ?>
        <table class="table table-compact">
            <thead><tr><th>Inventarnr.</th><th>Artikel</th><th>Seriennr.</th><th>Seit</th></tr></thead>
            <tbody>
            <?php $addedIds = array_map(static fn (array $i): int => (int) $i['id'], $diff['added']); ?>
            <?php foreach ($status['items'] as $i): ?>
                <tr class="is-clickable<?= in_array((int) $i['id'], $addedIds, true) ? ' row-highlight' : '' ?>" data-href="/assets/<?= (int) $i['id'] ?>">
                    <td class="mono"><strong><?= e($i['inventory_number']) ?></strong><?= in_array((int) $i['id'], $addedIds, true) && $status['current'] !== null ? ' ' . badge('neu', 'warning') : '' ?></td>
                    <td><?= e(trim(($i['manufacturer_name'] ?? '') . ' ' . $i['article_name'])) ?><br><span class="text-muted text-sm"><?= e($i['asset_type_name']) ?></span></td>
                    <td class="mono"><?= e($i['serial_number'] ?? '–') ?></td>
                    <td><?= fmt_date($i['assigned_at'] ?? null) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($diff['removed'] !== []): ?>
            <div class="card-footer text-sm text-muted">Nicht mehr zugeordnet seit Version <?= (int) $status['current']['version'] ?>:
                <?= e(implode(', ', array_map(static fn (array $i): string => $i['inventory_number'], $diff['removed']))) ?></div>
        <?php endif; ?>
    </div>

    <div class="card card-flush">
        <div class="card-header"><h2>Versionen</h2></div>
        <?php if ($status['versions'] === []): ?>
            <div class="table-empty">Noch kein Protokoll erstellt.</div>
        <?php else: ?>
        <table class="table table-compact">
            <thead><tr><th>Version</th><th>Nummer</th><th>Status</th><th class="text-right">Assets</th><th>Unterschrieben</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($status['versions'] as $v): ?>
                <tr class="is-clickable" data-href="/handover/<?= (int) $v['id'] ?>">
                    <td><strong>v<?= (int) $v['version'] ?></strong></td>
                    <td class="mono"><?= e($v['protocol_number']) ?></td>
                    <td><?= $statusBadge($v['status']) ?></td>
                    <td class="text-right"><?= (int) $v['item_count'] ?></td>
                    <td><?= $v['signed_at'] ? fmt_datetime($v['signed_at']) : '<span class="text-muted">–</span>' ?></td>
                    <td class="text-right"><?php if ($v['pdf_document_id']): ?><a class="btn btn-sm btn-ghost" href="/handover/<?= (int) $v['id'] ?>/pdf" title="PDF"><?= icon('document') ?></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
