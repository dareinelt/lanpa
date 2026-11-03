<?php /** Rekursiver Baum. Erwartet: $nodes, $types, $can; optional $treeParentId */ ?>
<ul<?= empty($treeParentId) ? ' data-tree-root' : '' ?>>
<?php foreach ($nodes as $node): ?>
    <li class="tree-item" data-tree-id="<?= (int) $node['id'] ?>" data-tree-search="<?= e(mb_strtolower(trim($node['name'] . ' ' . ($node['code'] ?? '')))) ?>">
        <div class="tree-node <?= (int) $node['is_active'] ? '' : 'is-inactive' ?>">
            <button type="button" class="tree-toggle<?= $node['children'] ? '' : ' is-leaf' ?>" data-tree-toggle aria-expanded="true" title="Unterstandorte einklappen" aria-label="Unterstandorte von <?= e($node['name']) ?> einklappen"<?= $node['children'] ? '' : ' disabled' ?>><?= icon('chevron-down') ?></button>
            <?php if ($can('locations.manage')): ?><span class="tree-handle" data-tree-handle draggable="true" title="Ziehen zum Verschieben" aria-hidden="true"><?= icon('grip') ?></span><?php endif; ?>
            <?= icon(match ($node['type']) { 'site' => 'map', 'building' => 'home', 'floor' => 'layers', 'room' => 'tag', 'warehouse' => 'box', 'workplace' => 'laptop', default => 'chevron-right' }) ?>
            <a href="/locations/<?= (int) $node['id'] ?>" draggable="false"><strong data-tree-name><?= e($node['name']) ?></strong></a>
            <span class="tree-type"><?= e($types[$node['type']] ?? $node['type']) ?><?= $node['code'] ? ' · <span data-tree-code>' . e($node['code']) . '</span>' : '' ?></span>
            <?php if ((int) $node['asset_count'] > 0): ?><a class="badge badge-neutral" href="/assets?location_id=<?= (int) $node['id'] ?>" title="Assets an diesem Standort" draggable="false"><?= (int) $node['asset_count'] ?></a><?php endif; ?>
            <?php if (!(int) $node['is_active']): ?><?= badge('Inaktiv', 'neutral') ?><?php endif; ?>
            <?php if ($can('locations.manage')): ?>
            <span class="tree-actions">
                <a class="btn btn-ghost btn-sm" href="/locations/new?parent_id=<?= (int) $node['id'] ?>" title="Unterstandort anlegen" draggable="false"><?= icon('plus') ?></a>
                <a class="btn btn-ghost btn-sm" href="/locations/<?= (int) $node['id'] ?>/edit" title="Bearbeiten" draggable="false"><?= icon('pen') ?></a>
            </span>
            <?php endif; ?>
        </div>
        <?php /* Muss der letzte Zugriff auf $node bleiben: das rekursive include überschreibt die Variablen. */ ?>
        <?php if ($node['children']): $nodes = $node['children']; $treeParentId = (int) $node['id']; include __DIR__ . '/_tree.php'; endif; ?>
    </li>
<?php endforeach; ?>
</ul>