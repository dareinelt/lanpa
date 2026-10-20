<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'portal';
$areaLabel = 'Portal';
$tabs = ['portal_open' => 'Offen', 'portal_resolved' => 'Gelöst', 'all' => 'Alle'];
$hasFilter = ($q ?? '') !== '';
?>
<div class="page-header">
    <div><h1 class="page-title">Meine Tickets</h1><p class="page-subtitle text-muted"><?= (int) $paginator->total ?> Anfragen</p></div>
    <div class="page-actions"><?php if ($canCreate): ?><a class="btn btn-primary" href="/portal/tickets/new"><?= icon('plus') ?> Neue Anfrage</a><?php endif; ?></div>
</div>
<div class="status-tabs mb-4" role="tablist" aria-label="Ansichten">
    <?php foreach ($tabs as $key => $label): ?><a class="status-tab<?= $view === $key ? ' is-active' : '' ?>" href="<?= e(query_url('/portal/tickets', $query, ['view' => $key, 'page' => null])) ?>"><?= e($label) ?> <span class="status-tab-count"><?= (int) ($counts[$key] ?? 0) ?></span></a><?php endforeach; ?>
</div>
<form method="get" action="/portal/tickets" class="filter-bar mb-4" role="search">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="form-group filter-wide"><label for="filter-q">Suche</label><input id="filter-q" type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="Ticketnummer oder Betreff …"></div>
    <button type="submit" class="btn btn-secondary"><?= icon('search') ?> Suchen</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="/portal/tickets?view=<?= e($view) ?>"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>
<div class="card card-flush">
<?php if ($rows === []): ?>
    <div class="empty-state">Keine Tickets gefunden.</div>
<?php else: ?>
    <div class="table-wrapper"><table class="table"><thead><tr><th>Nr.</th><th>Betreff</th><th>Status</th><th>Priorität</th><th>Kategorie</th><th>Aktualisiert</th></tr></thead><tbody>
    <?php foreach ($rows as $t): ?><tr class="ticket-row<?= in_array($t['status_category'] ?? '', ['resolved', 'closed', 'cancelled'], true) ? ' is-closed' : '' ?>" data-href="/portal/tickets/<?= (int) $t['id'] ?>"><td class="ticket-number"><a href="/portal/tickets/<?= (int) $t['id'] ?>"><?= e($t['number']) ?></a></td><td class="ticket-subject"><a href="/portal/tickets/<?= (int) $t['id'] ?>"><?= e($t['subject']) ?></a><div class="ticket-meta"><?= fmt_datetime($t['created_at'] ?? null) ?> · <?= (int) ($t['comment_count'] ?? 0) ?> Kommentare</div></td><td><?= badge($t['status_name'] ?? '–', $t['status_color'] ?? 'neutral') ?></td><td><span class="prio-dot is-<?= e($t['priority_color'] ?? 'neutral') ?>"></span><?= e($t['priority_name'] ?? '–') ?></td><td><?= e($t['category_name'] ?? '–') ?></td><td class="nowrap"><?= fmt_datetime($t['updated_at'] ?? null) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
