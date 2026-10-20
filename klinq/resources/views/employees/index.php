<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Mitarbeiter</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Einträge</p></div>
    <div class="page-actions">
        <?php if ($can('employees.sync')): ?><a class="btn btn-secondary" href="/admin/ad-sync"><?= icon('refresh') ?> AD-Synchronisation</a><?php endif; ?>
        <?php if ($can('employees.manage')): ?><a class="btn btn-primary" href="/employees/new"><?= icon('plus') ?> Mitarbeiter anlegen</a><?php endif; ?>
    </div>
</div>
<?php ob_start(); ?>
    <div class="form-group">
        <label for="filter-department">Abteilung</label>
        <select id="filter-department" name="department" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($departments as $d): ?><option value="<?= e($d) ?>"<?= selected($filters['department'], $d) ?>><?= e($d) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-source">Quelle</label>
        <select id="filter-source" name="source" data-autosubmit>
            <option value="">Alle</option>
            <option value="ad"<?= selected($filters['source'], 'ad') ?>>Active Directory</option>
            <option value="manual"<?= selected($filters['source'], 'manual') ?>>Manuell</option>
        </select>
    </div>
<?php $extraFilters = ob_get_clean(); $placeholder = 'Name, Benutzername, E-Mail, Personalnummer …'; include __DIR__ . '/../partials/filter_bar.php'; ?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Mitarbeiter gefunden.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Name</th><th>Benutzername</th><th>Personalnr.</th><th>Abteilung</th><th>Standort</th><th>Kostenstelle</th><th>Quelle</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="is-clickable <?= (int) $r['is_active'] ? '' : 'is-muted' ?>" data-href="/employees/<?= (int) $r['id'] ?>">
                <td><strong><?= e($r['display_name']) ?></strong><?= $r['email'] ? '<br><span class="text-muted text-sm">' . e($r['email']) . '</span>' : '' ?></td>
                <td class="mono"><?= e($r['username'] ?? '–') ?></td>
                <td class="mono"><?= e($r['personnel_number'] ?? '–') ?></td>
                <td><?= e($r['department'] ?? '–') ?></td>
                <td><?= e($r['location_path'] ?? $r['ad_location'] ?? '–') ?></td>
                <td class="mono"><?= e($r['cost_center_number'] ?? $r['ad_cost_center'] ?? '–') ?></td>
                <td><?= $r['source'] === 'ad' ? badge('AD', 'info') : badge('Manuell', 'neutral') ?></td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions"><?php if ($can('employees.manage')): ?><a class="btn btn-ghost btn-sm" href="/employees/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
