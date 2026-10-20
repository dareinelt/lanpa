<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$statusBadge = static fn (string $s): string => badge($statusLabels[$s] ?? $s, match ($s) { 'signed' => 'success', 'draft' => 'info', 'superseded' => 'neutral', default => 'danger' });
$isDraft = $protocol['status'] === 'draft';
$outdated = $isDraft && $protocol['asset_fingerprint'] !== $status['fingerprint'];
$signUrlAbs = $signUrl !== null ? (isset($_SERVER['HTTP_HOST']) ? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) : '') . $signUrl : null;
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/handover">Übergabeprotokolle</a> › <a href="/handover/employee/<?= (int) $protocol['employee_id'] ?>"><?= e($protocol['employee_name']) ?></a></p>
        <h1 class="page-title"><span class="mono"><?= e($protocol['protocol_number']) ?></span> <?= $statusBadge($protocol['status']) ?><?= $isCurrent ? ' ' . badge('Gültige Version', 'success') : '' ?></h1>
        <p class="text-muted mb-0">Version <?= (int) $protocol['version'] ?> · <?= (int) $protocol['item_count'] ?> Arbeitsmittel · angelegt <?= fmt_datetime($protocol['created_at']) ?> von <?= e($protocol['created_by_name'] ?? '–') ?><?= $protocol['signed_at'] ? ' · unterschrieben ' . fmt_datetime($protocol['signed_at']) : '' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($isDraft): ?>
            <?php if ($can('handover.sign')): ?><a class="btn btn-primary" href="<?= e($signUrl) ?>"><?= icon('signature') ?> Auf Mobilgerät unterschreiben</a><?php endif; ?>
            <?php if ($can('handover.manage')): ?>
                <form method="post" action="/handover/<?= (int) $protocol['id'] ?>/refresh" class="inline-form"><?= csrf_field() ?><button class="btn btn-secondary" type="submit"><?= icon('refresh') ?> Bestand aktualisieren</button></form>
                <button class="btn btn-ghost" type="button" data-dialog-open="cancel-dialog"><?= icon('x') ?> Stornieren</button>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($protocol['pdf_document_id']): ?>
                <a class="btn btn-primary" href="/handover/<?= (int) $protocol['id'] ?>/pdf" target="_blank" rel="noopener"><?= icon('document') ?> PDF öffnen</a>
                <a class="btn btn-secondary" href="/handover/<?= (int) $protocol['id'] ?>/pdf?download=1"><?= icon('download') ?> Herunterladen</a>
            <?php elseif ($protocol['status'] !== 'cancelled' && $can('handover.manage')): ?>
                <form method="post" action="/handover/<?= (int) $protocol['id'] ?>/pdf" class="inline-form"><?= csrf_field() ?><button class="btn btn-primary" type="submit"<?= $pdfEnabled ? '' : ' disabled title="PDF-Dienst nicht konfiguriert"' ?>><?= icon('document') ?> PDF erzeugen</button></form>
            <?php endif; ?>
            <a class="btn btn-secondary" href="/handover/<?= (int) $protocol['id'] ?>/pdf" target="_blank" rel="noopener" title="Druckansicht (HTML)"><?= icon('print') ?> Drucken</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($outdated): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning') ?> <span>Der Bestand des Mitarbeiters hat sich seit Anlage dieses Entwurfs geändert. Beim Öffnen auf dem Mobilgerät wird der Entwurf automatisch aktualisiert – oder jetzt „Bestand aktualisieren“.</span></div>
<?php elseif ($protocol['status'] === 'superseded'): ?>
    <div class="alert alert-info mb-4"><?= icon('info') ?> <span>Diese Version wurde durch eine neuere unterschriebene Version abgelöst. Sie bleibt zur Nachvollziehbarkeit archiviert.</span></div>
<?php elseif ($protocol['status'] === 'cancelled'): ?>
    <div class="alert alert-danger mb-4"><?= icon('x') ?> <span>Storniert am <?= fmt_datetime($protocol['cancelled_at']) ?>: <?= e($protocol['cancel_reason'] ?? '') ?></span></div>
<?php elseif ($protocol['status'] === 'signed' && !$protocol['pdf_document_id']): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning') ?> <span>Für dieses Protokoll liegt noch kein PDF vor (PDF-Dienst nicht erreichbar). Die Unterschrift und der Inhalt sind gespeichert; das PDF kann jederzeit erzeugt werden.</span></div>
<?php endif; ?>

<div class="grid handover-show-grid">
    <div class="card hp-preview-card">
        <div class="hp-paper"><?= $html ?></div>
    </div>
    <div class="stack">
        <?php if ($isDraft && $signUrlAbs): ?>
        <div class="card">
            <div class="card-header"><h2>Unterschrift</h2></div>
            <p class="text-sm">Den Entwurf auf dem iPhone/iPad öffnen und vom Mitarbeiter unterschreiben lassen. Er muss dort angemeldet sein und die Berechtigung „Übergabeprotokoll unterschreiben“ besitzen.</p>
            <div class="hp-sign-link"><a class="mono text-sm" href="<?= e($signUrl) ?>"><?= e($signUrlAbs) ?></a></div>
            <p class="text-muted text-sm mb-0">Tipp: Auf dem Mobilgerät unter „Protokolle“ (Tab-Leiste) erscheinen alle offenen Entwürfe.</p>
        </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><h2>Details</h2></div>
            <dl class="detail-list detail-list-compact">
                <dt>Mitarbeiter</dt><dd><a href="/handover/employee/<?= (int) $protocol['employee_id'] ?>"><?= e($protocol['employee_name']) ?></a></dd>
                <dt>Version</dt><dd><?= (int) $protocol['version'] ?><?php if ($status['current'] !== null && !$isCurrent && $protocol['status'] !== 'draft'): ?> <span class="text-muted">(gültig: v<?= (int) $status['current']['version'] ?>)</span><?php endif; ?></dd>
                <dt>Status</dt><dd><?= $statusBadge($protocol['status']) ?></dd>
                <dt>Aussteller</dt><dd><?= e($protocol['issuer_name'] ?? '–') ?></dd>
                <?php if ($protocol['signed_at']): ?>
                <dt>Unterschrieben</dt><dd><?= fmt_datetime($protocol['signed_at']) ?></dd>
                <dt>Gerät</dt><dd class="text-sm"><?= e(mb_substr((string) ($protocol['signed_device'] ?? ''), 0, 120) ?: '–') ?></dd>
                <dt>IP</dt><dd class="mono text-sm"><?= e($protocol['signed_ip'] ?? '–') ?></dd>
                <?php endif; ?>
                <dt>PDF</dt><dd><?= $protocol['pdf_document_id'] ? '<a href="/handover/' . (int) $protocol['id'] . '/pdf">' . e($protocol['pdf_original_name'] ?? 'PDF') . '</a> <span class="text-muted text-sm">' . fmt_bytes((int) ($protocol['pdf_size'] ?? 0)) . '</span>' : '<span class="text-muted">–</span>' ?></dd>
                <?php if ($protocol['note']): ?><dt>Notiz</dt><dd><?= nl2br_e($protocol['note']) ?></dd><?php endif; ?>
            </dl>
        </div>
    </div>
</div>

<?php if ($isDraft && $can('handover.manage')): ?>
<dialog id="cancel-dialog" class="dialog">
    <form method="post" action="/handover/<?= (int) $protocol['id'] ?>/cancel" class="dialog-form">
        <?= csrf_field() ?>
        <h2>Entwurf stornieren</h2>
        <div class="form-group">
            <label for="cancel-reason">Grund <span class="required">*</span></label>
            <input id="cancel-reason" name="cancel_reason" type="text" maxlength="500" required>
        </div>
        <div class="dialog-actions">
            <button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-danger">Stornieren</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); $extraStyles = ['/handover/print.css']; include __DIR__ . '/../partials/app_layout.php'; ?>
