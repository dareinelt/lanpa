<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Lieferanten</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Einträge</p></div>
    <?php if ($can('suppliers.manage')): ?>
    <div class="page-actions"><a class="btn btn-primary" href="/suppliers/new"><?= icon('plus') ?> Lieferant anlegen</a></div>
    <?php endif; ?>
</div>
<?php $placeholder = 'Firma, Ort, Ansprechpartner, Kundennummer …'; include __DIR__ . '/../partials/filter_bar.php'; ?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Lieferanten gefunden.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Firma</th><th>Ort</th><th>Ansprechpartner</th><th>Kontakt</th><th>Kundennummer</th><th class="num">Bestellungen</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="is-clickable <?= (int) $r['is_active'] ? '' : 'is-muted' ?>" data-href="/suppliers/<?= (int) $r['id'] ?>">
                <td><strong><?= e($r['name']) ?></strong></td>
                <td><?= e(trim(($r['postal_code'] ?? '') . ' ' . ($r['city'] ?? '')) ?: '–') ?></td>
                <td><?= e($r['contact_person'] ?? '–') ?></td>
                <td><?php if ($r['email']): ?><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><br><?php endif; ?><span class="text-muted text-sm"><?= e($r['phone'] ?? '') ?></span></td>
                <td class="mono"><?= e($r['customer_number'] ?? '–') ?></td>
                <td class="num"><?= (int) $r['order_count'] ?></td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions"><?php if ($can('suppliers.manage')): ?><a class="btn btn-ghost btn-sm" href="/suppliers/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
