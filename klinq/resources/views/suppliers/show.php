<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/suppliers">Lieferanten</a></p>
        <h1 class="page-title"><?= e($row['name']) ?> <?= active_badge($row['is_active']) ?></h1>
    </div>
    <?php if ($can('suppliers.manage')): ?>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/suppliers/<?= (int) $row['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
        <?php $toggleUrl = '/suppliers/' . (int) $row['id'] . '/toggle-active'; $isActive = $row['is_active']; include __DIR__ . '/../partials/toggle_active_form.php'; ?>
    </div>
    <?php endif; ?>
</div>
<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Anschrift & Kontakt</h2></div>
        <dl class="detail-list">
            <dt>Anschrift</dt><dd><?= e($row['street'] ?? '') ?><br><?= e(trim(($row['postal_code'] ?? '') . ' ' . ($row['city'] ?? ''))) ?><?= $row['country'] ? '<br>' . e($row['country']) : '' ?></dd>
            <dt>Ansprechpartner</dt><dd><?= e($row['contact_person'] ?? '–') ?></dd>
            <dt>E-Mail</dt><dd><?= $row['email'] ? '<a href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a>' : '–' ?></dd>
            <dt>Telefon</dt><dd><?= $row['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $row['phone'])) . '">' . e($row['phone']) . '</a>' : '–' ?></dd>
            <dt>Webseite</dt><dd><?= $row['website'] ? '<a href="' . e($row['website']) . '" target="_blank" rel="noopener noreferrer">' . e($row['website']) . '</a>' : '–' ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Weitere Angaben</h2></div>
        <dl class="detail-list">
            <dt>Kundennummer</dt><dd class="mono"><?= e($row['customer_number'] ?? '–') ?></dd>
            <dt>Bemerkung</dt><dd><?= nl2br_e($row['note']) ?: '–' ?></dd>
            <dt>Bestellungen</dt><dd><a href="/orders?supplier_id=<?= (int) $row['id'] ?>"><?= (int) $row['order_count'] ?> Bestellungen</a></dd>
            <dt>Angelegt</dt><dd><?= fmt_datetime($row['created_at']) ?></dd>
            <dt>Geändert</dt><dd><?= fmt_datetime($row['updated_at']) ?></dd>
        </dl>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
