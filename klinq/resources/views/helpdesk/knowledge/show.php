<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk-knowledge';
$areaLabel = 'Help Desk';
$scripts = ['/js/helpdesk.js'];
$statusColor = static fn (string $s): string => match ($s) { 'published' => 'success', 'draft' => 'warning', 'archived' => 'neutral', default => 'neutral' };
$visibilityColor = static fn (string $v): string => $v === 'public' ? 'success' : 'info';
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted"><a href="/helpdesk/knowledge"><?= icon('arrow-left') ?> Wissensdatenbank</a></p>
        <h1 class="page-title"><?= e($article['title']) ?> <?= badge($statuses[$article['status']] ?? $article['status'], $statusColor((string) $article['status'])) ?> <?= badge($visibilities[$article['visibility']] ?? $article['visibility'], $visibilityColor((string) $article['visibility'])) ?></h1>
        <?php if (!empty($article['summary'])): ?><p class="kb-article-summary"><?= e($article['summary']) ?></p><?php endif; ?>
    </div>
    <div class="page-actions"><?php if ($canManage): ?><a class="btn btn-secondary" href="/helpdesk/knowledge/<?= (int) $article['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a><?php endif; ?></div>
</div>
<div class="grid grid-2">
    <article class="card">
        <dl class="detail-list mb-4">
            <dt>Kategorie</dt><dd><?= e($article['category_name'] ?? '–') ?></dd>
            <dt>Aufrufe</dt><dd class="mono"><?= (int) ($article['view_count'] ?? 0) ?></dd>
            <dt>Veröffentlicht</dt><dd><?= fmt_datetime($article['published_at'] ?? null) ?></dd>
            <dt>Geändert</dt><dd><?= fmt_datetime($article['updated_at'] ?? null) ?><?= !empty($article['updated_by_name']) ? ' · ' . e($article['updated_by_name']) : '' ?></dd>
        </dl>
        <div class="kb-article-body"><?= nl2br_e($article['body'] ?? '') ?></div>
    </article>
    <aside>
        <div class="card mb-4">
            <div class="card-header"><h2>Tags</h2></div>
            <?php if ($tags === []): ?><p class="text-muted mb-0">Keine Tags.</p><?php else: ?><div class="tag-list"><?php foreach ($tags as $tag): ?><span class="tag-chip is-<?= e($tag['color'] ?? 'neutral') ?>"><?= e($tag['name'] ?? $tag) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div>
        <div class="card card-flush" id="tickets">
            <div class="card-header"><h2>Verknüpfte Tickets</h2></div>
            <?php if ($linkedTickets === []): ?><div class="table-empty">Keine Tickets verknüpft.</div><?php else: ?>
            <div class="table-wrapper"><table class="table table-compact"><thead><tr><th>Nr.</th><th>Betreff</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($linkedTickets as $t): ?>
                <tr data-href="/helpdesk/tickets/<?= (int) $t['id'] ?>">
                    <td class="ticket-number"><a href="/helpdesk/tickets/<?= (int) $t['id'] ?>"><?= e($t['number']) ?></a></td>
                    <td><?= e($t['subject']) ?></td>
                    <td><?= badge($t['status_name'] ?? '–', $t['status_color'] ?? 'neutral') ?></td>
                    <td class="table-actions"><?php if ($canManage): ?><form method="post" action="/helpdesk/knowledge/<?= (int) $article['id'] ?>/tickets/<?= (int) $t['id'] ?>/unlink" data-confirm="Verknüpfung entfernen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm"><?= icon('x') ?> Lösen</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <form method="post" action="/helpdesk/knowledge/<?= (int) $article['id'] ?>/tickets" class="card-form">
                <?= csrf_field() ?>
                <div class="form-group<?= has_error('ticket') ? ' has-error' : '' ?>"><label for="f-ticket">Ticket verknüpfen</label><input id="f-ticket" name="ticket" value="<?= e(form_value(null, 'ticket')) ?>" list="ticket-suggestions" data-hd-ticket-search="" placeholder="Ticketnummer oder Betreff"><datalist id="ticket-suggestions"></datalist><?= field_error('ticket') ?></div>
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('link') ?> Verknüpfen</button>
            </form>
            <?php endif; ?>
        </div>
    </aside>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
