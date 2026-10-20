<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk-knowledge';
$areaLabel = 'Help Desk';
$hasFilter = ($filters['q'] ?? '') !== '' || ($filters['category_id'] ?? '') !== '' || ($filters['status'] ?? '') !== '' || ($filters['tag'] ?? '') !== '';
$tabs = ['' => 'Alle'] + $statuses;
$statusColor = static fn (string $s): string => match ($s) { 'published' => 'success', 'draft' => 'warning', 'archived' => 'neutral', default => 'neutral' };
$visibilityColor = static fn (string $v): string => $v === 'public' ? 'success' : 'info';
?>
<div class="page-header">
    <div><h1 class="page-title">Wissensdatenbank</h1><p class="page-subtitle text-muted"><?= (int) $paginator->total ?> Artikel</p></div>
    <div class="page-actions"><?php if ($canManage): ?><a class="btn btn-primary" href="/helpdesk/knowledge/new"><?= icon('plus') ?> Neuer Artikel</a><?php endif; ?></div>
</div>
<?php if ($canManage): ?>
<div class="status-tabs mb-4" role="tablist" aria-label="Artikelstatus">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="status-tab<?= (string) ($filters['status'] ?? '') === (string) $key ? ' is-active' : '' ?>" href="<?= e(query_url('/helpdesk/knowledge', $query, ['status' => $key === '' ? null : $key, 'page' => null])) ?>"><?= e($label) ?><?php if ($key !== ''): ?> <span class="status-tab-count"><?= (int) ($counts[$key] ?? 0) ?></span><?php else: ?> <span class="status-tab-count"><?= (int) ($counts['total'] ?? 0) ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<form method="get" action="/helpdesk/knowledge" class="filter-bar mb-4" role="search">
    <div class="form-group filter-wide"><label for="filter-q">Suche</label><input id="filter-q" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Titel, Inhalt oder Tag …"></div>
    <div class="form-group"><label for="filter-category">Kategorie</label><select id="filter-category" name="category_id" data-autosubmit><option value="">Alle</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($filters['category_id'] ?? '', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="filter-tag">Tag</label><input id="filter-tag" name="tag" value="<?= e($filters['tag'] ?? '') ?>"></div>
    <button type="submit" class="btn btn-secondary"><?= icon('search') ?> Suchen</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="/helpdesk/knowledge"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>
<div class="card card-flush">
<?php if ($rows === []): ?>
    <div class="empty-state">Keine Artikel gefunden.</div>
<?php else: ?>
    <div class="table-wrapper">
        <table class="table">
            <thead><tr><th><?= sort_link('/helpdesk/knowledge', $query, 'title', 'Titel') ?></th><th>Kategorie</th><th><?= sort_link('/helpdesk/knowledge', $query, 'status', 'Status') ?></th><th>Sichtbarkeit</th><th><?= sort_link('/helpdesk/knowledge', $query, 'view_count', 'Aufrufe') ?></th><th><?= sort_link('/helpdesk/knowledge', $query, 'updated_at', 'Geändert') ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-href="/helpdesk/knowledge/<?= (int) $r['id'] ?>">
                    <td><a class="font-semibold" href="/helpdesk/knowledge/<?= (int) $r['id'] ?>"><?= e($r['title']) ?></a><div class="text-xs text-muted"><?= e($r['summary'] ?? '') ?></div></td>
                    <td><?= e($r['category_name'] ?? '–') ?></td>
                    <td><?= badge($statuses[$r['status']] ?? $r['status'], $statusColor((string) $r['status'])) ?></td>
                    <td><?= badge($visibilities[$r['visibility']] ?? $r['visibility'], $visibilityColor((string) $r['visibility'])) ?></td>
                    <td class="mono"><?= (int) ($r['view_count'] ?? 0) ?></td>
                    <td class="nowrap"><?= fmt_datetime($r['updated_at'] ?? null) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
