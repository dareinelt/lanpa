<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $assets = $assets ?? []; $movements = $movements ?? []; ?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/employees">Mitarbeiter</a></p>
        <h1 class="page-title"><?= e($row['display_name']) ?> <?= active_badge($row['is_active']) ?> <?= $row['source'] === 'ad' ? badge('AD', 'info') : '' ?></h1>
        <p class="text-muted mb-0"><?= e(implode(' · ', array_filter([$row['position'], $row['department']]))) ?></p>
    </div>
    <div class="page-actions">
        <?php if ($can('employees.manage')): ?>
            <a class="btn btn-secondary" href="/employees/<?= (int) $row['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
            <?php if ($row['source'] !== 'ad'): $toggleUrl = '/employees/' . (int) $row['id'] . '/toggle-active'; $isActive = $row['is_active']; include __DIR__ . '/../partials/toggle_active_form.php'; endif; ?>
        <?php endif; ?>
    </div>
</div>
<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Person</h2></div>
        <dl class="detail-list">
            <dt>Benutzername</dt><dd class="mono"><?= e($row['username'] ?? '–') ?></dd>
            <dt>Personalnummer</dt><dd class="mono"><?= e($row['personnel_number'] ?? '–') ?></dd>
            <dt>E-Mail</dt><dd><?= $row['email'] ? '<a href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a>' : '–' ?></dd>
            <dt>Telefon</dt><dd><?= e($row['phone'] ?? '–') ?></dd>
            <dt>Abteilung</dt><dd><?= e($row['department'] ?? '–') ?></dd>
            <dt>Position</dt><dd><?= e($row['position'] ?? '–') ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Zuordnung & Herkunft</h2></div>
        <dl class="detail-list">
            <dt>Standort</dt><dd><?= $row['location_id'] ? '<a href="/locations/' . (int) $row['location_id'] . '">' . e($row['location_path']) . '</a>' : e($row['ad_location'] ?? '–') ?></dd>
            <dt>Kostenstelle</dt><dd><?= $row['cost_center_id'] ? '<span class="mono">' . e($row['cost_center_number']) . '</span> ' . e($row['cost_center_name']) : e($row['ad_cost_center'] ?? '–') ?></dd>
            <dt>Quelle</dt><dd><?= $row['source'] === 'ad' ? 'Active Directory' : 'Manuell angelegt' ?></dd>
            <?php if ($row['source'] === 'ad'): ?>
            <dt>AD-GUID</dt><dd class="mono text-sm"><?= e($row['ad_object_guid'] ?? '–') ?></dd>
            <dt>Zuletzt synchronisiert</dt><dd><?= fmt_datetime($row['last_synced_at']) ?></dd>
            <?php endif; ?>
            <?php if (!(int) $row['is_active']): ?><dt>Deaktiviert am</dt><dd><?= fmt_datetime($row['deactivated_at']) ?></dd><?php endif; ?>
            <dt>Angelegt</dt><dd><?= fmt_datetime($row['created_at']) ?></dd>
        </dl>
    </div>
</div>
<?php if (!empty($handover)): $h = $handover; $tone = match ($h['state']) { 'ok' => 'success', 'outdated' => 'warning', 'draft' => 'info', 'missing' => 'danger', default => 'neutral' }; ?>
<div class="card mt-4">
    <div class="card-header"><h2>Übergabeprotokoll <?= badge($h['state_label'], $tone) ?></h2><a class="btn btn-link btn-sm" href="/handover/employee/<?= (int) $row['id'] ?>">Details &amp; Versionen</a></div>
    <div class="cluster cluster-between">
        <div class="text-sm">
            <?php if ($h['current'] !== null): ?>
                Gültig: <a href="/handover/<?= (int) $h['current']['id'] ?>">Version <?= (int) $h['current']['version'] ?></a> vom <?= fmt_datetime($h['current']['signed_at']) ?> mit <?= (int) $h['current']['item_count'] ?> Arbeitsmitteln<?= $h['current']['pdf_document_id'] ? ' · <a href="/handover/' . (int) $h['current']['id'] . '/pdf">PDF</a>' : '' ?>.
            <?php else: ?>
                Noch kein unterschriebenes Protokoll.
            <?php endif; ?>
            Aktuell <?= count($h['items']) ?> protokollrelevante Arbeitsmittel.
        </div>
        <div class="cluster">
            <?php if ($h['draft'] !== null): ?>
                <a class="btn btn-sm btn-primary" href="/handover/<?= (int) $h['draft']['id'] ?>"><?= icon('pen') ?> Entwurf v<?= (int) $h['draft']['version'] ?></a>
            <?php elseif ($can('handover.manage') && in_array($h['state'], ['missing', 'outdated'], true)): ?>
                <form method="post" action="/handover/employee/<?= (int) $row['id'] ?>/draft" class="inline-form"><?= csrf_field() ?><button class="btn btn-sm btn-primary" type="submit"><?= icon('plus') ?> Protokoll erstellen</button></form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="card card-flush mt-4">
    <div class="card-header"><h2>Zugeordnete Assets</h2><a class="btn btn-link btn-sm" href="/assets?employee_id=<?= (int) $row['id'] ?>">Alle anzeigen</a></div>
    <?php if (!$assets): ?>
        <div class="table-empty">Aktuell sind diesem Mitarbeiter keine Assets zugeordnet.</div>
    <?php else: ?>
    <table class="table table-compact">
        <thead><tr><th>Inventarnummer</th><th>Bezeichnung</th><th>Typ</th><th>Seit</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
            <tr class="is-clickable" data-href="/assets/<?= (int) $a['id'] ?>">
                <td class="mono"><strong><?= e($a['inventory_number']) ?></strong></td>
                <td><?= e($a['name'] ?? '') ?></td>
                <td><?= e($a['asset_type_name'] ?? '') ?></td>
                <td><?= fmt_date($a['assigned_at'] ?? null) ?></td>
                <td><?= badge($a['status_name'] ?? '', $a['status_color'] ?? 'neutral') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
