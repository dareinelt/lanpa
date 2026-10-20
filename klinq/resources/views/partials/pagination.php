<?php
/** Erwartet: $paginator (App\Support\Paginator), $basePath, $query */
if (!isset($paginator) || $paginator->pages <= 1 && $paginator->total <= $paginator->perPage) { return; }
?>
<nav class="pagination" aria-label="Seiten">
    <span class="text-muted text-sm"><?= $paginator->from() ?>–<?= $paginator->to() ?> von <?= $paginator->total ?></span>
    <div class="pagination-links">
        <?php if ($paginator->hasPrevious()): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(query_url($basePath, $query, ['page' => $paginator->page - 1])) ?>" rel="prev"><?= icon('chevron-left') ?> Zurück</a>
        <?php endif; ?>
        <span class="text-sm">Seite <?= $paginator->page ?> / <?= $paginator->pages ?></span>
        <?php if ($paginator->hasNext()): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(query_url($basePath, $query, ['page' => $paginator->page + 1])) ?>" rel="next">Weiter <?= icon('chevron-right') ?></a>
        <?php endif; ?>
    </div>
</nav>
