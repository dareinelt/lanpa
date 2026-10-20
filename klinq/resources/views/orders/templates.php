<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header"><div><p class="page-subtitle text-muted mb-0"><a href="/orders">Bestellungen</a></p><h1>Bestellvorlagen</h1></div><div class="page-actions"><a class="btn btn-secondary" href="/orders/replenishment">Bestellvorschläge</a><a class="btn btn-secondary" href="/orders/requests">Bedarfsmeldungen</a></div></div>
<div class="card card-flush">
<?php if (!$templates): ?><div class="table-empty">Noch keine Vorlagen. Speichern Sie eine bestehende Bestellung als Vorlage.</div>
<?php else: ?><div class="table-wrapper"><table class="table"><thead><tr><th>Name</th><th>Lieferant</th><th>Kostenstelle</th><th>Positionen</th><th></th></tr></thead><tbody>
<?php foreach ($templates as $template): ?><tr><td><strong><?= e($template['name']) ?></strong></td><td><?= e($template['supplier_name'] ?? '–') ?></td><td><?= e($template['cost_center_number'] ?? '–') ?></td><td><?= (int) $template['item_count'] ?></td><td><form method="post" action="/orders/templates/<?= (int) $template['id'] ?>/use"><?= csrf_field() ?><button class="btn btn-primary btn-sm"><?= icon('plus') ?> Entwurf erstellen</button></form></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
