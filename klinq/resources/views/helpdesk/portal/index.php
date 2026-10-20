<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'portal';
$areaLabel = 'Portal';
$ticketUrl = static fn (array $t): string => '/portal/tickets/' . (int) ($t['id'] ?? 0);
?>
<div class="portal-hero">
    <div><h1>Willkommen im IT-Service-Portal</h1><p class="page-subtitle text-muted">Hier können Sie neue Anfragen stellen, offene Tickets verfolgen und hilfreiche Anleitungen finden.</p></div>
    <div class="page-actions">
        <?php if ($canCreate): ?><a class="btn btn-primary" href="/portal/tickets/new"><?= icon('plus') ?> Neue Anfrage</a><?php endif; ?>
        <?php if ($isAgent): ?><a class="btn btn-secondary" href="/helpdesk"><?= icon('settings') ?> Zum Help Desk</a><?php endif; ?>
    </div>
</div>
<?php if ($templates !== []): ?>
<div class="card mb-4">
    <div class="card-header"><h2>Häufige Anliegen</h2></div>
    <div class="portal-templates">
        <?php foreach ($templates as $template): ?>
            <a class="portal-template" href="/portal/tickets/new?template=<?= (int) $template['id'] ?>"><strong><?= e($template['name']) ?></strong><span><?= e($template['description'] ?? $template['subject'] ?? 'Anfrage mit Vorlage starten') ?></span></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<div class="grid grid-2">
    <div class="card card-flush">
        <div class="card-header"><h2>Meine offenen Anfragen</h2><span class="text-muted text-sm"><?= (int) $openCount ?></span></div>
        <?php if ($open === []): ?><div class="table-empty">Sie haben keine offenen Anfragen.</div><?php else: ?>
        <div class="table-wrapper"><table class="table table-compact"><thead><tr><th>Nr.</th><th>Betreff</th><th>Status</th><th>Aktualisiert</th></tr></thead><tbody>
        <?php foreach ($open as $t): ?><tr class="ticket-row" data-href="<?= e($ticketUrl($t)) ?>"><td class="ticket-number"><a href="<?= e($ticketUrl($t)) ?>"><?= e($t['number']) ?></a></td><td class="ticket-subject"><a href="<?= e($ticketUrl($t)) ?>"><?= e($t['subject']) ?></a></td><td><?= badge($t['status_name'] ?? '–', $t['status_color'] ?? 'neutral') ?></td><td class="nowrap"><?= fmt_datetime($t['updated_at'] ?? null) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <div class="form-actions"><a class="btn btn-ghost" href="/portal/tickets">Alle meine Anfragen</a></div>
    </div>
    <div class="card">
        <div class="card-header"><h2>Kürzlich gelöst</h2><span class="text-muted text-sm"><?= (int) $resolvedCount ?></span></div>
        <?php if ($resolved === []): ?><p class="text-muted mb-0">Noch keine gelösten Anfragen.</p><?php else: ?>
        <ul class="side-list"><?php foreach ($resolved as $t): ?><li><span class="side-list-main"><a href="<?= e($ticketUrl($t)) ?>"><?= e($t['number']) ?> · <?= e($t['subject']) ?></a><span class="text-xs text-muted"><?= fmt_datetime($t['updated_at'] ?? null) ?></span></span><?= badge($t['status_name'] ?? '–', $t['status_color'] ?? 'neutral') ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><h2>Hilfreiche Artikel</h2><a class="btn btn-ghost btn-sm" href="/portal/knowledge">Alle Artikel</a></div>
        <?php if ($articles === []): ?><p class="text-muted mb-0">Keine Artikel verfügbar.</p><?php else: ?>
        <div class="kb-card-list"><?php foreach ($articles as $article): ?><a class="card kb-card" href="/portal/knowledge/<?= (int) $article['id'] ?>"><h3><?= e($article['title']) ?></h3><p><?= e($article['summary'] ?? $article['category_name'] ?? '') ?></p></a><?php endforeach; ?></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><h2>Übersicht</h2></div>
        <div class="kpi-row"><div><span class="kpi-value"><?= (int) $openCount ?></span><span class="kpi-label">offen</span></div><div><span class="kpi-value"><?= (int) $resolvedCount ?></span><span class="kpi-label">gelöst</span></div><div><span class="kpi-value mono"><?= (int) $userId ?></span><span class="kpi-label">Benutzer-ID</span></div></div>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
