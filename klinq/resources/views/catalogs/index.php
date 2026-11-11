<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isTypes = $kind === 'asset-types';
$newUrl = '/catalogs/' . $kind . '/new' . (!$isTypes && $typeFilter !== null ? '?asset_type_id=' . (int) $typeFilter : '');
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Kataloge</h1>
        <p class="page-subtitle text-muted mb-0">Auswahllisten der Assetverwaltung. Einträge werden nicht gelöscht, sondern deaktiviert – deaktivierte Einträge stehen bei neuen Datensätzen nicht mehr zur Auswahl.</p>
    </div>
    <div class="page-actions"><a class="btn btn-primary" href="<?= e($newUrl) ?>"><?= icon('plus') ?> <?= e($kinds[$kind][1]) ?> anlegen</a></div>
</div>
<div class="status-tabs admin-tabs" role="tablist" aria-label="Kataloge">
    <?php foreach ($kinds as $key => [$label]): ?>
        <a class="status-tab<?= $kind === $key ? ' is-active' : '' ?>" href="/catalogs?tab=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<?php if (!$isTypes): ?>
<form method="get" action="/catalogs" class="filter-bar" role="search">
    <input type="hidden" name="tab" value="asset-categories">
    <div class="form-group filter-compact">
        <label for="filter-type">Assettyp</label>
        <select id="filter-type" name="asset_type_id" data-autosubmit>
            <option value="">Alle Assettypen</option>
            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($typeFilter, $t['id']) ?>><?= e($t['name']) ?><?= (int) $t['is_active'] === 1 ? '' : ' (inaktiv)' ?></option><?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
</form>
<?php endif; ?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty"><?= $isTypes ? 'Keine Assettypen vorhanden.' : 'Keine Kategorien gefunden.' ?></div>
    <?php elseif ($isTypes): ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Assettyp</th><th>Code</th><th>Inventarnummer</th><th>Kennungen</th><th class="num">Kategorien</th><th class="num">Artikel</th><th class="num">Assets</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= (int) $r['is_active'] ? '' : 'is-muted' ?>">
                <?php $features = array_keys(array_filter(['Seriennr.' => $r['has_serial_number'], 'MAC' => $r['has_mac_address'], 'IMEI' => $r['has_imei'], 'Lizenzen' => $r['supports_licenses']], static fn ($v): bool => (int) $v === 1)); ?>
                <td class="nowrap"><?= icon((string) ($r['icon'] ?: 'box'), 'icon icon-sm icon-muted') ?> <strong><?= e($r['name']) ?></strong></td>
                <td class="mono"><?= e($r['code']) ?></td>
                <td class="nowrap"><?= (int) $r['requires_inventory_number'] === 1 ? '<span class="mono"><strong>' . e((string) $r['inventory_prefix']) . '</strong></span> <span class="text-muted text-sm">z. B. ' . e((string) $r['inventory_prefix']) . date('y') . '001</span>' : badge('Lagerartikel', 'neutral') ?></td>
                <td class="text-sm"><?= $features !== [] ? e(implode(' · ', $features)) : '<span class="text-muted">–</span>' ?></td>
                <td class="num"><a href="/catalogs?tab=asset-categories&amp;asset_type_id=<?= (int) $r['id'] ?>"><?= (int) $r['category_count'] ?></a></td>
                <td class="num"><a href="/articles?asset_type_id=<?= (int) $r['id'] ?>&amp;active="><?= (int) $r['article_count'] ?></a></td>
                <td class="num"><a href="/assets?asset_type_id=<?= (int) $r['id'] ?>"><?= (int) $r['asset_count'] ?></a></td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions">
                    <a class="btn btn-ghost btn-sm" href="/catalogs/asset-types/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a>
                    <?php $toggleUrl = '/catalogs/asset-types/' . (int) $r['id'] . '/toggle-active'; $isActive = $r['is_active']; $small = true; include __DIR__ . '/../partials/toggle_active_form.php'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Kategorie</th><th>Assettyp</th><th class="num">Sortierung</th><th class="num">Artikel</th><th class="num">Assets</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= (int) $r['is_active'] ? '' : 'is-muted' ?>">
                <td><strong><?= e($r['name']) ?></strong></td>
                <td><?= e($r['asset_type_name']) ?><?= (int) $r['asset_type_active'] === 1 ? '' : ' ' . badge('Typ inaktiv', 'neutral') ?></td>
                <td class="num"><?= (int) $r['sort_order'] ?></td>
                <td class="num"><?= (int) $r['article_count'] ?></td>
                <td class="num"><a href="/assets?asset_category_id=<?= (int) $r['id'] ?>"><?= (int) $r['asset_count'] ?></a></td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions">
                    <a class="btn btn-ghost btn-sm" href="/catalogs/asset-categories/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a>
                    <?php $toggleUrl = '/catalogs/asset-categories/' . (int) $r['id'] . '/toggle-active'; $isActive = $r['is_active']; $small = true; include __DIR__ . '/../partials/toggle_active_form.php'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
