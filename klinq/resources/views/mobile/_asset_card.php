<?php /** Erwartet: $asset; optional $compact */ ?>
<div class="card card-compact">
    <div class="m-asset-card">
        <div class="m-asset-icon"><?= icon($asset['asset_type_icon'] ?: 'box') ?></div>
        <div class="m-list-main">
            <p class="m-asset-title"><?= e($asset['inventory_number']) ?></p>
            <p class="m-asset-sub"><?= e($asset['category_name'] ?? $asset['asset_type_name']) ?><?= $asset['name'] || $asset['article_name'] ? ' · ' . e($asset['name'] ?: $asset['article_name']) : '' ?><?= $asset['manufacturer_name'] ? ' (' . e($asset['manufacturer_name']) . ')' : '' ?></p>
            <p class="mb-0 mt-2"><?= badge($asset['status_name'], $asset['status_color']) ?></p>
        </div>
    </div>
    <?php if (empty($compact)): ?>
    <dl class="m-asset-meta">
        <?php if ($asset['serial_number']): ?><dt>Seriennr.</dt><dd class="mono"><?= e($asset['serial_number']) ?></dd><?php endif; ?>
        <dt>Mitarbeiter</dt><dd><?= $asset['employee_name'] ? e($asset['employee_name']) : '<span class="text-muted">–</span>' ?></dd>
        <dt>Standort</dt><dd><?= $asset['location_path'] ? e($asset['location_path']) : '<span class="text-muted">–</span>' ?></dd>
        <dt>Kostenst.</dt><dd><?= $asset['cost_center_number'] ? '<span class="mono">' . e($asset['cost_center_number']) . '</span>' : '<span class="text-muted">–</span>' ?></dd>
    </dl>
    <?php endif; ?>
</div>
