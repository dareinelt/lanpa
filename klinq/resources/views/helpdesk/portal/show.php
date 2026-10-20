<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'portal';
$areaLabel = 'Portal';
$scripts = ['/js/helpdesk.js'];
$userId = (int) ($user['id'] ?? 0);
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted"><a href="/portal/tickets"><?= icon('arrow-left') ?> Meine Tickets</a></p>
        <h1 class="page-title"><span class="ticket-number"><?= e($ticket['number']) ?></span> · <?= e($ticket['subject']) ?> <?= badge($ticket['status_name'] ?? '–', $ticket['status_color'] ?? 'neutral') ?></h1>
        <?php if (($ticket['source'] ?? '') === 'email'): ?><p class="text-muted text-sm mb-0"><?= icon('mail', 'icon icon-xs') ?> Per E-Mail eröffnet<?= !empty($ticket['mail_from_address']) ? ' von ' . e($ticket['mail_from_address']) : '' ?>.</p><?php endif; ?>
    </div>
    <div class="page-actions">
        <?php if ($canReopen): ?><button type="button" class="btn btn-secondary" data-dialog-open="reopen-dialog"><?= icon('refresh') ?> Wieder öffnen</button><?php endif; ?>
        <?php if ($isAgent): ?><a class="btn btn-ghost" href="/helpdesk/tickets/<?= (int) $ticket['id'] ?>"><?= icon('settings') ?> Agentenansicht</a><?php endif; ?>
    </div>
</div>
<div class="ticket-layout">
    <div>
        <div class="card mb-4"><div class="card-header"><h2>Beschreibung</h2></div><div class="ticket-description"><?= nl2br_e($ticket['description'] ?? '') ?></div></div>
        <?php if (!empty($ticket['resolution'])): ?><div class="card mb-4"><div class="card-header"><h2>Lösung</h2></div><div class="ticket-resolution"><?= nl2br_e($ticket['resolution']) ?></div></div><?php endif; ?>
        <div class="card" id="comments">
            <div class="card-header"><h2>Kommentare</h2><span class="text-muted text-sm"><?= count($comments) ?></span></div>
            <?php if ($comments === []): ?><p class="text-muted">Noch keine Kommentare.</p><?php else: ?>
            <ul class="comment-list"><?php foreach ($comments as $c): $own = (int) ($c['author_user_id'] ?? 0) === $userId || !empty($c['is_requester']); ?><li class="comment<?= $own ? ' is-requester' : '' ?>" data-comment-type="<?= e($c['type'] ?? 'public') ?>"><div class="comment-head"><span class="comment-author"><?= e($c['author_name'] ?? 'system') ?></span><span class="text-muted"><?= fmt_datetime($c['created_at'] ?? null) ?></span><?php if (($c['source'] ?? '') === 'portal'): ?><?= badge('Portal', 'info') ?><?php elseif (($c['source'] ?? '') === 'email'): ?><?= badge('Per E-Mail', 'info') ?><?php endif; ?></div><div class="comment-body"><?= nl2br_e($c['body'] ?? '') ?></div></li><?php endforeach; ?></ul>
            <?php endif; ?>
            <?php if ($canComment): ?>
            <form method="post" action="/portal/tickets/<?= (int) $ticket['id'] ?>/comments" enctype="multipart/form-data" class="comment-form mt-4" data-hd-comment-form>
                <?= csrf_field() ?>
                <div class="form-group<?= has_error('body') ? ' has-error' : '' ?>"><label for="f-body">Antwort</label><textarea id="f-body" name="body" rows="4" placeholder="Nachricht an den Help Desk …"></textarea><?= field_error('body') ?></div>
                <div class="comment-form-footer"><div class="form-group"><label for="f-file">Anhang</label><input id="f-file" type="file" name="file"></div><button type="submit" class="btn btn-primary"><?= icon('check') ?> Kommentar senden</button></div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <aside class="ticket-sidebar">
        <div class="card">
            <div class="card-header"><h2>Details</h2></div>
            <dl class="detail-list">
                <dt>Erstellt</dt><dd><?= fmt_datetime($ticket['created_at'] ?? null) ?></dd>
                <dt>Aktualisiert</dt><dd><?= fmt_datetime($ticket['updated_at'] ?? null) ?></dd>
                <dt>Kategorie</dt><dd><?= e($ticket['category_name'] ?? '–') ?><?= !empty($ticket['subcategory_name']) ? ' / ' . e($ticket['subcategory_name']) : '' ?></dd>
                <dt>Priorität</dt><dd><span class="prio-dot is-<?= e($ticket['priority_color'] ?? 'neutral') ?>"></span><?= e($ticket['priority_name'] ?? '–') ?></dd>
                <dt>Bearbeiter</dt><dd><?= e($ticket['assignee_name'] ?? 'noch nicht zugewiesen') ?></dd>
                <dt>Gruppe</dt><dd><?= e($ticket['group_name'] ?? '–') ?></dd>
            </dl>
        </div>
        <div class="card" id="attachments">
            <div class="card-header"><h2>Anhänge</h2></div>
            <?php if ($attachments === []): ?><p class="text-muted mb-0">Keine Anhänge.</p><?php else: ?><ul class="side-list"><?php foreach ($attachments as $a): ?><li><span class="side-list-main"><a href="/portal/tickets/<?= (int) $ticket['id'] ?>/attachments/<?= (int) ($a['document_id'] ?? $a['id']) ?>"><?= icon('download') ?> <?= e($a['original_name'] ?? 'Datei') ?></a><span class="text-xs text-muted"><?= fmt_bytes((int) ($a['size_bytes'] ?? 0)) ?> · <?= fmt_datetime($a['created_at'] ?? null) ?></span></span></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <?php if ($assets !== []): ?><div class="card"><div class="card-header"><h2>Betroffene Assets</h2></div><ul class="side-list"><?php foreach ($assets as $asset): ?><li><span class="side-list-main"><span class="mono"><?= e($asset['inventory_number'] ?? '–') ?></span><span class="text-xs text-muted"><?= e($asset['asset_name'] ?? $asset['article_name'] ?? '') ?></span></span></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if ($articles !== []): ?><div class="card"><div class="card-header"><h2>Hilfreiche Artikel</h2></div><ul class="side-list"><?php foreach ($articles as $article): ?><li><span class="side-list-main"><a href="/portal/knowledge/<?= (int) $article['id'] ?>"><?= e($article['title']) ?></a><span class="text-xs text-muted"><?= e($article['summary'] ?? '') ?></span></span></li><?php endforeach; ?></ul></div><?php endif; ?>
    </aside>
</div>
<?php if ($canReopen): ?>
<dialog id="reopen-dialog" class="dialog">
    <form method="post" action="/portal/tickets/<?= (int) $ticket['id'] ?>/reopen">
        <?= csrf_field() ?>
        <div class="card-header"><h2>Ticket wieder öffnen</h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close><?= icon('x') ?></button></div>
        <p class="text-muted">Dieses Ticket kann innerhalb von <?= (int) $reopenDays ?> Tagen nach Lösung wieder geöffnet werden.</p>
        <div class="form-group<?= has_error('note') ? ' has-error' : '' ?>"><label for="f-note">Grund</label><textarea id="f-note" name="note" rows="4" required></textarea><?= field_error('note') ?></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('refresh') ?> Wieder öffnen</button><button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button></div>
    </form>
</dialog>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
