<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isBelow = static fn (array $r): bool => $r['minimum_stock'] !== null && (int) $r['stock_quantity'] <= (int) $r['minimum_stock'];
?>
<div class="page-header">
    <div>
        <h1>Verbrauchsmaterial</h1>
        <p class="page-subtitle text-muted mb-0"><?= count($rows) ?> Artikel<?= $belowCount > 0 ? ' · ' . $belowCount . ' am oder unter Mindestbestand' : '' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($can('orders.view')): ?><a class="btn btn-secondary" href="/orders/replenishment"><?= icon('cart') ?> Bestellvorschläge<?= $belowCount > 0 ? ' (' . $belowCount . ')' : '' ?></a><?php endif; ?>
        <?php if ($can('articles.manage')): ?><a class="btn btn-primary" href="/articles/new?is_consumable=1"><?= icon('plus') ?> Verbrauchsmaterial anlegen</a><?php endif; ?>
    </div>
</div>
<p class="text-muted text-sm">Verbrauchsmaterial (z. B. Toner, Tinte, Batterien) wird nur als <strong>Menge je Artikel</strong> geführt – es entstehen keine einzelnen Assets. Der Wareneingang einer Bestellung erhöht den Bestand automatisch; Ausgaben und Inventurergebnisse werden hier als neuer Bestand eingetragen. Fällt der Bestand auf oder unter den Mindestbestand, erscheint der Artikel in den Bestellvorschlägen. Einzelne Stücke ohne Inventarnummer, die ausgegeben und zurückgenommen werden (z. B. Kabel), sind dagegen <strong>Lagerartikel</strong>: ein Assettyp ohne Inventarnummer unter Auswertung &amp; System → Kataloge.</p>
<?php
$placeholder = 'Artikel, Artikelnummer oder Hersteller …';
$extraFilters = '<div class="form-group"><label for="filter-below">Bestand</label><select id="filter-below" name="below" data-autosubmit>'
    . '<option value="">Alle</option><option value="1"' . selected($filters['below'], '1') . '>Am/unter Mindestbestand</option></select></div>';
include __DIR__ . '/../partials/filter_bar.php';
?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Kein Verbrauchsmaterial gefunden.<?php if ($can('articles.manage')): ?> <a href="/articles/new?is_consumable=1">Verbrauchsmaterial anlegen</a> oder einen vorhandenen Artikel unter „Artikel“ als Verbrauchsmaterial kennzeichnen.<?php endif; ?></div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Artikel</th><th>Artikelnummer</th><th>Assettyp</th><th class="num">Bestand</th><th class="num">Mindestbestand</th><th>Status</th><?php if ($can('articles.manage')): ?><th>Bestand setzen</th><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= (int) $r['is_active'] ? '' : 'is-muted' ?>">
                <td><strong><?= e($r['manufacturer_name'] . ' ' . $r['name']) ?></strong></td>
                <td class="mono"><?= e($r['article_number'] ?? '–') ?></td>
                <td><?= e($r['asset_type_name']) ?><?= $r['category_name'] ? ' <span class="text-muted">· ' . e($r['category_name']) . '</span>' : '' ?></td>
                <td class="num"><strong><?= (int) $r['stock_quantity'] ?></strong></td>
                <td class="num"><?= $r['minimum_stock'] !== null ? (int) $r['minimum_stock'] : '–' ?></td>
                <td><?= $r['minimum_stock'] === null ? '<span class="text-muted">kein Mindestbestand</span>' : ($isBelow($r) ? badge('nachbestellen', 'warning') : badge('ausreichend', 'success')) ?><?= (int) $r['is_active'] ? '' : ' ' . badge('Inaktiv', 'neutral') ?></td>
                <?php if ($can('articles.manage')): ?>
                <td>
                    <form method="post" action="/consumables/<?= (int) $r['id'] ?>/stock" class="inline-form flex gap-1">
                        <?= csrf_field() ?>
                        <label class="visually-hidden" for="stock-<?= (int) $r['id'] ?>">Neuer Bestand für <?= e($r['name']) ?></label>
                        <input id="stock-<?= (int) $r['id'] ?>" type="number" name="stock_quantity" min="0" step="1" value="<?= (int) $r['stock_quantity'] ?>" class="input-narrow" required>
                        <button type="submit" class="btn btn-secondary btn-sm" title="Bestand speichern"><?= icon('check') ?></button>
                    </form>
                </td>
                <td class="table-actions"><a class="btn btn-ghost btn-sm" href="/articles/<?= (int) $r['id'] ?>/edit?return=<?= e(rawurlencode('/consumables')) ?>" title="Artikel bearbeiten"><?= icon('pen') ?></a></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
