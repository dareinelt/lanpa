<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<section class="m-scan" id="scanner" data-lookup-url="/m/lookup">
    <div class="m-scan-viewport" id="scan-viewport" hidden>
        <video id="scan-video" playsinline muted autoplay></video>
        <div class="m-scan-frame" aria-hidden="true"></div>
        <div class="m-scan-status" id="scan-status" aria-live="polite">Kamera wird gestartet …</div>
    </div>
    <div class="m-actions" id="scan-actions">
        <button type="button" class="btn btn-primary btn-xl btn-block" id="scan-start"><?= icon('camera') ?> QR-Code scannen</button>
        <p class="m-scan-hint" id="scan-hint">Etikett vor die Kamera halten – oder Inventarnummer eingeben.</p>
    </div>
    <form method="get" action="/m/lookup" class="m-scan-manual" id="scan-form">
        <label for="scan-code" class="visually-hidden">Inventar- oder Seriennummer</label>
        <input id="scan-code" type="text" name="code" value="<?= e($code ?? '') ?>" placeholder="z. B. PC26001" autocomplete="off" autocapitalize="characters" enterkeyhint="go" required>
        <button type="submit" class="btn btn-secondary" aria-label="Suchen"><?= icon('arrow-right') ?></button>
    </form>
</section>

<section id="offline-asset" class="m-offline-asset" hidden aria-live="polite"></section>
<section id="offline-queue" class="card card-compact m-queue-card" hidden aria-live="polite"></section>
<p class="text-xs text-muted text-center mb-0" id="offline-cache-state"></p>

<?php if ($openCount > 0): ?>
<a class="alert alert-warning" href="/m/open"><?= icon('warning') ?> <?= (int) $openCount ?> offene <?= $openCount === 1 ? 'Vorgang wartet' : 'Vorgänge warten' ?> auf Vervollständigung</a>
<?php endif; ?>

<?php if ($recent): ?>
<section class="card card-compact">
    <h2 class="text-sm text-muted mb-2">Zuletzt erfasst</h2>
    <ul class="m-list">
        <?php foreach ($recent as $r): ?>
            <li><a href="/m/asset/<?= e(rawurlencode($r['inventory_number'])) ?>">
                <?= icon($r['type'] === 'checkout' ? 'checkout' : 'return') ?>
                <div class="m-list-main">
                    <div class="m-list-title"><span class="mono"><?= e($r['inventory_number']) ?></span> · <?= $r['type'] === 'checkout' ? 'Entnahme' : 'Rückgabe' ?></div>
                    <div class="m-list-sub"><?= e($r['employee_name'] ?? $r['to_location_path'] ?? '') ?> · <?= fmt_datetime($r['movement_at']) ?></div>
                </div>
                <?php if ($r['status'] === 'open'): ?><?= badge('offen', 'warning') ?><?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/vendor/jsqr.js', '/js/scan.js', '/js/offline-ui.js']; include __DIR__ . '/../partials/mobile_layout.php'; ?>
