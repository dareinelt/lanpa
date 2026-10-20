<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/manufacturers">Hersteller</a></p>
        <h1 class="page-title"><?= e($row['name']) ?> <?= active_badge($row['is_active']) ?></h1>
    </div>
    <?php if ($can('manufacturers.manage')): ?>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/manufacturers/<?= (int) $row['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
        <?php $toggleUrl = '/manufacturers/' . (int) $row['id'] . '/toggle-active'; $isActive = $row['is_active']; include __DIR__ . '/../partials/toggle_active_form.php'; ?>
    </div>
    <?php endif; ?>
</div>
<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Stammdaten</h2></div>
        <dl class="detail-list">
            <dt>Kurzname</dt><dd><?= e($row['short_name'] ?? '–') ?></dd>
            <dt>Webseite</dt><dd><?php if ($row['website']): ?><a href="<?= e($row['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($row['website']) ?></a><?php else: ?>–<?php endif; ?></dd>
            <dt>Kontakt</dt><dd><?= e($row['contact'] ?? '–') ?></dd>
            <dt>Bemerkung</dt><dd><?= nl2br_e($row['note']) ?: '–' ?></dd>
            <dt>Angelegt</dt><dd><?= fmt_datetime($row['created_at']) ?></dd>
            <dt>Geändert</dt><dd><?= fmt_datetime($row['updated_at']) ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Verwendung</h2></div>
        <div class="grid grid-2">
            <a class="stat-card card-compact" href="/articles?manufacturer_id=<?= (int) $row['id'] ?>"><span class="stat-value"><?= (int) $row['article_count'] ?></span><span class="stat-label">Artikel</span></a>
            <a class="stat-card card-compact" href="/assets?manufacturer_id=<?= (int) $row['id'] ?>"><span class="stat-value"><?= (int) $row['asset_count'] ?></span><span class="stat-label">Assets</span></a>
        </div>
        <?php if ($can('articles.manage')): ?>
        <div class="form-actions"><a class="btn btn-secondary" href="/articles/new?manufacturer_id=<?= (int) $row['id'] ?>&return=<?= rawurlencode('/manufacturers/' . (int) $row['id']) ?>"><?= icon('plus') ?> Artikel für diesen Hersteller</a></div>
        <?php endif; ?>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
