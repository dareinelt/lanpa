<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$statusBadge = static fn (string $s): string => match ($s) { 'success' => badge('Erfolgreich', 'success'), 'failed' => badge('Fehlgeschlagen', 'danger'), default => badge('Läuft', 'info') };
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/employees">Mitarbeiter</a></p>
        <h1 class="page-title">AD-Synchronisation</h1>
    </div>
    <div class="page-actions">
        <?php if ($enabled && !$running): ?>
        <form method="post" action="/admin/ad-sync/run" class="inline-form"><?= csrf_field() ?><input type="hidden" name="dry_run" value="1"><button class="btn btn-secondary" type="submit"><?= icon('search') ?> Testlauf</button></form>
        <form method="post" action="/admin/ad-sync/run" class="inline-form" data-confirm="Synchronisation jetzt starten?"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= icon('refresh') ?> Jetzt synchronisieren</button></form>
        <?php endif; ?>
    </div>
</div>

<?php if (!$enabled): ?>
<div class="alert alert-warning"><?= icon('warning') ?> Die AD-Synchronisation ist deaktiviert. Setzen Sie <code>AD_ENABLED=true</code> und die Verbindungsdaten in der <code>.env</code>-Datei.</div>
<?php elseif ($running): ?>
<div class="alert alert-info"><?= icon('clock') ?> Eine Synchronisation läuft seit <?= fmt_datetime($running['started_at']) ?> (gestartet von <?= e($running['triggered_by']) ?>).</div>
<?php endif; ?>

<div class="grid grid-3">
    <div class="card stat-card <?= $lastSuccess ? 'is-success' : '' ?>">
        <span class="stat-value"><?= $lastSuccess ? fmt_datetime($lastSuccess['finished_at']) : '–' ?></span>
        <span class="stat-label">Letzter erfolgreicher Lauf</span>
    </div>
    <div class="card stat-card"><span class="stat-value"><?= $lastSuccess ? (int) $lastSuccess['total_count'] : '–' ?></span><span class="stat-label">Konten im AD (letzter Lauf)</span></div>
    <div class="card stat-card <?= $lastSuccess && (int) $lastSuccess['error_count'] > 0 ? 'is-warning' : '' ?>"><span class="stat-value"><?= $lastSuccess ? (int) $lastSuccess['error_count'] : '–' ?></span><span class="stat-label">Fehler (letzter Lauf)</span></div>
</div>

<div class="grid grid-2 mt-4">
    <div class="card card-flush">
        <div class="card-header"><h2>Letzte Läufe</h2></div>
        <?php if (!$runs): ?>
            <div class="table-empty">Noch keine Synchronisation ausgeführt.</div>
        <?php else: ?>
        <div class="table-wrapper"><table class="table table-compact">
            <thead><tr><th>Start</th><th>Status</th><th>Ausgelöst von</th><th class="num">Neu</th><th class="num">Akt.</th><th class="num">Deakt.</th><th class="num">Fehler</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
                <tr class="is-clickable" data-href="/admin/ad-sync/runs/<?= (int) $r['id'] ?>">
                    <td class="nowrap"><?= fmt_datetime($r['started_at']) ?></td>
                    <td><?= $statusBadge($r['status']) ?></td>
                    <td><?= e($r['triggered_by']) ?></td>
                    <td class="num"><?= (int) $r['created_count'] ?></td>
                    <td class="num"><?= (int) $r['updated_count'] ?></td>
                    <td class="num"><?= (int) $r['deactivated_count'] ?></td>
                    <td class="num"><?= (int) $r['error_count'] > 0 ? '<strong class="text-danger">' . (int) $r['error_count'] . '</strong>' : '0' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><h2>Konfiguration</h2></div>
        <dl class="detail-list">
            <dt>Status</dt><dd><?= $enabled ? badge('Aktiviert', 'success') : badge('Deaktiviert', 'neutral') ?></dd>
            <dt>Treiber</dt><dd><?= $driver === 'fake' ? badge('Fake (Entwicklung)', 'warning') : 'LDAP / LDAPS' ?></dd>
            <dt>Server</dt><dd class="mono"><?= e($host ?: '–') ?></dd>
            <dt>Basis-DN</dt><dd class="mono text-sm"><?= e($baseDn ?: '–') ?></dd>
            <dt>Zeitplan</dt><dd><?= $intervalMinutes > 0 ? 'alle ' . $intervalMinutes . ' Minuten (Container-Scheduler)' : 'kein automatischer Lauf im Container' ?></dd>
        </dl>
        <h3 class="text-sm text-muted mt-4">Attribut-Zuordnung</h3>
        <dl class="detail-list detail-list-compact">
            <?php foreach ($attributes as $field => $attr): ?><dt><?= e($field) ?></dt><dd class="mono text-sm"><?= e($attr) ?></dd><?php endforeach; ?>
        </dl>
        <p class="text-muted text-sm mt-4 mb-0">Mitarbeiter werden über die stabile <code>objectGUID</code> identifiziert. Aus dem AD entfernte oder deaktivierte Konten werden nur als inaktiv markiert – historische Zuordnungen bleiben erhalten.</p>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
