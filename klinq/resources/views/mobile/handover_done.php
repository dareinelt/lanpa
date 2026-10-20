<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="card m-done">
    <div class="m-done-icon"><?= icon('check') ?></div>
    <h2>Protokoll unterschrieben</h2>
    <p class="text-muted"><span class="mono font-semibold"><?= e($protocol['protocol_number']) ?></span> · Version <?= (int) $protocol['version'] ?><br><?= e($protocol['employee_name']) ?> · <?= fmt_datetime($protocol['signed_at']) ?></p>
    <?php if ($protocol['pdf_document_id']): ?>
        <p class="mb-0">Das PDF wurde archiviert und ist unter dem Protokoll abrufbar.</p>
    <?php else: ?>
        <p class="text-warning mb-0">Das PDF konnte gerade nicht erzeugt werden (PDF-Dienst nicht erreichbar). Unterschrift und Inhalt sind gespeichert; das PDF kann am Desktop nachträglich erzeugt werden.</p>
    <?php endif; ?>
    <?php if (!empty($protocol['notify_email'])): ?>
        <?php if (!empty($protocol['email_sent_at'])): ?>
            <p class="text-muted mb-0"><?= icon('mail') ?> Protokoll wurde an <?= e($protocol['email_sent_to']) ?> gesendet.</p>
        <?php else: ?>
            <p class="text-warning mb-0"><?= icon('mail') ?> Protokoll konnte nicht per E-Mail versendet werden (Dienst nicht erreichbar oder keine E-Mail-Adresse hinterlegt).</p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<div class="m-actions">
    <a class="btn btn-primary btn-xl btn-block" href="/m/handover"><?= icon('signature') ?> Weitere Protokolle</a>
    <div class="m-actions-row">
        <a class="btn btn-secondary" href="/m"><?= icon('qr') ?> Scannen</a>
        <?php if ($can('handover.view')): ?><a class="btn btn-secondary" href="/handover/<?= (int) $protocol['id'] ?>"><?= icon('document') ?> Protokoll</a><?php endif; ?>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/mobile_layout.php'; ?>
