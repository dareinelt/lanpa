<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$id = (int) $ticket['id'];
$closed = in_array($ticket['status_category'], ['resolved', 'closed', 'cancelled'], true);
$isMerged = $ticket['merged_into_ticket_id'] !== null;
$readOnly = $isMerged;
$canUpdate = $can('helpdesk.update') && !$readOnly;
$stateLabel = ['ok' => 'Im Plan', 'warning' => 'Warnung', 'breached' => 'Verletzt', 'met' => 'Eingehalten', 'none' => '–', 'paused' => 'Pausiert'];
$stateClass = static fn (string $s): string => match ($s) { 'warning' => ' is-warning', 'breached' => ' is-breached', 'met' => ' is-met', default => '' };
$eventLabels = [
    'created' => 'Ticket angelegt', 'status_changed' => 'Status geändert', 'reopened' => 'Wieder geöffnet', 'resolved' => 'Gelöst', 'closed' => 'Geschlossen', 'cancelled' => 'Storniert',
    'assigned' => 'Zugewiesen', 'unassigned' => 'Zuweisung entfernt', 'group_changed' => 'Gruppe geändert', 'deputy_changed' => 'Vertretung geändert', 'field_changed' => 'Feld geändert',
    'comment' => 'Kommentar', 'internal_note' => 'Interne Notiz', 'attachment_added' => 'Anhang hinzugefügt', 'attachment_removed' => 'Anhang entfernt',
    'asset_linked' => 'Asset verknüpft', 'asset_unlinked' => 'Asset-Verknüpfung entfernt', 'tags_changed' => 'Tags geändert', 'watcher_added' => 'Beobachter hinzugefügt',
    'worklog_added' => 'Arbeitszeit erfasst', 'worklog_removed' => 'Arbeitszeit gelöscht', 'relation_added' => 'Beziehung angelegt', 'relation_removed' => 'Beziehung entfernt',
    'merged' => 'Zusammengeführt', 'merge_received' => 'Ticket aufgenommen', 'escalated' => 'Eskaliert', 'sla_warning' => 'SLA-Warnung', 'sla_breached' => 'SLA verletzt',
    'knowledge_linked' => 'Wissensartikel verknüpft', 'knowledge_created' => 'Wissensartikel erstellt', 'rule_applied' => 'Regel angewendet', 'notification' => 'Benachrichtigung',
    'viewed' => 'Ticket angesehen',
];
$eventClass = static fn (string $t): string => match ($t) { 'sla_breached', 'escalated', 'cancelled' => ' is-danger', 'resolved', 'closed' => ' is-success', 'sla_warning', 'reopened' => ' is-warning', default => '' };
$percentBar = static function (?int $percent, string $state): string {
    if ($percent === null) {
        return '';
    }
    $p = max(0, min(100, $percent));

    return '<progress class="sla-bar' . ($state === 'breached' ? ' is-breached' : ($state === 'warning' ? ' is-warning' : '')) . '" max="100" value="' . $p . '" aria-label="SLA-Verbrauch ' . $p . '%"></progress>';
};
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
            <?php if ($ticket['source'] === 'email'): ?>
                <span class="badge badge-info"><?= icon('mail', 'icon icon-xs') ?> Per E-Mail eröffnet<?= !empty($ticket['mail_from_address']) ? ' von ' . e(trim(($ticket['mail_from_name'] ?? '') . ' <' . $ticket['mail_from_address'] . '>')) : '' ?></span>
            <?php endif; ?>
            <span class="text-muted text-sm">Quelle: <?= e($sources[$ticket['source']] ?? $ticket['source']) ?> · erstellt <?= fmt_datetime($ticket['created_at']) ?> von <?= e($ticket['created_by_name'] ?? 'system') ?></span>
        </div>
    </div>
    <div class="page-actions">
        <form method="post" action="/helpdesk/tickets/<?= $id ?>/watch" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-secondary btn-sm"><?= icon('eye') ?> <?= $isWatching ? 'Nicht mehr beobachten' : 'Beobachten' ?></button></form>
        <?php if ($isAgent && $can('helpdesk.assign') && !$readOnly && !$closed && (int) ($ticket['assignee_user_id'] ?? 0) !== (int) $user['id']): ?>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/take" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-secondary btn-sm"><?= icon('user') ?> Übernehmen</button></form>
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

<?php if ($transitions !== [] && !$readOnly): ?>
<section class="ticket-actions" id="status">
    <span class="text-muted text-sm">Status ändern:</span>
    <?php foreach ($transitions as $t): ?>
        <button type="button" class="btn btn-sm <?= $t['category'] === 'resolved' || $t['category'] === 'closed' ? 'btn-primary' : ($t['category'] === 'cancelled' ? 'btn-danger' : 'btn-secondary') ?>" data-hd-status="<?= e($t['code']) ?>" data-hd-label="<?= e($t['name']) ?>" data-requires-resolution="<?= !empty($t['requires_resolution']) ? '1' : '0' ?>" data-requires-reason="<?= !empty($t['requires_reason']) ? '1' : '0' ?>"><?= e($t['name']) ?></button>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="ticket-layout">
<div class="ticket-main">

    <section class="card">
        <h2 class="card-title">Beschreibung</h2>
        <div class="ticket-description"><?= nl2br_e($ticket['description']) ?></div>
        <?php if (!empty($ticket['resolution'])): ?>
            <div class="ticket-resolution">
                <h3><?= icon('check', 'icon icon-xs') ?> Lösung</h3>
                <div><?= nl2br_e($ticket['resolution']) ?></div>
                <?php if (!empty($ticket['resolved_at'])): ?><div class="text-xs text-muted">Gelöst am <?= fmt_datetime($ticket['resolved_at']) ?><?= $ticket['resolved_by_name'] ?? null ? ' von ' . e($ticket['resolved_by_name']) : '' ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($ticket['close_reason'])): ?><p class="text-sm text-muted">Abschlussgrund: <?= e($ticket['close_reason']) ?></p><?php endif; ?>
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
            <li class="comment<?= $c['type'] === 'internal' ? ' is-internal' : '' ?><?= (int) $c['is_requester'] === 1 ? ' is-requester' : '' ?>" data-comment-type="<?= e($c['type']) ?>" id="comment-<?= (int) $c['id'] ?>">
                <div class="comment-head">
                    <span class="comment-author"><?= e($c['author_name']) ?></span>
                    <?php if ((int) $c['is_requester'] === 1): ?><span class="badge is-neutral">Melder</span><?php endif; ?>
                    <?php if ($c['type'] === 'internal'): ?><span class="badge is-warning">Intern</span><?php endif; ?>
                    <?php if ($c['source'] === 'email'): ?><span class="badge badge-info" title="<?= e(!empty($c['mail_from_address']) ? 'Absender: ' . $c['mail_from_address'] : 'Per E-Mail eingegangen') ?>"><?= icon('mail', 'icon icon-xs') ?> Per E-Mail<?= !empty($c['mail_from_address']) ? ' von ' . e($c['mail_from_address']) : '' ?></span><?php endif; ?>
                    <span class="text-muted text-xs"><?= fmt_datetime($c['created_at']) ?> · <?= e($sources[$c['source']] ?? $c['source']) ?></span>
                </div>
                <div class="comment-body"><?= nl2br_e($c['body']) ?></div>
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
                <textarea id="comment-body" name="body" rows="4" placeholder="Antwort oder Notiz eingeben …" maxlength="20000" data-hd-field="ticket_comment"></textarea>
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
        <?php if ($timeline === []): ?>
            <p class="empty-state">Kein Verlauf.</p>
        <?php else: ?>
        <ul class="timeline">
            <?php foreach (array_reverse($timeline) as $ev): ?>
            <li class="<?= trim($eventClass((string) $ev['type'])) ?>">
                <span class="timeline-dot"></span>
                <div class="timeline-time"><?= fmt_datetime($ev['created_at']) ?> · <?= e($ev['user_name']) ?></div>
                <div class="timeline-title"><?= e($eventLabels[$ev['type']] ?? ucfirst(str_replace('_', ' ', (string) $ev['type']))) ?><?php if (!empty($ev['field']) && !in_array($ev['type'], ['status_changed', 'assigned', 'unassigned', 'tags_changed'], true)): ?> <span class="text-muted">(<?= e($ev['field']) ?>)</span><?php endif; ?></div>
                <?php if ($ev['old_value'] !== null || $ev['new_value'] !== null): ?>
                <div class="timeline-detail">
                    <?php if ($ev['old_value'] !== null && $ev['new_value'] !== null): ?><s class="text-muted"><?= e($ev['old_value']) ?></s> → <strong><?= e($ev['new_value']) ?></strong>
                    <?php elseif ($ev['new_value'] !== null): ?><?= e($ev['new_value']) ?>
                    <?php else: ?><s class="text-muted"><?= e($ev['old_value']) ?></s><?php endif; ?>
                </div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
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
            <?php if ($slaInfo['paused']): ?><div class="sla-paused"><?= icon('clock', 'icon icon-xs') ?> SLA pausiert seit <?= fmt_datetime($ticket['sla_paused_at']) ?></div><?php endif; ?>
            <?php foreach (['response' => 'Reaktion', 'resolution' => 'Lösung'] as $k => $label): $info = $slaInfo[$k]; ?>
            <div class="sla-target<?= $stateClass($info['state']) ?>">
                <div class="sla-target-label"><?= $label ?> <span class="badge is-<?= $info['state'] === 'breached' ? 'danger' : ($info['state'] === 'warning' ? 'warning' : ($info['state'] === 'met' ? 'success' : 'neutral')) ?>"><?= e($stateLabel[$info['state']] ?? $info['state']) ?></span></div>
                <div class="sla-target-value">
                    <?php if ($info['due'] === null): ?>–
                    <?php elseif (in_array($info['state'], ['met', 'none'], true) || $closed): ?>Fällig <?= fmt_datetime($info['due']) ?>
                    <?php elseif ($slaInfo['paused']): ?>Fällig <?= fmt_datetime($info['due']) ?> (pausiert)
                    <?php else: ?><span class="sla-remaining<?= $info['remaining'] !== null && $info['remaining'] < 0 ? ' is-overdue' : '' ?>" data-hd-due="<?= e($info['due']) ?>"><?= e(\App\Services\Helpdesk\TicketSlaService::humanRemaining($info['remaining'])) ?></span> <span class="text-muted text-xs">(<?= fmt_datetime($info['due']) ?>)</span><?php endif; ?>
                </div>
                <?php if (!$closed && !in_array($info['state'], ['met', 'none'], true)): ?><?= $percentBar($info['percent'], $info['state']) ?><?php endif; ?>
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
        <details id="merge" class="side-edit">
            <summary class="side-edit-summary"><?= icon('merge', 'icon icon-xs') ?> Ticket zusammenführen</summary>
            <form method="post" action="/helpdesk/tickets/<?= $id ?>/merge" class="stack-form" data-confirm="Dieses Ticket in das Zielticket zusammenführen? Kommentare, Anhänge und Beobachter werden übertragen; dieses Ticket wird storniert.">
                <?= csrf_field() ?>
                <div class="form-group"><label for="merge-target">Zielticket</label><input id="merge-target" name="target" list="merge-tickets" data-hd-ticket-search="<?= $id ?>" placeholder="Nummer oder Betreff" required autocomplete="off"><datalist id="merge-tickets"></datalist></div>
                <button type="submit" class="btn btn-danger btn-sm"><?= icon('merge') ?> Zusammenführen</button>
            </form>
        </details>
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
<dialog id="status-dialog" class="dialog">
    <form method="post" action="/helpdesk/tickets/<?= $id ?>/status">
        <?= csrf_field() ?>
        <input type="hidden" name="version" value="<?= (int) $ticket['version'] ?>">
        <input type="hidden" name="status" value="">
        <div class="dialog-header"><h2>Status → <span data-status-label></span></h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button></div>
        <div class="dialog-body">
            <div class="form-group" data-show-for="resolved,closed">
                <label for="st-resolution">Lösung <span class="text-muted">(Pflicht bei Gelöst/Geschlossen)</span></label>
                <textarea id="st-resolution" name="resolution" rows="4" data-required-when-visible data-hd-field="ticket_resolution"><?= e($ticket['resolution'] ?? '') ?></textarea>
            </div>
            <div class="form-group" data-show-for="cancelled">
                <label for="st-reason">Grund</label>
                <input id="st-reason" name="reason" maxlength="255" data-required-when-visible>
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
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
