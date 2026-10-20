<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Kostenstellen</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Einträge</p></div>
    <?php if ($can('costcenters.manage')): ?>
    <div class="page-actions"><a class="btn btn-primary" href="/cost-centers/new"><?= icon('plus') ?> Kostenstelle anlegen</a></div>
    <?php endif; ?>
</div>
<?php $placeholder = 'Nummer oder Beschreibung …'; include __DIR__ . '/../partials/filter_bar.php'; ?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Kostenstellen gefunden.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Nummer</th><th>Beschreibung</th><th>Standort</th><th class="num">Assets</th><th class="num">Mitarbeiter</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= (int) $r['is_active'] ? '' : 'is-muted' ?>">
                <td class="mono"><strong><?= e($r['number']) ?></strong></td>
                <td><?= e($r['description']) ?></td>
                <td><?= $r['location_id'] ? '<a href="/locations/' . (int) $r['location_id'] . '">' . e($r['location_path']) . '</a>' : '–' ?></td>
                <td class="num"><a href="/assets?cost_center_id=<?= (int) $r['id'] ?>"><?= (int) $r['asset_count'] ?></a></td>
                <td class="num"><?= (int) $r['employee_count'] ?></td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions">
                    <?php if ($can('costcenters.manage')): ?>
                        <a class="btn btn-ghost btn-sm" href="/cost-centers/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a>
                        <?php $toggleUrl = '/cost-centers/' . (int) $r['id'] . '/toggle-active'; $isActive = $r['is_active']; $small = true; include __DIR__ . '/../partials/toggle_active_form.php'; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
