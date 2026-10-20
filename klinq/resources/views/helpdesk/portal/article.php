<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'portal-knowledge';
$areaLabel = 'Portal';
$articleTags = array_values(array_filter(array_map('trim', explode(',', (string) ($article['tag_names'] ?? '')))));
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted"><a href="/portal/knowledge"><?= icon('arrow-left') ?> Hilfe &amp; Anleitungen</a></p>
        <h1 class="page-title"><?= e($article['title']) ?></h1>
        <?php if (!empty($article['summary'])): ?><p class="kb-article-summary"><?= e($article['summary']) ?></p><?php endif; ?>
    </div>
    <div class="page-actions"><a class="btn btn-primary" href="/portal/tickets/new"><?= icon('plus') ?> Anfrage erstellen</a></div>
</div>
<article class="card">
    <dl class="detail-list mb-4">
        <dt>Kategorie</dt><dd><?= e($article['category_name'] ?? '–') ?></dd>
        <dt>Veröffentlicht</dt><dd><?= fmt_datetime($article['published_at'] ?? null) ?></dd>
        <dt>Aktualisiert</dt><dd><?= fmt_datetime($article['updated_at'] ?? null) ?></dd>
    </dl>
    <div class="kb-article-body"><?= nl2br_e($article['body'] ?? '') ?></div>
    <?php if ($articleTags !== []): ?><div class="tag-list mt-4"><?php foreach ($articleTags as $tag): ?><span class="tag-chip"><?= e($tag) ?></span><?php endforeach; ?></div><?php endif; ?>
</article>
<div class="card mt-4">
    <div class="card-header"><h2>War das hilfreich?</h2></div>
    <p class="text-muted">Wenn Ihr Anliegen damit nicht gelöst ist, erstellen Sie eine neue Anfrage und verweisen Sie auf diesen Artikel.</p>
    <a class="btn btn-primary" href="/portal/tickets/new"><?= icon('plus') ?> Anfrage erstellen</a>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
