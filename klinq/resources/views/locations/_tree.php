<?php /** Rekursiver Baum. Erwartet: $nodes, $types, $can */ ?>
<ul>
<?php foreach ($nodes as $node): ?>
    <li>
        <div class="tree-node <?= (int) $node['is_active'] ? '' : 'is-inactive' ?>">
            <?= icon(match ($node['type']) { 'site' => 'map', 'building' => 'home', 'room' => 'tag', 'warehouse' => 'box', 'workplace' => 'laptop', default => 'chevron-right' }) ?>
            <a href="/locations/<?= (int) $node['id'] ?>"><strong><?= e($node['name']) ?></strong></a>
            <span class="tree-type"><?= e($types[$node['type']] ?? $node['type']) ?><?= $node['code'] ? ' · ' . e($node['code']) : '' ?></span>
            <?php if ((int) $node['asset_count'] > 0): ?><a class="badge badge-neutral" href="/assets?location_id=<?= (int) $node['id'] ?>" title="Assets an diesem Standort"><?= (int) $node['asset_count'] ?></a><?php endif; ?>
            <?php if (!(int) $node['is_active']): ?><?= badge('Inaktiv', 'neutral') ?><?php endif; ?>
            <?php if ($can('locations.manage')): ?>
            <span class="tree-actions">
                <a class="btn btn-ghost btn-sm" href="/locations/new?parent_id=<?= (int) $node['id'] ?>" title="Unterstandort anlegen"><?= icon('plus') ?></a>
                <a class="btn btn-ghost btn-sm" href="/locations/<?= (int) $node['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a>
            </span>
            <?php endif; ?>
        </div>
        <?php if ($node['children']): $nodes = $node['children']; include __DIR__ . '/_tree.php'; endif; ?>
    </li>
<?php endforeach; ?>
</ul>
