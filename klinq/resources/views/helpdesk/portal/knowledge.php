<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'portal-knowledge';
$areaLabel = 'Portal';
$hasFilter = ($filters['q'] ?? '') !== '' || ($filters['category_id'] ?? '') !== '';
?>
<div class="page-header"><div><h1 class="page-title">Hilfe &amp; Anleitungen</h1><p class="page-subtitle text-muted"><?= (int) $paginator->total ?> Artikel</p></div><div class="page-actions"><a class="btn btn-ghost" href="/portal"><?= icon('arrow-left') ?> Portal</a></div></div>
<form method="get" action="/portal/knowledge" class="filter-bar mb-4" role="search">
    <div class="form-group filter-wide"><label for="filter-q">Suche</label><input id="filter-q" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Wonach suchen Sie?"></div>
    <div class="form-group"><label for="filter-category">Kategorie</label><select id="filter-category" name="category_id" data-autosubmit><option value="">Alle</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($filters['category_id'] ?? '', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <button type="submit" class="btn btn-secondary"><?= icon('search') ?> Suchen</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="/portal/knowledge"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>
<?php if ($rows === []): ?>
    <div class="card empty-state">Keine passenden Artikel gefunden.</div>
<?php else: ?>
    <div class="kb-card-list mb-4">
        <?php foreach ($rows as $article): ?>
            <a class="card kb-card" href="/portal/knowledge/<?= (int) $article['id'] ?>"><h3><?= e($article['title']) ?></h3><p><?= e($article['summary'] ?? '') ?></p><span class="text-xs text-muted"><?= e($article['category_name'] ?? 'Ohne Kategorie') ?> · <?= (int) ($article['view_count'] ?? 0) ?> Aufrufe</span></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
