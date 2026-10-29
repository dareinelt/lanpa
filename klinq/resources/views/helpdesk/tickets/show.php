<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$id = (int) $ticket['id'];
$closed = in_array($ticket['status_category'], ['resolved', 'closed', 'cancelled'], true);
$isMerged = $ticket['merged_into_ticket_id'] !== null;
$readOnly = $isMerged;
$isForwardedToMe = !empty($ticket['forwarded_to_user_id']) && (int) $ticket['forwarded_to_user_id'] === (int) $user['id'] && empty($ticket['forward_acknowledged_at']);
$canUpdate = $can('helpdesk.update') && !$readOnly;
$canConvert = $isAgent && $can('helpdesk.tasks') && !$readOnly
    && !in_array((string) $ticket['status_code'], ['closed', 'cancelled'], true) && $taskForTicket === null;
$stateLabel = ['ok' => 'Im Plan', 'warning' => 'Warnung', 'breached' => 'Verletzt', 'met' => 'Eingehalten', 'none' => '–', 'paused' => 'Pausiert'];
$stateClass = static fn (string $s): string => match ($s) { 'warning' => ' is-warning', 'breached' => ' is-breached', 'met' => ' is-met', default => '' };
$percentBar = static function (?int $percent, string $state, ?string $startedAt, ?string $dueAt, int $warningPercent): string {
    if ($percent === null || $startedAt === null || $dueAt === null) {
        return '';
    }
    $p = max(0, min(100, $percent));

    return '<progress class="sla-bar' . ($state === 'breached' ? ' is-breached' : ($state === 'warning' ? ' is-warning' : '')) . '" max="100" value="' . $p . '"'
        . ' data-hd-sla-start="' . e($startedAt) . '" data-hd-sla-due="' . e($dueAt) . '" data-hd-sla-warning="' . $warningPercent . '"'
        . ' aria-label="SLA-Verbrauch ' . $p . '%"></progress>';
};
?>
<?php
// Aktionen der Kopfzeile (Annehmen, Schließen, Pausieren, Stornieren) und die verbleibenden
// Statuswechsel der Statusleiste werden aus den verfügbaren Übergängen abgeleitet.
$currentCode = (string) $ticket['status_code'];
$pauseTransitions = array_values(array_filter($transitions, static fn (array $t): bool => !empty($t['is_pause'])));
$pauseCodes = implode(',', array_column($pauseTransitions, 'code'));
$actionTransitions = [];
foreach ($transitions as $t) {
    if (empty($t['is_pause'])) {
        $actionTransitions[(string) $t['code']] = $t;
    }
}
$acceptTransition = $actionTransitions['in_progress'] ?? null;
$closeTransition = $actionTransitions['closed'] ?? null;
$cancelTransition = $actionTransitions['cancelled'] ?? null;
// „Annehmen“ heißt je nach Ausgangslage anders (wieder öffnen, Pausierung beenden).
$acceptLabel = match (true) {
    in_array($currentCode, ['resolved', 'closed'], true) => 'Wieder öffnen',
    ($ticket['status_category'] ?? '') === 'pending' => 'Weiter bearbeiten',
    default => 'Annehmen',
};
// „In Bearbeitung“, „Gelöst“, „Geschlossen“ und „Storniert“ liegen in der Kopfzeile.
$statusTransitions = array_values(array_filter(
    $actionTransitions,
    static fn (array $t): bool => !in_array((string) $t['code'], ['in_progress', 'resolved', 'closed', 'cancelled'], true)
));
?>
<span hidden data-hd-ai-config data-enabled="<?= !empty($aiEnabled) ? '1' : '0' ?>"></span>
<div class="page-header ticket-page-header">
    <div>
        <div class="breadcrumb"><a href="/helpdesk/tickets">Tickets</a> › <span class="ticket-number"><?= e($ticket['number']) ?></span></div>
        <h1><?= e($ticket['subject']) ?></h1>
        <div class="ticket-header-badges">
            <?= badge($ticket['status_name'], $ticket['status_color']) ?>
            <span class="badge is-<?= e($ticket['priority_color']) ?>"><span class="prio-dot"></span><?= e($ticket['priority_name']) ?></span>
            <?= badge($ticket['type_name'], $ticket['type_color'] ?? 'neutral') ?>
            <?php if ($isMerged): ?><span class="badge is-neutral"><?= icon('merge', 'icon icon-xs') ?> Zusammengeführt in <a href="/helpdesk/tickets/<?= (int) $ticket['merged_into_ticket_id'] ?>"><?= e($ticket['merged_into_number']) ?></a></span><?php endif; ?>
            <?php if ((int) $ticket['escalation_level'] > 0): ?><span class="badge is-danger">Eskalationsstufe <?= (int) $ticket['escalation_level'] ?></span><?php endif; ?>
            <?php if ((int) $ticket['reopen_count'] > 0): ?><span class="badge is-warning"><?= (int) $ticket['reopen_count'] ?>× wiedereröffnet</span><?php endif; ?>
            <?php if ($taskForTicket !== null): ?><span class="badge badge-info"><?= icon('inbox', 'icon icon-xs') ?> Aufgabe <a href="/helpdesk/aufgaben/<?= (int) $taskForTicket['id'] ?>"><?= e($taskForTicket['number']) ?></a></span><?php endif; ?>
            <?php if ($ticket['source'] === 'email'): ?>
                <span class="badge badge-info"><?= icon('mail', 'icon icon-xs') ?> Per E-Mail eröffnet<?= !empty($ticket['mail_from_address']) ? ' von ' . e(trim(($ticket['mail_from_name'] ?? '') . ' <' . $ticket['mail_from_address'] . '>')) : '' ?></span>
            <?php endif; ?>
            <span class="text-muted text-sm">Quelle: <?= e($sources[$ticket['source']] ?? $ticket['source']) ?> · erstellt <?= fmt_datetime($ticket['created_at']) ?> von <?= e($ticket['created_by_name'] ?? 'system') ?></span>
        </div>
    </div>
    <div class="page-actions">
        <?php if ($isAgent && $can('helpdesk.comment') && !$readOnly): ?>
            <?php if (!empty($mailCompose['available'])): ?>
                <button type="button" class="btn btn-primary btn-sm" data-dialog-open="mail-dialog"><?= icon('mail') ?> E-Mail senden</button>
            <?php else: ?>
                <button type="button" class="btn btn-secondary btn-sm" disabled title="<?= e($mailCompose['reason']) ?>"><?= icon('mail') ?> E-Mail senden</button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($isAgent && $transitions !== [] && !$readOnly): ?>
            <?php if ($acceptTransition !== null): ?>
                <button type="button" class="btn btn-secondary btn-sm" data-hd-status="<?= e($acceptTransition['code']) ?>" data-hd-label="<?= e($acceptLabel) ?>" data-requires-resolution="0" data-requires-reason="0"><?= icon('user') ?> <?= e($acceptLabel) ?></button>
            <?php endif; ?>
            <?php if ($closeTransition !== null): ?>
                <button type="button" class="btn btn-primary btn-sm" data-hd-status="<?= e($closeTransition['code']) ?>" data-hd-label="<?= e($closeTransition['name']) ?>" data-requires-resolution="<?= !empty($closeTransition['requires_resolution']) ? '1' : '0' ?>" data-requires-reason="0"><?= icon('check') ?> Schließen</button>
            <?php endif; ?>
            <?php if ($pauseTransitions !== []): ?>
                <button type="button" class="btn btn-secondary btn-sm" data-hd-status="<?= e($pauseTransitions[0]['code']) ?>" data-hd-label="<?= e($pauseTransitions[0]['name']) ?>" data-requires-resolution="0" data-requires-reason="0" data-hd-pause="1"><?= icon('clock') ?> <?= ($ticket['status_category'] ?? '') === 'pending' ? 'Pausierung ändern' : 'Pausieren' ?></button>
            <?php endif; ?>
            <?php if ($cancelTransition !== null): ?>
                <button type="button" class="btn btn-danger btn-sm" data-hd-status="<?= e($cancelTransition['code']) ?>" data-hd-label="<?= e($cancelTransition['name']) ?>" data-requires-resolution="0" data-requires-reason="<?= !empty($cancelTransition['requires_reason']) ? '1' : '0' ?>"><?= icon('x') ?> <?= e($cancelTransition['name']) ?></button>
            <?php endif; ?>
        <?php endif; ?>
        <form method="post" action="/helpdesk/tickets/<?= $id ?>/watch" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-secondary btn-sm"><?= icon('eye') ?> <?= $isWatching ? 'Nicht mehr beobachten' : 'Beobachten' ?></button></form>
        <?php if ($isAgent && $can('helpdesk.assign') && !$readOnly && !$closed): ?>
            <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="forward-dialog"><?= icon('swap') ?> Weiterleiten</button>
        <?php endif; ?>
        <?php if ($isAgent && $can('helpdesk.assign') && !$readOnly && !$closed && (int) ($ticket['assignee_user_id'] ?? 0) !== (int) $user['id']): ?>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/take" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-secondary btn-sm"><?= icon('user') ?> Übernehmen</button></form>
        <?php endif; ?>
        <?php if ($canConvert): ?>
            <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="convert-dialog"><?= icon('inbox') ?> In Aufgabe umwandeln</button>
        <?php endif; ?>
        <?php if (($isFirstLevelSupport ?? false) && $secondLevelSupport !== null && !$readOnly && !$closed): ?>
            <button type="button" class="btn btn-secondary btn-sm" data-dialog-open="second-level-dialog"><?= icon('shield') ?> An 2nd Level zuweisen</button>
        <?php endif; ?>
        <?php if ($canUpdate): ?><a class="btn btn-secondary btn-sm" href="/helpdesk/tickets/<?= $id ?>/edit"><?= icon('pen') ?> Bearbeiten</a><?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="/helpdesk/tickets/<?= $id ?>?print=1" target="_blank" rel="noopener"><?= icon('print') ?></a>
    </div>
</div>

<?php if ($isMerged): ?>
    <div class="alert alert-info"><?= icon('info') ?> Dieses Ticket wurde in <a href="/helpdesk/tickets/<?= (int) $ticket['merged_into_ticket_id'] ?>"><?= e($ticket['merged_into_number']) ?></a> zusammengeführt und ist schreibgeschützt.</div>
<?php endif; ?>

<?php if ($isForwardedToMe): ?>
    <div class="alert alert-warning hd-forward-banner">
        <?= icon('swap') ?>
        <span>Dieses Ticket wurde <?= !empty($ticket['forwarded_by_name']) ? 'von <strong>' . e($ticket['forwarded_by_name']) . '</strong> ' : '' ?>an Sie weitergeleitet (<?= fmt_datetime($ticket['forwarded_at'] ?? null) ?>). Übernehmen Sie es, damit es nicht mehr als weitergeleitet gilt.</span>
        <form method="post" action="/helpdesk/tickets/<?= $id ?>/take" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-primary btn-sm"><?= icon('check') ?> Übernehmen</button></form>
    </div>
<?php endif; ?>

<?php if ($transitions !== [] && !$readOnly): ?>
<section class="ticket-actions" id="status">
    <span class="text-muted text-sm">Status ändern:</span>
    <?php foreach ($statusTransitions as $t): ?>
        <button type="button" class="btn btn-sm <?= $t['category'] === 'resolved' || $t['category'] === 'closed' ? 'btn-primary' : ($t['category'] === 'cancelled' ? 'btn-danger' : 'btn-secondary') ?>" data-hd-status="<?= e($t['code']) ?>" data-hd-label="<?= e($t['name']) ?>" data-requires-resolution="<?= !empty($t['requires_resolution']) ? '1' : '0' ?>" data-requires-reason="<?= !empty($t['requires_reason']) ? '1' : '0' ?>"><?= e($t['name']) ?></button>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="ticket-layout">
<div class="ticket-main">

    <section class="card">
        <h2 class="card-title">Beschreibung</h2>
        <div class="ticket-description"><?= rich_text($ticket['description']) ?></div>
        <?php if (!empty($ticket['resolution'])): ?>
            <div class="ticket-resolution">
                <h3><?= icon('check', 'icon icon-xs') ?> Lösung</h3>
                <div><?= rich_text($ticket['resolution']) ?></div>
                <?php if (!empty($ticket['resolved_at'])): ?><div class="text-xs text-muted">Gelöst am <?= fmt_datetime($ticket['resolved_at']) ?><?= $ticket['resolved_by_name'] ?? null ? ' von ' . e($ticket['resolved_by_name']) : '' ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($ticket['close_reason'])): ?><p class="text-sm text-muted">Abschlussgrund: <?= e($ticket['close_reason']) ?></p><?php endif; ?>
        <?php if (!empty($ticket['error_origin'])): ?><p class="text-sm text-muted">Fehlerursprung: <?= e($ticket['error_origin']) ?></p><?php endif; ?>
    </section>

    <section class="card" id="comments">
        <div class="card-header-row">
            <h2 class="card-title">Kommunikation <span class="text-muted">(<?= count($comments) ?>)</span></h2>
            <?php if ($isAgent): ?>
            <div class="comment-filter" data-hd-comment-filter="#comment-list">
                <button type="button" class="btn btn-ghost btn-sm is-active" data-filter="all">Alle</button>
                <button type="button" class="btn btn-ghost btn-sm" data-filter="public">Öffentlich</button>
                <button type="button" class="btn btn-ghost btn-sm" data-filter="internal">Intern</button>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($comments === []): ?>
            <p class="empty-state">Noch keine Kommentare.</p>
        <?php else: ?>
        <ol class="comment-list" id="comment-list">
            <?php foreach ($comments as $c): ?>
            <?php $pinned = (int) ($c['is_pinned'] ?? 0) === 1; ?>
            <li class="comment<?= $c['type'] === 'internal' ? ' is-internal' : '' ?><?= (int) $c['is_requester'] === 1 ? ' is-requester' : '' ?><?= $pinned ? ' is-pinned' : '' ?>" data-comment-type="<?= e($c['type']) ?>" id="comment-<?= (int) $c['id'] ?>">
                <?php if ($isAgent && $can('helpdesk.comment') && !$readOnly): ?>
                <form method="post" action="/helpdesk/tickets/<?= $id ?>/comments/<?= (int) $c['id'] ?>/pin" class="comment-pin-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="pinned" value="<?= $pinned ? '0' : '1' ?>">
                    <button type="submit" class="comment-pin<?= $pinned ? ' is-active' : '' ?>" title="<?= $pinned ? 'Anheftung lösen' : 'Oben anheften' ?>" aria-label="<?= $pinned ? 'Anheftung lösen' : 'Oben anheften' ?>" aria-pressed="<?= $pinned ? 'true' : 'false' ?>"><?= icon('pin') ?></button>
                </form>
                <?php endif; ?>
                <div class="comment-head">
                    <span class="comment-author"><?= e($c['author_name']) ?></span>
                    <?php if ($pinned): ?><span class="badge badge-info"><?= icon('pin', 'icon icon-xs') ?> Angeheftet</span><?php endif; ?>
                    <?php if ((int) $c['is_requester'] === 1): ?><span class="badge is-neutral">Melder</span><?php endif; ?>
                    <?php if ($c['type'] === 'internal'): ?><span class="badge is-warning">Intern</span><?php endif; ?>
                    <?php if ($c['source'] === 'email'): ?><span class="badge badge-info" title="<?= e(!empty($c['mail_from_address']) ? 'Absender: ' . $c['mail_from_address'] : 'Per E-Mail eingegangen') ?>"><?= icon('mail', 'icon icon-xs') ?> Per E-Mail<?= !empty($c['mail_from_address']) ? ' von ' . e($c['mail_from_address']) : '' ?></span><?php endif; ?>
                    <span class="text-muted text-xs"><?= fmt_datetime($c['created_at']) ?> · <?= e($sources[$c['source']] ?? $c['source']) ?></span>
                </div>
                <div class="comment-body"><?= rich_text($c['body']) ?></div>
                <?php $cAtt = array_filter($attachments, static fn (array $a): bool => (int) ($a['comment_id'] ?? 0) === (int) $c['id']); ?>
                <?php if ($cAtt !== []): ?>
                <ul class="comment-attachments">
                    <?php foreach ($cAtt as $a): ?><li><a href="/helpdesk/tickets/<?= $id ?>/attachments/<?= (int) $a['id'] ?>"><?= icon('paperclip', 'icon icon-xs') ?> <?= e($a['original_name']) ?></a> <span class="text-muted text-xs"><?= fmt_bytes((int) $a['size_bytes']) ?></span></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>

        <?php if ($can('helpdesk.comment') && !$readOnly): ?>
        <form method="post" action="/helpdesk/tickets/<?= $id ?>/comments" enctype="multipart/form-data" class="comment-form" data-hd-comment-form>
            <?= csrf_field() ?>
            <div class="radio-group" role="radiogroup" aria-label="Kommentartyp">
                <?php if ($isAgent): ?><label><input type="radio" name="type" value="internal" checked> Interne Notiz</label><?php endif; ?>
                <label><input type="radio" name="type" value="public"<?= $isAgent ? '' : ' checked' ?>> Öffentliche Antwort (für Melder sichtbar)</label>
            </div>
            <div class="form-group">
                <label for="comment-body" class="sr-only">Kommentar</label>
                <textarea id="comment-body" name="body" rows="4" placeholder="Antwort oder Notiz eingeben …" maxlength="2000000" data-hd-field="ticket_comment" data-hd-media-images data-hd-media-preview="#comment-media-preview"></textarea>
                <div class="hd-media-preview" id="comment-media-preview" hidden></div>
                <p class="form-hint">Bilder aus der Zwischenablage mit STRG+V einfügen – sie bleiben im Text erhalten.</p>
            </div>
            <div class="comment-form-footer">
                <label class="file-label"><?= icon('paperclip', 'icon icon-xs') ?> Anhang <input type="file" name="file"></label>
                <button type="submit" class="btn btn-primary"><?= icon('mail') ?> Senden</button>
            </div>
        </form>
        <?php endif; ?>
    </section>

    <details class="card card-collapsible" id="attachments">
        <summary class="card-summary">
            <h2 class="card-title">Anhänge <span class="text-muted">(<?= count($attachments) ?>)</span></h2>
        </summary>
        <div class="card-collapsible-body">
        <?php if ($attachments === []): ?>
            <p class="empty-state">Keine Anhänge.</p>
        <?php else: ?>
        <table class="table table-compact">
            <thead><tr><th>Datei</th><th>Größe</th><th>Hochgeladen</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($attachments as $a): ?>
                <tr>
                    <td><a href="/helpdesk/tickets/<?= $id ?>/attachments/<?= (int) $a['id'] ?>"><?= icon('paperclip', 'icon icon-xs') ?> <?= e($a['original_name']) ?></a><?php if ((int) $a['is_internal'] === 1): ?> <span class="badge is-warning">Intern</span><?php endif; ?><?php if (!empty($a['note'])): ?><div class="text-xs text-muted"><?= e($a['note']) ?></div><?php endif; ?></td>
                    <td class="nowrap"><?= fmt_bytes((int) $a['size_bytes']) ?></td>
                    <td class="nowrap text-sm"><?= fmt_datetime($a['created_at']) ?><div class="text-xs text-muted"><?= e($a['uploaded_by_name']) ?></div></td>
                    <td class="text-right">
                        <?php if ($canUpdate): ?><form method="post" action="/helpdesk/tickets/<?= $id ?>/attachments/<?= (int) $a['id'] ?>/delete" class="inline-form" data-confirm="Anhang „<?= e($a['original_name']) ?>“ löschen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" aria-label="Löschen"><?= icon('trash') ?></button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($can('helpdesk.comment') && !$readOnly): ?>
        <form method="post" action="/helpdesk/tickets/<?= $id ?>/attachments" enctype="multipart/form-data" class="inline-form form-row">
            <?= csrf_field() ?>
            <div class="form-group"><label for="att-file">Datei</label><input id="att-file" type="file" name="file" required></div>
            <div class="form-group"><label for="att-note">Notiz</label><input id="att-note" name="note" maxlength="500"></div>
            <?php if ($isAgent): ?><label class="checkbox-row"><input type="checkbox" name="internal" value="1"> Nur intern</label><?php endif; ?>
            <div class="form-group is-narrow"><button type="submit" class="btn btn-secondary"><?= icon('upload') ?> Hochladen</button></div>
        </form>
        <?php endif; ?>
        </div>
    </details>

    <details class="card card-collapsible" id="worklog">
        <summary class="card-summary">
            <h2 class="card-title">Arbeitszeit <span class="text-muted">(<?= (int) $worklogTotal ?> min gesamt)</span></h2>
        </summary>
        <div class="card-collapsible-body">
        <?php if ($worklogs !== []): ?>
        <table class="table table-compact">
            <thead><tr><th>Datum</th><th>Bearbeiter</th><th>Tätigkeit</th><th class="text-right">Minuten</th><th>Notiz</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($worklogs as $w): ?>
                <tr>
                    <td class="nowrap"><?= fmt_date($w['started_at'] ?? $w['created_at']) ?></td>
                    <td><?= e($w['user_name']) ?></td>
                    <td><?= e($worklogActivities[$w['activity']] ?? $w['activity']) ?></td>
                    <td class="text-right"><?= (int) $w['minutes'] ?></td>
                    <td class="text-sm"><?= e($w['note'] ?? '') ?></td>
                    <td class="text-right"><?php if ($can('helpdesk.worklog') && !$readOnly && ((int) $w['user_id'] === (int) $user['id'] || $can('helpdesk.admin'))): ?><form method="post" action="/helpdesk/tickets/<?= $id ?>/worklogs/<?= (int) $w['id'] ?>/delete" class="inline-form" data-confirm="Eintrag löschen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" aria-label="Löschen"><?= icon('trash') ?></button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="empty-state">Noch keine Arbeitszeit erfasst.</p>
        <?php endif; ?>
        <?php if ($can('helpdesk.worklog') && !$readOnly): ?>
        <form method="post" action="/helpdesk/tickets/<?= $id ?>/worklogs" class="inline-form form-row">
            <?= csrf_field() ?>
            <div class="form-group is-narrow"><label for="wl-min">Minuten</label><input id="wl-min" type="number" name="minutes" min="1" max="43200" step="15" required value="<?= e(old('minutes', '15')) ?>"></div>
            <div class="form-group"><label for="wl-act">Tätigkeit</label><select id="wl-act" name="activity"><?php foreach ($worklogActivities as $k => $l): ?><option value="<?= e($k) ?>"<?= selected(old('activity', 'support'), $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="form-group is-narrow"><label for="wl-date">Datum</label><input id="wl-date" type="date" name="worked_on" value="<?= e(old('worked_on', gmdate('Y-m-d'))) ?>"></div>
            <div class="form-group"><label for="wl-note">Notiz</label><input id="wl-note" name="note" maxlength="2000" value="<?= e(old('note')) ?>"></div>
            <div class="form-group is-narrow"><button type="submit" class="btn btn-secondary"><?= icon('timer') ?> Buchen</button></div>
        </form>
        <?php endif; ?>
        </div>
    </details>

    <section class="card" id="history">
        <h2 class="card-title">Verlauf</h2>
        <?php include __DIR__ . '/_history.php'; ?>
        <?php if ($notifications !== []): ?>
        <details class="mt-2">
            <summary class="text-sm">Benachrichtigungen (<?= count($notifications) ?>)</summary>
            <table class="table table-compact">
                <thead><tr><th>Zeit</th><th>Ereignis</th><th>Empfänger</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($notifications as $n): ?><tr><td class="nowrap text-sm"><?= fmt_datetime($n['created_at']) ?></td><td class="text-sm"><?= e($n['event_key']) ?></td><td class="text-sm"><?= e($n['recipient']) ?></td><td><?= badge($n['status'], $n['status'] === 'sent' ? 'success' : ($n['status'] === 'failed' ? 'danger' : 'neutral')) ?><?php if (!empty($n['error'])): ?><div class="text-xs text-muted"><?= e($n['error']) ?></div><?php endif; ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </details>
        <?php endif; ?>
    </section>
</div>

<aside class="ticket-sidebar">

    <section class="card sla-panel" id="sla">
        <h2 class="card-title"><?= icon('timer', 'icon icon-xs') ?> SLA <?php if ($sla): ?><span class="text-muted text-sm">· <?= e($sla['name']) ?></span><?php endif; ?></h2>
        <?php if ($sla === null): ?>
            <p class="text-muted text-sm">Kein SLA zugeordnet.</p>
        <?php else: ?>
            <?php if ($slaInfo['paused']): ?>
                <div class="sla-paused"><?= icon('clock', 'icon icon-xs') ?> SLA pausiert seit <?= fmt_datetime($ticket['sla_paused_at']) ?></div>
                <?php if (!empty($ticket['paused_until'])): ?><div class="text-xs text-muted">Pausiert bis <?= fmt_datetime($ticket['paused_until']) ?> – danach automatisch „In Bearbeitung“.</div><?php endif; ?>
            <?php endif; ?>
            <?php foreach (['response' => 'Reaktion', 'resolution' => 'Lösung'] as $k => $label): $info = $slaInfo[$k]; ?>
            <div class="sla-target<?= $stateClass($info['state']) ?>">
                <div class="sla-target-label"><?= $label ?> <span class="badge is-<?= $info['state'] === 'breached' ? 'danger' : ($info['state'] === 'warning' ? 'warning' : ($info['state'] === 'met' ? 'success' : 'neutral')) ?>"><?= e($stateLabel[$info['state']] ?? $info['state']) ?></span></div>
                <div class="sla-target-value">
                    <?php if ($info['due'] === null): ?>–
                    <?php elseif (in_array($info['state'], ['met', 'none'], true) || $closed): ?>Fällig <?= fmt_datetime($info['due']) ?>
                    <?php elseif ($slaInfo['paused']): ?>Fällig <?= fmt_datetime($info['due']) ?> (pausiert)
                    <?php else: ?><span class="sla-remaining<?= $info['remaining'] !== null && $info['remaining'] < 0 ? ' is-overdue' : '' ?>" data-hd-due="<?= e($info['due']) ?>"><?= e(\App\Services\Helpdesk\TicketSlaService::humanRemaining($info['remaining'])) ?></span> <span class="text-muted text-xs">(<?= fmt_datetime($info['due']) ?>)</span><?php endif; ?>
                </div>
                <?php if (!$closed && !in_array($info['state'], ['met', 'none'], true)): ?><?= $percentBar($info['percent'], $info['state'], $ticket['created_at'], $info['due'], (int) ($slaInfo['warning_percent'] ?? 75)) ?><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if ($slaInfo['paused_minutes'] > 0): ?><div class="text-xs text-muted">Bisher <?= (int) $slaInfo['paused_minutes'] ?> min pausiert.</div><?php endif; ?>
            <?php if (!empty($ticket['first_response_at'])): ?><div class="text-xs text-muted">Erste Reaktion: <?= fmt_datetime($ticket['first_response_at']) ?></div><?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="card" id="assign">
        <h2 class="card-title">Zuweisung</h2>
        <dl class="detail-list">
            <dt>Gruppe</dt><dd><?= e($ticket['group_name'] ?? '–') ?></dd>
            <dt>Bearbeiter</dt><dd><?= $ticket['assignee_name'] ? e($ticket['assignee_name']) : '<span class="text-muted">nicht zugewiesen</span>' ?></dd>
            <?php if (!empty($ticket['forwarded_at']) && empty($ticket['forward_acknowledged_at'])): ?>
                <dt>Weitergeleitet</dt><dd><?= fmt_datetime($ticket['forwarded_at']) ?><?= !empty($ticket['forwarded_by_name']) ? ' von ' . e($ticket['forwarded_by_name']) : '' ?> <span class="badge is-warning">noch nicht übernommen</span></dd>
            <?php endif; ?>
            <?php if ($ticket['deputy_name']): ?><dt>Vertretung</dt><dd><?= e($ticket['deputy_name']) ?></dd><?php endif; ?>
        </dl>
        <?php if ($can('helpdesk.assign') && !$readOnly): ?>
        <details class="side-edit"<?= empty($ticket['assignee_user_id']) ? ' open' : '' ?>>
            <summary class="side-edit-summary"><?= icon('pen', 'icon icon-xs') ?> Zuweisung ändern</summary>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/assign" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="version" value="<?= (int) $ticket['version'] ?>">
                <div class="form-group">
                    <label for="as-group">Gruppe</label>
                    <select id="as-group" name="group_id" data-hd-group="#as-user">
                        <option value="">– keine –</option>
                        <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= selected($ticket['group_id'], $g['id']) ?>><?= e($g['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="as-user">Bearbeiter</label>
                    <select id="as-user" name="assignee_user_id" data-selected="<?= (int) ($ticket['assignee_user_id'] ?? 0) ?>">
                        <option value="">– nicht zugewiesen –</option>
                        <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected($ticket['assignee_user_id'], $a['id']) ?>><?= e($a['display_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="as-deputy">Vertretung</label>
                    <select id="as-deputy" name="deputy_user_id">
                        <option value="">– keine –</option>
                        <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected($ticket['deputy_user_id'], $a['id']) ?>><?= e($a['display_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <label class="checkbox-row"><input type="checkbox" name="keep_group" value="1" checked> Gruppe beibehalten, wenn Bearbeiter in anderer Gruppe</label>
                <button type="submit" class="btn btn-secondary btn-sm">Speichern</button>
            </form>
        </details>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="card-title">Details</h2>
        <dl class="detail-list">
            <dt>Melder</dt><dd><?php if ($ticket['requester_employee_id']): ?><a href="/employees/<?= (int) $ticket['requester_employee_id'] ?>"><?= e($ticket['requester_name']) ?></a><?php else: ?><?= e($ticket['requester_name'] ?? '–') ?><?php endif; ?>
                <?php if ($ticket['requester_department'] || $ticket['requester_email']): ?><div class="text-xs text-muted"><?= e(implode(' · ', array_filter([$ticket['requester_department'], $ticket['requester_email']]))) ?></div><?php endif; ?></dd>
            <?php if ($ticket['requester_phone']): ?><dt>Rufnummer</dt><dd class="detail-phone"><?= e($ticket['requester_phone']) ?></dd><?php endif; ?>
            <?php
            // Automatisch erfasste Angaben aus dem Störungsformular (Windows-Konto, Rechner, AD-Abgleich)
            $reporterInfo = array_filter([
                $ticket['reporter_username'] ?? null,
                $ticket['reporter_host'] ?? ($ticket['reporter_ip'] ?? null),
                $ticket['reporter_phone'] ?? null,
                $ticket['reporter_department'] ?? null,
            ]);
            ?>
            <?php if ($reporterInfo !== []): ?><dt>Automatisch erfasst</dt><dd><?= e(implode(' · ', $reporterInfo)) ?><?php if (!empty($ticket['reporter_host']) && !empty($ticket['reporter_ip'])): ?><div class="text-xs text-muted">IP <?= e($ticket['reporter_ip']) ?></div><?php endif; ?></dd><?php endif; ?>
            <?php if ($ticket['affected_employee_id'] && (int) $ticket['affected_employee_id'] !== (int) ($ticket['requester_employee_id'] ?? 0)): ?><dt>Betroffen</dt><dd><a href="/employees/<?= (int) $ticket['affected_employee_id'] ?>"><?= e($ticket['affected_name']) ?></a></dd><?php endif; ?>
            <dt>Kategorie</dt><dd><?= e($ticket['category_name'] ?? '–') ?><?= $ticket['subcategory_name'] ? ' / ' . e($ticket['subcategory_name']) : '' ?></dd>
            <dt>Auswirkung / Dringlichkeit</dt><dd><?= e($impactLabels[(int) $ticket['impact']] ?? $ticket['impact']) ?> / <?= e($urgencyLabels[(int) $ticket['urgency']] ?? $ticket['urgency']) ?></dd>
            <?php if ($ticket['location_path'] ?? null): ?><dt>Standort</dt><dd><?= e($ticket['location_path']) ?></dd><?php endif; ?>
            <?php if ($ticket['cost_center_number'] ?? null): ?><dt>Kostenstelle</dt><dd><?= e($ticket['cost_center_number']) ?> <?= e($ticket['cost_center_name'] ?? '') ?></dd><?php endif; ?>
            <?php if ($ticket['due_at'] ?? null): ?><dt>Wunschtermin</dt><dd><?= fmt_datetime($ticket['due_at']) ?></dd><?php endif; ?>
            <?php if ($ticket['external_reference']): ?><dt>Externe Referenz</dt><dd><?= e($ticket['external_reference']) ?></dd><?php endif; ?>
            <dt>Aktualisiert</dt><dd><?= fmt_datetime($ticket['updated_at']) ?></dd>
            <?php if ($ticket['closed_at']): ?><dt>Geschlossen</dt><dd><?= fmt_datetime($ticket['closed_at']) ?></dd><?php endif; ?>
            <dt>Version</dt><dd><?= (int) $ticket['version'] ?></dd>
        </dl>
    </section>

    <section class="card" id="tags">
        <h2 class="card-title">Tags</h2>
        <?php if ($tags !== []): ?><div class="tag-list"><?php foreach ($tags as $t): ?><a class="tag-chip is-<?= e($t['color'] ?? 'neutral') ?>" href="/helpdesk/tickets?view=all&amp;tag=<?= urlencode((string) $t['name']) ?>"><?= e($t['name']) ?></a><?php endforeach; ?></div><?php else: ?><p class="text-muted text-sm">Keine Tags.</p><?php endif; ?>
        <?php if ($canUpdate): ?>
        <details class="side-edit">
            <summary class="side-edit-summary"><?= icon('pen', 'icon icon-xs') ?> Tags bearbeiten</summary>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/tags" class="inline-form">
                <?= csrf_field() ?>
                <div class="form-group"><label for="tags-input" class="sr-only">Tags</label><input id="tags-input" name="tags" list="tags-datalist" data-hd-tags value="<?= e(implode(', ', array_map(static fn (array $t): string => (string) $t['name'], $tags))) ?>" placeholder="tag1, tag2 …"></div>
                <datalist id="tags-datalist"></datalist>
                <button type="submit" class="btn btn-secondary btn-sm">Speichern</button>
            </form>
        </details>
        <?php endif; ?>
    </section>

    <section class="card" id="assets">
        <h2 class="card-title">Assets <span class="text-muted">(<?= count($assets) ?>)</span></h2>
        <?php if ($assets === []): ?><p class="text-muted text-sm">Keine Assets verknüpft.</p><?php else: ?>
        <ul class="side-list">
            <?php foreach ($assets as $a): ?>
            <li>
                <div class="side-list-main"><a href="/assets/<?= (int) $a['asset_id'] ?>"><?= e($a['inventory_number']) ?></a> <?= e($a['asset_name'] ?? $a['article_name'] ?? '') ?><div class="text-xs text-muted"><?= e($a['asset_type_name']) ?><?= $a['serial_number'] ? ' · SN ' . e($a['serial_number']) : '' ?><?= $a['employee_name'] ? ' · ' . e($a['employee_name']) : '' ?><?= !empty($a['note']) ? ' · ' . e($a['note']) : '' ?></div></div>
                <?php if ($canUpdate): ?><form method="post" action="/helpdesk/tickets/<?= $id ?>/assets/<?= (int) $a['asset_id'] ?>/remove" class="inline-form" data-confirm="Verknüpfung entfernen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" aria-label="Entfernen"><?= icon('x') ?></button></form><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($canUpdate): ?>
        <details class="side-edit">
            <summary class="side-edit-summary"><?= icon('link', 'icon icon-xs') ?> Asset verknüpfen</summary>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/assets" class="stack-form">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="asset-select">Asset verknüpfen</label>
                    <select id="asset-select" name="asset_id" required>
                        <option value="">– Asset des Melders wählen –</option>
                        <?php foreach ($employeeAssets as $ea): ?><option value="<?= (int) $ea['id'] ?>"><?= e($ea['inventory_number']) ?> · <?= e($ea['name'] ?? $ea['article_name'] ?? '') ?></option><?php endforeach; ?>
                    </select>
                    <p class="form-hint">Es werden die dem Melder zugeordneten Assets angeboten. Andere Assets über <a href="/assets">Assets</a> → „Ticket erstellen“ verknüpfen.</p>
                </div>
                <div class="form-group"><label for="asset-note">Notiz</label><input id="asset-note" name="note" maxlength="255"></div>
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('link') ?> Verknüpfen</button>
            </form>
        </details>
        <?php endif; ?>
    </section>

    <section class="card" id="relations">
        <h2 class="card-title">Beziehungen</h2>
        <?php if ($relations === [] && $merged === []): ?><p class="text-muted text-sm">Keine Beziehungen.</p><?php endif; ?>
        <?php if ($relations !== []): ?>
        <ul class="side-list">
            <?php foreach ($relations as $r): ?>
            <li>
                <div class="side-list-main"><span class="text-xs text-muted"><?= e($r['direction'] === 'out' ? ($relationLabels[$r['type']] ?? $r['type']) : 'Von: ' . ($relationLabels[$r['type']] ?? $r['type'])) ?></span><br><a href="/helpdesk/tickets/<?= (int) $r['other_id'] ?>"><?= e($r['other_number']) ?></a> <?= e($r['other_subject']) ?> <?= badge($r['other_status_name'], $r['other_status_color']) ?></div>
                <?php if ($canUpdate): ?><form method="post" action="/helpdesk/tickets/<?= $id ?>/relations/<?= (int) $r['id'] ?>/delete" class="inline-form" data-confirm="Beziehung entfernen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" aria-label="Entfernen"><?= icon('x') ?></button></form><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($merged !== []): ?>
        <h3 class="text-sm mt-2">In dieses Ticket zusammengeführt</h3>
        <ul class="side-list"><?php foreach ($merged as $m): ?><li><div class="side-list-main"><a href="/helpdesk/tickets/<?= (int) $m['id'] ?>"><?= e($m['number']) ?></a> <?= e($m['subject']) ?></div></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($canUpdate): ?>
        <details class="side-edit">
            <summary class="side-edit-summary"><?= icon('link', 'icon icon-xs') ?> Ticket verknüpfen</summary>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/relations" class="stack-form">
                <?= csrf_field() ?>
                <div class="form-row">
                    <div class="form-group"><label for="rel-type">Typ</label><select id="rel-type" name="type"><?php foreach ($relationLabels as $k => $l): ?><option value="<?= e($k) ?>"<?= selected('related', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="rel-ticket">Ticket</label><input id="rel-ticket" name="related" list="rel-tickets" data-hd-ticket-search="<?= $id ?>" placeholder="Nummer oder Betreff" required autocomplete="off"><datalist id="rel-tickets"></datalist></div>
                </div>
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('link') ?> Verknüpfen</button>
            </form>
        </details>
        <?php endif; ?>
        <?php if ($can('helpdesk.merge') && !$readOnly && !$closed): ?>
        <div id="merge" class="side-edit">
            <button type="button" class="btn btn-danger btn-sm" data-dialog-open="merge-dialog"><?= icon('merge') ?> Ticket zusammenführen …</button>
            <p class="form-hint">Führt dieses Ticket in ein bestehendes Ticket über und informiert die meldende Person optional per E-Mail.</p>
        </div>
        <?php endif; ?>
    </section>

    <section class="card" id="watchers">
        <h2 class="card-title">Beobachter <span class="text-muted">(<?= count($watchers) ?>)</span></h2>
        <?php if ($watchers === []): ?><p class="text-muted text-sm">Niemand beobachtet dieses Ticket.</p><?php else: ?>
        <ul class="side-list member-list">
            <?php foreach ($watchers as $w): ?>
            <li><div class="side-list-main"><?= e($w['display_name']) ?></div><?php if ($canUpdate): ?><form method="post" action="/helpdesk/tickets/<?= $id ?>/watchers/<?= (int) $w['user_id'] ?>/remove" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" aria-label="Entfernen"><?= icon('x') ?></button></form><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($canUpdate): ?>
        <details class="side-edit">
            <summary class="side-edit-summary"><?= icon('plus', 'icon icon-xs') ?> Beobachter hinzufügen</summary>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/watchers" class="inline-form">
                <?= csrf_field() ?>
                <div class="form-group"><label for="watcher-user" class="sr-only">Benutzer</label><select id="watcher-user" name="user_id" required><option value="">– Benutzer –</option><?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['display_name']) ?></option><?php endforeach; ?></select></div>
                <button type="submit" class="btn btn-secondary btn-sm"><?= icon('plus') ?></button>
            </form>
        </details>
        <?php endif; ?>
    </section>

    <?php if ($can('knowledgebase.view') && ($articles !== [] || $linkedArticles !== [] || $can('knowledgebase.manage'))): ?>
    <section class="card" id="knowledge">
        <h2 class="card-title"><?= icon('book', 'icon icon-xs') ?> Wissensdatenbank</h2>
        <?php if ($linkedArticles !== []): ?>
        <h3 class="text-sm">Verknüpfte Artikel</h3>
        <ul class="side-list"><?php foreach ($linkedArticles as $a): ?><li><div class="side-list-main"><a href="/helpdesk/knowledge/<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a></div></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($articles !== []): ?>
        <h3 class="text-sm">Vorschläge</h3>
        <ul class="side-list"><?php foreach ($articles as $a): ?><li><div class="side-list-main"><a href="/helpdesk/knowledge/<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a><?php if (!empty($a['summary'])): ?><div class="text-xs text-muted"><?= e(mb_strimwidth((string) $a['summary'], 0, 120, '…')) ?></div><?php endif; ?></div>
            <?php if ($can('knowledgebase.manage') && !$readOnly): ?><form method="post" action="/helpdesk/knowledge/<?= (int) $a['id'] ?>/tickets" class="inline-form"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= e($ticket['number']) ?>"><button type="submit" class="btn btn-ghost btn-sm" title="Mit Ticket verknüpfen"><?= icon('link') ?></button></form><?php endif; ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($can('knowledgebase.manage') && !empty($ticket['resolution'])): ?>
            <a class="btn btn-secondary btn-sm mt-2" href="/helpdesk/knowledge/new?ticket=<?= $id ?>"><?= icon('plus') ?> Artikel aus Lösung erstellen</a>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($can('helpdesk.delete')): ?>
    <section class="card card-danger">
        <form method="post" action="/helpdesk/tickets/<?= $id ?>/delete" data-confirm="Ticket <?= e($ticket['number']) ?> unwiderruflich löschen? Kommentare, Anhänge und Verlauf werden ebenfalls entfernt."><?= csrf_field() ?><button type="submit" class="btn btn-danger btn-sm"><?= icon('trash') ?> Ticket löschen</button></form>
    </section>
    <?php endif; ?>
</aside>
</div>

<?php if ($transitions !== [] && !$readOnly): ?>
<dialog id="status-dialog" class="dialog" data-paused-until="<?= e($ticket['paused_until'] ?? '') ?>">
    <form method="post" action="/helpdesk/tickets/<?= $id ?>/status">
        <?= csrf_field() ?>
        <input type="hidden" name="version" value="<?= (int) $ticket['version'] ?>">
        <input type="hidden" name="status" value="">
        <div class="dialog-header"><h2>Status → <span data-status-label></span></h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button></div>
        <div class="dialog-body">
            <?php if ($pauseTransitions !== []): ?>
            <div class="form-group" data-show-for="<?= e($pauseCodes) ?>">
                <label for="st-pause-reason">Pausieren wegen</label>
                <select id="st-pause-reason" name="pause_reason" data-hd-pause-reason>
                    <?php foreach ($pauseTransitions as $p): ?>
                        <option value="<?= e($p['code']) ?>"><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" data-show-for="<?= e($pauseCodes) ?>">
                <label for="st-pause-until">Pausiert bis <span class="text-muted">(Datum und Uhrzeit)</span></label>
                <input type="datetime-local" id="st-pause-until" name="pause_until" data-required-when-visible>
                <p class="form-hint">Nach Ablauf läuft das Ticket automatisch weiter (Status „In Bearbeitung“).</p>
            </div>
            <?php endif; ?>
            <div class="form-group" data-show-for="resolved,closed">
                <label for="st-resolution">Lösung <span class="text-muted">(Pflicht beim Schließen)</span></label>
                <textarea id="st-resolution" name="resolution" rows="4" data-required-when-visible data-hd-field="ticket_resolution"><?= e($ticket['resolution'] ?? '') ?></textarea>
            </div>
            <div class="form-group" data-show-for="cancelled,closed">
                <label for="st-reason">Grund <span class="text-muted">(beim Stornieren Pflicht, beim Schließen optional)</span></label>
                <input id="st-reason" name="reason" maxlength="255" data-required-when-visible data-required-for="cancelled">
            </div>
            <?php if ($errorOrigins !== []): ?>
            <div class="form-group" data-show-for="closed">
                <label for="st-error-origin">Fehlerursprung <span class="text-muted">(optional)</span></label>
                <select id="st-error-origin" name="error_origin">
                    <option value="">– keine Angabe –</option>
                    <?php foreach ($errorOrigins as $origin): ?>
                        <option value="<?= e($origin) ?>"><?= e($origin) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">Katalog aus den Help-Desk-Einstellungen (Reiter „Fehlerursprung“). Der Wert erscheint im Schließungstext als <code>{fehlerursprung}</code>.</p>
            </div>
            <?php endif; ?>
            <div class="form-group" data-show-for="closed">
                <label class="checkbox-row"><input type="checkbox" name="notify_requester" value="1" checked data-hd-notify-requester> Melder über die Schließung informieren</label>
                <p class="form-hint">Sendet die Schließungsbenachrichtigung aus den Help-Desk-Einstellungen (Reiter „Vorlageneinstellungen“) mit dem Grund an den Melder. Ohne Häkchen erhält der Melder keine E-Mail.</p>
            </div>
            <div class="form-group">
                <label for="st-note">Kommentar an den Melder <span class="text-muted">(optional)</span></label>
                <textarea id="st-note" name="note" rows="3" data-hd-field="ticket_comment"></textarea>
            </div>
        </div>
        <div class="dialog-footer"><button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button><button type="submit" class="btn btn-primary">Status setzen</button></div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($isAgent && $can('helpdesk.assign') && !$readOnly && !$closed): ?>
<dialog id="forward-dialog" class="dialog">
    <form method="post" action="/helpdesk/tickets/<?= $id ?>/forward">
        <?= csrf_field() ?>
        <input type="hidden" name="version" value="<?= (int) $ticket['version'] ?>">
        <div class="dialog-header">
            <h2><?= icon('swap') ?> Ticket weiterleiten</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button>
        </div>
        <div class="dialog-body">
            <p class="text-muted text-sm">Das Ticket wechselt sofort zum ausgewählten Bearbeiter. Er erhält einen Hinweis auf seinem Help-Desk-Dashboard; bis er das Ticket übernimmt, ist es in der Ticketliste farblich hervorgehoben.</p>
            <div class="form-group">
                <label for="fw-group">Gruppe</label>
                <select id="fw-group" name="group_id" data-hd-group="#fw-user">
                    <option value="">– keine –</option>
                    <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= selected($ticket['group_id'], $g['id']) ?>><?= e($g['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="fw-user">Bearbeiter <span class="text-muted">(Pflicht)</span></label>
                <select id="fw-user" name="assignee_user_id" required>
                    <option value="">– Agent wählen –</option>
                    <?php foreach ($agents as $a): ?>
                        <?php if ((int) $a['id'] === (int) $user['id'] || (int) $a['id'] === (int) ($ticket['assignee_user_id'] ?? 0)) { continue; } ?>
                        <option value="<?= (int) $a['id'] ?>"><?= e($a['display_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="checkbox-row"><input type="checkbox" name="keep_group" value="1" checked> Gruppe beibehalten, wenn Bearbeiter in anderer Gruppe</label>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary"><?= icon('swap') ?> Weiterleiten</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php if (($isFirstLevelSupport ?? false) && $secondLevelSupport !== null): ?>
<dialog id="second-level-dialog" class="dialog">
    <form method="post" action="/helpdesk/tickets/<?= $id ?>/assign-second-level">
        <?= csrf_field() ?>
        <div class="dialog-header"><h2>An 2nd Level zuweisen</h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button></div>
        <div class="dialog-body">
            <p class="text-muted text-sm">Das Ticket wird <strong><?= e($secondLevelSupport['user_display_name']) ?></strong> (2nd Level Support von heute) zugewiesen.</p>
            <div class="form-group">
                <label for="sl-note">Kommentar <span class="text-muted">(Pflicht)</span></label>
                <textarea id="sl-note" name="note" rows="4" required data-hd-field="second_level_note"></textarea>
            </div>
        </div>
        <div class="dialog-footer"><button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button><button type="submit" class="btn btn-primary">Zuweisen</button></div>
    </form>
</dialog>
<?php endif; ?>
<?php if ($canConvert): ?>
<dialog id="convert-dialog" class="dialog">
    <form method="post" action="/helpdesk/tickets/<?= $id ?>/in-aufgabe">
        <?= csrf_field() ?>
        <div class="dialog-header">
            <h2><?= icon('inbox') ?> In Aufgabe umwandeln</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button>
        </div>
        <div class="dialog-body">
            <p class="text-muted text-sm">Ticket <strong><?= e($ticket['number']) ?></strong> wird in eine Aufgabe umgewandelt. Das Ticket wird dabei geschlossen und mit der Aufgabe verknüpft.</p>
            <div class="form-group">
                <label for="convert-category">Kategorie <span class="text-muted">(optional)</span></label>
                <select id="convert-category" name="category_id">
                    <option value="">– keine Kategorie –</option>
                    <?php foreach ($taskCategories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">Die Kategorie erscheint als Pill vor dem Aufgabentitel und kann später in der Aufgabe geändert werden.</p>
            </div>
            <div class="form-group">
                <label for="convert-due">Fällig <span class="text-muted">(optional)</span></label>
                <input type="date" id="convert-due" name="due_at">
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary"><?= icon('inbox') ?> Umwandeln</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php if (!empty($mailCompose['available'])): ?>
<dialog id="mail-dialog" class="dialog hd-mail-dialog" data-hd-mail
        data-signature-url="/helpdesk/tickets/<?= $id ?>/mail/signature"
        data-ai-enabled="<?= !empty($aiEnabled) ? '1' : '0' ?>"
        data-ticket="<?= e($ticket['number']) ?>"
        data-subject="<?= e($mailCompose['subject']) ?>"
        data-max-chars="<?= (int) $mailCompose['max_chars'] ?>">
    <form method="post" action="/helpdesk/tickets/<?= $id ?>/mail" data-hd-mail-form>
        <?= csrf_field() ?>
        <input type="hidden" name="body_html" value="" data-hd-mail-body>
        <div class="dialog-header">
            <h2><?= icon('mail') ?> E-Mail senden</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button>
        </div>
        <div class="dialog-body">
            <div class="hd-mail-recipients">
                <div class="hd-mail-recipient">
                    <span class="hd-mail-recipient-role">An</span>
                    <span class="hd-mail-recipient-name"><?= e($mailCompose['requester']['name'] !== '' ? $mailCompose['requester']['name'] : $mailCompose['requester']['email']) ?></span>
                    <span class="text-muted text-xs">&lt;<?= e($mailCompose['requester']['email']) ?>&gt;</span>
                </div>
                <?php if ($mailCompose['affected']['email'] !== ''): ?>
                <label class="checkbox-row hd-mail-recipient-option">
                    <input type="checkbox" name="to_affected" value="1" data-hd-mail-affected>
                    Auch an die betroffene Person: <?= e($mailCompose['affected']['name'] !== '' ? $mailCompose['affected']['name'] : $mailCompose['affected']['email']) ?>
                    <span class="text-muted text-xs">&lt;<?= e($mailCompose['affected']['email']) ?>&gt;</span>
                </label>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="mail-subject">Betreff</label>
                <input id="mail-subject" name="subject" maxlength="180" required value="<?= e($mailCompose['subject']) ?>" data-hd-mail-subject>
            </div>

            <div class="form-group">
                <label for="mail-body">Nachricht</label>
                <div id="mail-body" class="hd-mail-editor" contenteditable="true" role="textbox" aria-multiline="true"
                     spellcheck="true" data-placeholder="Nachricht an die meldende Person …" data-hd-mail-editor></div>
                <p class="form-hint">Rechtsklick öffnet das Kontextmenü mit Ausschneiden, Kopieren, Einfügen und „Mit KI verbessern“.</p>
            </div>

            <?php if ($mailCompose['pauses'] !== []): ?>
            <fieldset class="hd-mail-pause">
                <legend>Ticket pausieren <span class="text-muted">(optional)</span></legend>
                <div class="form-group">
                    <label for="mail-pause-code">Grund</label>
                    <select id="mail-pause-code" name="pause_code" data-hd-mail-pause-code>
                        <option value="">Nicht pausieren</option>
                        <?php foreach ($mailCompose['pauses'] as $p): ?>
                            <option value="<?= e($p['code']) ?>"><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" data-hd-mail-pause-until hidden>
                    <label for="mail-pause-until">Pausiert bis <span class="text-muted">(Datum und Uhrzeit)</span></label>
                    <input type="datetime-local" id="mail-pause-until" name="pause_until" value="<?= e($mailCompose['pause_default_until']) ?>" disabled>
                    <p class="form-hint">Bis dahin gilt das Ticket als pausiert; die Antwort der meldenden Person startet die Bearbeitung wieder.</p>
                </div>
            </fieldset>
            <?php endif; ?>

            <p class="alert alert-danger" data-hd-mail-error hidden></p>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary" data-hd-mail-submit><?= icon('mail') ?> Senden</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($can('helpdesk.merge') && !$readOnly && !$closed && !empty($mergeCompose)): ?>
<dialog id="merge-dialog" class="dialog hd-merge-dialog" data-hd-merge
        data-source="<?= $id ?>"
        data-number="<?= e($ticket['number']) ?>">
    <form method="post" action="/helpdesk/tickets/<?= $id ?>/merge" data-hd-merge-form>
        <?= csrf_field() ?>
        <input type="hidden" name="body_html" value="" data-hd-merge-body>
        <div class="dialog-header">
            <h2><?= icon('merge') ?> Ticket zusammenführen</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button>
        </div>
        <div class="dialog-body">
            <p class="text-muted text-sm">Dieses Ticket <strong><?= e($ticket['number']) ?></strong> wird in das Zielticket überführt und anschließend storniert. Kommentare, Anhänge, Arbeitszeiten, Assets, Tags und Beobachter wandern mit.</p>

            <div class="form-group">
                <label for="merge-target">Zielticket</label>
                <div class="hd-merge-search">
                    <input id="merge-target" type="text" placeholder="Ticketnummer oder Betreff" autocomplete="off"
                           role="combobox" aria-expanded="false" aria-controls="merge-suggestions" aria-autocomplete="list"
                           data-hd-merge-search>
                    <input type="hidden" name="target" value="" data-hd-merge-target>
                    <ul id="merge-suggestions" class="hd-merge-suggestions" role="listbox" aria-label="Ticketvorschläge"
                        data-hd-merge-suggestions hidden></ul>
                </div>
                <p class="hd-merge-selected" data-hd-merge-selected hidden></p>
                <p class="form-hint">Vorschläge stammen aus den Tickets derselben meldenden Person, offene zuerst; auch gelöste und geschlossene Tickets sind möglich. Zusätzlich Suche über Nummer oder Betreff.</p>
                <p class="alert alert-danger" data-hd-merge-target-error hidden></p>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="merge-reason">Grund</label>
                    <select id="merge-reason" name="reason_code" data-hd-merge-reason>
                        <?php foreach ($mergeCompose['reasons'] as $reasonOption): ?>
                        <option value="<?= e($reasonOption['code']) ?>"<?= selected($mergeCompose['default_reason'], $reasonOption['code']) ?>><?= e($reasonOption['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="merge-reason-note">Ergänzung <span class="text-muted">(optional)</span></label>
                    <input id="merge-reason-note" name="reason_note" maxlength="160" data-hd-merge-note>
                </div>
            </div>

            <fieldset class="hd-merge-notify">
                <legend>Meldende Person informieren</legend>
                <?php if (!empty($mergeCompose['mail_available'])): ?>
                <label class="checkbox-row"><input type="checkbox" name="notify" value="1" data-hd-merge-notify checked> Info-E-Mail senden</label>
                <div class="hd-merge-mail" data-hd-merge-mail>
                    <div class="hd-mail-recipients">
                        <div class="hd-mail-recipient">
                            <span class="hd-mail-recipient-role">An</span>
                            <span class="hd-mail-recipient-name"><?= e($mergeCompose['requester']['name'] !== '' ? $mergeCompose['requester']['name'] : $mergeCompose['requester']['email']) ?></span>
                            <span class="text-muted text-xs">&lt;<?= e($mergeCompose['requester']['email']) ?>&gt;</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="merge-subject">Betreff</label>
                        <input id="merge-subject" name="subject" maxlength="180" value="<?= e($mergeCompose['subject']) ?>" data-hd-merge-subject>
                    </div>
                    <div class="form-group">
                        <label for="merge-body">Nachricht</label>
                        <div id="merge-body" class="hd-mail-editor" contenteditable="true" role="textbox" aria-multiline="true"
                             spellcheck="true" data-placeholder="Nachricht zur Zusammenführung …" data-hd-merge-editor><?= $mergeCompose['body_html'] ?></div>
                        <p class="form-hint">Platzhalter <code>{{nummer}}</code>, <code>{{ziel}}</code> und <code>{{grund}}</code> werden beim Senden ersetzt. Es wird bewusst keine Signatur des Agenten angehängt.</p>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="notify" value="0">
                <p class="form-hint"><?= e($mergeCompose['mail_reason']) ?></p>
                <?php endif; ?>
            </fieldset>

            <p class="alert alert-danger" data-hd-merge-error hidden></p>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-danger" data-hd-merge-submit><?= icon('merge') ?> Zusammenführen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
