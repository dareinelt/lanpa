<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Hersteller</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Einträge</p></div>
    <?php if ($can('manufacturers.manage')): ?>
    <div class="page-actions"><a class="btn btn-primary" href="/manufacturers/new"><?= icon('plus') ?> Hersteller anlegen</a></div>
    <?php endif; ?>
</div>
<?php $placeholder = 'Name oder Kurzname …'; include __DIR__ . '/../partials/filter_bar.php'; ?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Hersteller gefunden.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Name</th><th>Kurzname</th><th>Webseite</th><th class="num">Artikel</th><th class="num">Assets</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="is-clickable <?= (int) $r['is_active'] ? '' : 'is-muted' ?>" data-href="/manufacturers/<?= (int) $r['id'] ?>">
                <td><strong><?= e($r['name']) ?></strong></td>
                <td><?= e($r['short_name'] ?? '–') ?></td>
                <td><?php if ($r['website']): ?><a href="<?= e($r['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('~^https?://~', '', $r['website'])) ?></a><?php else: ?>–<?php endif; ?></td>
                <td class="num"><?= (int) $r['article_count'] ?></td>
                <td class="num"><?= (int) $r['asset_count'] ?></td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions">
                    <?php if ($can('manufacturers.manage')): ?><a class="btn btn-ghost btn-sm" href="/manufacturers/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a><?php endif; ?>
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
