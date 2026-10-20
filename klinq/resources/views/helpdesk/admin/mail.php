<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk-admin';
$areaLabel = 'Help Desk';
$yesNo = static fn (mixed $v): string => $v ? 'Ja' : 'Nein';
$actionLabel = ['created' => 'Ticket angelegt', 'comment' => 'Kommentar', 'ignored' => 'Ignoriert', 'failed' => 'Fehlgeschlagen'];
$actionColor = ['created' => 'success', 'comment' => 'info', 'ignored' => 'neutral', 'failed' => 'danger'];
$matchedLabel = ['reference' => 'Message-ID', 'subject' => 'Betreff', 'none' => '–'];
$configLabels = [
    'enabled' => 'Aktiv', 'driver' => 'Treiber', 'host' => 'Server / Pfad', 'encryption' => 'Verschlüsselung', 'mailbox' => 'Ordner',
    'processed_mailbox' => 'Verarbeitet nach', 'interval_minutes' => 'Intervall (min)', 'batch_size' => 'Nachrichten je Lauf',
    'system_user' => 'Systembenutzer', 'allow_unknown_senders' => 'Unbekannte Absender', 'default_type' => 'Standardtyp', 'mail_domain' => 'Message-ID-Domain',
];
?>
<div class="page-header">
    <div><h1 class="page-title">E-Mail-Eingang</h1><p class="page-subtitle text-muted">Abgeholte Nachrichten und ihre Zuordnung zu Tickets (Threading über Message-ID und Ticketnummer).</p></div>
    <div class="page-actions"><a class="btn btn-secondary" href="/helpdesk/admin"><?= icon('arrow-left') ?> Administration</a></div>
</div>
<?php if (!$config['enabled']): ?>
    <div class="alert alert-info mb-4"><?= icon('info', 'icon icon-xs') ?> Der E-Mail-Eingang ist deaktiviert. Er wird unter <a href="/admin/helpdesk-mail">Administration → E-Mail-Postfach</a> eingeschaltet. Bereits protokollierte Nachrichten werden weiterhin angezeigt.</div>
<?php endif; ?>
<div class="card mb-4">
    <div class="card-header"><h2>Konfiguration</h2><span class="text-muted text-sm">letzter Abruf: <?= $lastRun !== null ? fmt_datetime($lastRun) : '–' ?><?php if ($canConfigure): ?> · <a href="/admin/helpdesk-mail">bearbeiten</a><?php endif; ?></span></div>
    <div class="grid grid-4">
        <?php foreach ($config as $key => $value): ?>
            <div><span class="kpi-value text-sm"><?= is_bool($value) ? e($yesNo($value)) : e((string) ($value !== '' ? $value : '–')) ?></span><span class="kpi-label"><?= e($configLabels[$key] ?? $key) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>
<div class="grid grid-4 mb-4">
    <?php foreach ($counts as $action => $count): ?>
        <div class="card kpi-card"><span class="kpi-value"><?= (int) $count ?></span><span class="kpi-label"><?= e($actionLabel[$action] ?? $action) ?></span></div>
    <?php endforeach; ?>
</div>
<div class="card card-flush">
    <div class="card-header"><h2>Protokoll</h2><span class="text-muted text-sm">letzte <?= count($rows) ?> Nachrichten</span></div>
    <?php if ($rows === []): ?>
        <div class="table-empty">Noch keine Nachrichten verarbeitet.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Verarbeitet</th><th>Absender</th><th>Betreff</th><th>Aktion</th><th>Zuordnung</th><th>Ticket</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap text-sm"><?= fmt_datetime($r['created_at']) ?><?php if (!empty($r['received_at'])): ?><div class="text-xs text-muted">gesendet <?= fmt_datetime($r['received_at']) ?></div><?php endif; ?></td>
                    <td class="text-sm"><?= e($r['from_address'] !== '' ? $r['from_address'] : '–') ?></td>
                    <td class="text-sm"><?= e($r['subject'] !== '' ? $r['subject'] : '(ohne Betreff)') ?><div class="text-xs text-muted mono"><?= e(mb_strimwidth((string) $r['message_id'], 0, 60, '…')) ?></div></td>
                    <td><?= badge($actionLabel[$r['action']] ?? $r['action'], $actionColor[$r['action']] ?? 'neutral') ?></td>
                    <td class="text-sm"><?= e($matchedLabel[$r['matched_by']] ?? $r['matched_by']) ?></td>
                    <td class="mono text-sm"><?php if (!empty($r['ticket_id']) && !empty($r['ticket_number'])): ?><a href="/helpdesk/tickets/<?= (int) $r['ticket_id'] ?>"><?= e($r['ticket_number']) ?></a><?php else: ?>–<?php endif; ?></td>
                    <td class="text-xs text-muted"><?= e($r['detail'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
