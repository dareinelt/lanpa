<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Artikel</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Einträge</p></div>
    <?php if ($can('articles.manage')): ?>
    <div class="page-actions"><a class="btn btn-primary" href="/articles/new"><?= icon('plus') ?> Artikel anlegen</a></div>
    <?php endif; ?>
</div>
<?php
ob_start(); ?>
    <div class="form-group">
        <label for="filter-manufacturer">Hersteller</label>
        <select id="filter-manufacturer" name="manufacturer_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($manufacturers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected($filters['manufacturer_id'], $m['id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-type">Assettyp</label>
        <select id="filter-type" name="asset_type_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($assetTypes as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($filters['asset_type_id'], $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-handover">Übergabeprotokoll</label>
        <select id="filter-handover" name="handover" data-autosubmit>
            <option value="">Alle</option>
            <option value="1"<?= selected($filters['handover'], '1') ?>>Nur relevante</option>
            <option value="0"<?= selected($filters['handover'], '0') ?>>Nicht relevante</option>
        </select>
    </div>
<?php $extraFilters = ob_get_clean(); $placeholder = 'Bezeichnung, Artikelnummer, Hersteller …'; include __DIR__ . '/../partials/filter_bar.php'; ?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Artikel gefunden.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Hersteller</th><th>Bezeichnung</th><th>Artikelnummer</th><th>Typ</th><th>Kategorie</th><th>Protokoll</th><th>Bestand</th><th class="num">Assets</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= (int) $r['is_active'] ? '' : 'is-muted' ?>">
                <td><a href="/manufacturers/<?= (int) $r['manufacturer_id'] ?>"><?= e($r['manufacturer_name']) ?></a></td>
                <td><strong><?= e($r['name']) ?></strong></td>
                <td class="mono"><?= e($r['article_number'] ?? '–') ?></td>
                <td><?= e($r['asset_type_name']) ?></td>
                <td><?= e($r['category_name'] ?? '–') ?></td>
                <td><?= (int) ($r['is_handover_relevant'] ?? 0) ? badge('Übergabe', 'info') : '<span class="text-muted">–</span>' ?></td>
                <td><?= (int) ($r['is_consumable'] ?? 0) ? badge((int) $r['stock_quantity'] . ' / ' . (int) $r['minimum_stock'], (int) $r['stock_quantity'] <= (int) $r['minimum_stock'] ? 'warning' : 'info') : '<span class="text-muted">–</span>' ?></td>
                <td class="num"><a href="/assets?article_id=<?= (int) $r['id'] ?>"><?= (int) $r['asset_count'] ?></a></td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions">
                    <?php if ($can('articles.manage')): ?><a class="btn btn-ghost btn-sm" href="/articles/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a><?php endif; ?>
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
