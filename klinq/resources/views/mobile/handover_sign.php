<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$errorText = '';
foreach (($errors ?? []) as $messages) {
    $errorText .= ' ' . (is_array($messages) ? implode(' ', $messages) : (string) $messages);
}
$errorText = trim($errorText);
?>
<form method="post" action="/m/handover/<?= (int) $protocol['id'] ?>/sign" class="m-form hp-sign-form" id="handover-sign-form" data-protocol-id="<?= (int) $protocol['id'] ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="signature_data" id="signature-data" value="">

    <div class="card hp-sign-head">
        <div>
            <span class="text-muted text-sm mono"><?= e($protocol['protocol_number']) ?> · Version <?= (int) $protocol['version'] ?></span>
            <h2 class="mb-0"><?= e($protocol['employee_name']) ?></h2>
        </div>
        <?= badge((int) $protocol['item_count'] . ' Arbeitsmittel', 'info') ?>
    </div>

    <div class="alert alert-danger<?= $errorText === '' ? ' hidden' : '' ?>" id="signature-error" role="alert"><?= icon('warning') ?> <span><?= e($errorText) ?></span></div>

    <div class="card hp-paper hp-paper-mobile">
        <?= $html ?>
    </div>

    <?php if ($mailEnabled ?? false): ?>
    <label class="checkbox-field"><input type="checkbox" name="notify_email" value="1"> Übergabeprotokoll per E-Mail an den Mitarbeiter senden</label>
    <?php endif; ?>

    <div class="m-actions">
        <button class="btn btn-primary btn-xl btn-block" type="submit" id="signature-submit"><?= icon('check') ?> Unterschrift bestätigen</button>
        <a class="btn btn-ghost btn-block" href="/m/handover">Abbrechen</a>
    </div>
</form>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/signature-pad.js']; $extraStyles = ['/handover/print.css']; include __DIR__ . '/../partials/mobile_layout.php'; ?>
