<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/locations">Standorte</a><?= $row['parent_id'] ? ' › <a href="/locations/' . (int) $row['parent_id'] . '">' . e($row['parent_name']) . '</a>' : '' ?></p>
        <h1 class="page-title"><?= e($row['name']) ?> <?= active_badge($row['is_active']) ?></h1>
        <p class="text-muted mb-0"><?= e($types[$row['type']] ?? $row['type']) ?> · <?= e($row['full_path']) ?></p>
    </div>
    <?php if ($can('locations.manage')): ?>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/locations/new?parent_id=<?= (int) $row['id'] ?>"><?= icon('plus') ?> Unterstandort</a>
        <a class="btn btn-secondary" href="/locations/<?= (int) $row['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
        <?php $toggleUrl = '/locations/' . (int) $row['id'] . '/toggle-active'; $isActive = $row['is_active']; include __DIR__ . '/../partials/toggle_active_form.php'; ?>
    </div>
    <?php endif; ?>
</div>
<div class="grid grid-3">
    <a class="card stat-card" href="/assets?location_id=<?= (int) $row['id'] ?>"><span class="stat-value"><?= (int) $row['asset_count'] ?></span><span class="stat-label">Assets direkt hier</span></a>
    <a class="card stat-card" href="/employees?location_id=<?= (int) $row['id'] ?>"><span class="stat-value"><?= (int) $row['employee_count'] ?></span><span class="stat-label">Mitarbeiter</span></a>
    <div class="card stat-card"><span class="stat-value"><?= (int) $row['child_count'] ?></span><span class="stat-label">Unterstandorte</span></div>
</div>
<div class="grid grid-2 mt-4">
    <div class="card">
        <div class="card-header"><h2>Details</h2></div>
        <dl class="detail-list">
            <dt>Kürzel</dt><dd><?= e($row['code'] ?? '–') ?></dd>
            <dt>Pfad</dt><dd><?= e($row['full_path']) ?></dd>
            <dt>Ebene</dt><dd><?= (int) $row['depth'] + 1 ?></dd>
            <dt>Angelegt</dt><dd><?= fmt_datetime($row['created_at']) ?></dd>
            <dt>Geändert</dt><dd><?= fmt_datetime($row['updated_at']) ?></dd>
        </dl>
    </div>
    <div class="card card-flush">
        <div class="card-header"><h2>Unterstandorte</h2></div>
        <?php if (!$children): ?>
            <div class="table-empty">Keine Unterstandorte.</div>
        <?php else: ?>
        <table class="table table-compact">
            <tbody>
            <?php foreach ($children as $c): ?>
                <tr class="is-clickable <?= (int) $c['is_active'] ? '' : 'is-muted' ?>" data-href="/locations/<?= (int) $c['id'] ?>">
                    <td><strong><?= e($c['name']) ?></strong></td>
                    <td class="text-muted"><?= e($types[$c['type']] ?? $c['type']) ?></td>
                    <td><?= active_badge($c['is_active']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
