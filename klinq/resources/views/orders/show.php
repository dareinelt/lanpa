<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$status = $row['status'];
$editable = in_array($status, ['draft', 'ordered'], true) && $can('orders.manage');
$receivable = in_array($status, ['ordered', 'partially_delivered'], true) && $can('orders.receive') && (int) $row['quantity_total'] > (int) $row['quantity_received'];
$overdue = in_array($status, ['ordered', 'partially_delivered'], true) && $row['expected_delivery_date'] && $row['expected_delivery_date'] < date('Y-m-d');
$old = $_SESSION['_old_input'] ?? null;
$itemVal = static fn (string $key, mixed $default = ''): string => $old !== null ? (string) ($old[$key] ?? '') : (string) ($editing[$key] ?? $default);
$showItemForm = $editable && ($editing !== null || $old !== null || $items === []);
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/orders">Bestellungen</a></p>
        <h1 class="page-title"><span class="mono"><?= e($row['order_number']) ?></span> <?= badge($statuses[$status] ?? $status, $statusColors[$status] ?? 'neutral') ?><?= $overdue ? ' ' . badge('Überfällig', 'danger') : '' ?></h1>
        <p class="text-muted mb-0"><a href="/suppliers/<?= (int) $row['supplier_id'] ?>"><?= e($row['supplier_name']) ?></a><?= $row['order_date'] ? ' · bestellt am ' . fmt_date($row['order_date']) : '' ?><?= $row['ordered_by_display'] ? ' von ' . e($row['ordered_by_display']) : '' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($receivable): ?><a class="btn btn-primary" href="/orders/<?= (int) $row['id'] ?>/receive"><?= icon('download') ?> Wareneingang buchen</a><?php endif; ?>
        <?php if ($editable): ?><a class="btn btn-secondary" href="/orders/<?= (int) $row['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a><?php endif; ?>
        <?php if ($can('orders.manage')): ?>
        <button type="button" class="btn btn-ghost" data-dialog-open="template-dialog"><?= icon('copy') ?> Als Vorlage speichern</button>
            <?php if ($status === 'draft'): ?>
                <form method="post" action="/orders/<?= (int) $row['id'] ?>/status" data-confirm="Bestellung als bestellt markieren? Positionen sind danach weiterhin änderbar, bis die erste Lieferung gebucht wird."><?= csrf_field() ?><input type="hidden" name="action" value="order"><button type="submit" class="btn btn-secondary"<?= (int) $row['item_count'] === 0 ? ' disabled title="Zuerst Positionen hinzufügen"' : '' ?>><?= icon('cart') ?> Als bestellt markieren</button></form>
            <?php elseif ($status === 'delivered'): ?>
                <form method="post" action="/orders/<?= (int) $row['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="action" value="close"><button type="submit" class="btn btn-secondary"><?= icon('check') ?> Abschließen</button></form>
            <?php elseif ($status === 'closed'): ?>
                <form method="post" action="/orders/<?= (int) $row['id'] ?>/status"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button type="submit" class="btn btn-ghost"><?= icon('refresh') ?> Wieder öffnen</button></form>
            <?php endif; ?>
            <?php if (in_array($status, ['draft', 'ordered'], true) && (int) $row['quantity_received'] === 0): ?>
                <button type="button" class="btn btn-danger btn-outline" data-dialog-open="cancel-dialog"><?= icon('x') ?> Stornieren</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'draft' && (int) $row['item_count'] === 0): ?>
<div class="alert alert-info"><?= icon('info') ?> Die Bestellung ist ein Entwurf. Bitte Positionen hinzufügen und sie anschließend als <strong>bestellt</strong> markieren – erst dann kann Wareneingang gebucht werden.</div>
<?php elseif ($status === 'cancelled'): ?>
<div class="alert alert-warning"><?= icon('warning') ?> Diese Bestellung wurde storniert.</div>
<?php endif; ?>

<div class="grid grid-3 mb-4">
    <div class="card stat-card"><span class="stat-value"><?= (int) $row['quantity_received'] ?> / <?= (int) $row['quantity_total'] ?></span><span class="stat-label">Stück geliefert</span></div>
    <div class="card stat-card<?= $overdue ? ' is-danger' : '' ?>"><span class="stat-value"><?= fmt_date($row['expected_delivery_date'], '–') ?></span><span class="stat-label">Lieferung erwartet</span></div>
    <div class="card stat-card"><span class="stat-value"><?= fmt_money($row['total_net']) ?></span><span class="stat-label">Netto-Bestellwert</span></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Bestellung</h2></div>
        <dl class="detail-list">
            <dt>Lieferant</dt><dd><a href="/suppliers/<?= (int) $row['supplier_id'] ?>"><?= e($row['supplier_name']) ?></a><?= $row['supplier_customer_number'] ? ' <span class="text-muted text-sm">Kd.-Nr. ' . e($row['supplier_customer_number']) . '</span>' : '' ?></dd>
            <dt>Bestelldatum</dt><dd><?= fmt_date($row['order_date']) ?></dd>
            <dt>Besteller</dt><dd><?= e($row['ordered_by_display'] ?? '–') ?></dd>
            <dt>Kostenstelle</dt><dd><?= $row['cost_center_number'] ? '<span class="mono">' . e($row['cost_center_number']) . '</span> ' . e($row['cost_center_name']) : '–' ?></dd>
            <dt>Bemerkung</dt><dd><?= nl2br_e($row['note']) ?: '–' ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Verlauf</h2></div>
        <dl class="detail-list">
            <dt>Angelegt</dt><dd><?= fmt_datetime($row['created_at']) ?><?= $row['created_by_name'] ? ' · ' . e($row['created_by_name']) : '' ?></dd>
            <dt>Geändert</dt><dd><?= fmt_datetime($row['updated_at']) ?></dd>
            <dt>Wareneingänge</dt><dd><?= (int) $row['receipt_count'] ?></dd>
            <dt>Assets</dt><dd><?= count($assets) ?><?= $assets ? ' · <a href="/assets?purchase_order_id=' . (int) $row['id'] . '">in der Assetliste</a>' : '' ?></dd>
            <dt>Dokumente</dt><dd><?= count($documents) ?></dd>
        </dl>
    </div>
</div>

<div class="card mt-4" id="items">
    <div class="card-header"><h2>Positionen</h2><span class="text-muted text-sm"><?= count($items) ?></span></div>
    <?php if ($items): ?>
    <div class="table-wrapper">
    <table class="table table-compact">
        <thead><tr><th>Pos.</th><th>Bezeichnung</th><th>Assettyp</th><th class="text-right">Menge</th><th class="text-right">Geliefert</th><th class="text-right">Einzelpreis</th><th class="text-right">Gesamt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): $open = (int) $it['quantity_open']; ?>
            <tr<?= $editing !== null && (int) $editing['id'] === (int) $it['id'] ? ' class="is-editing"' : '' ?>>
                <td class="text-muted"><?= (int) $it['position'] ?></td>
                <td>
                    <strong><?= e($it['description']) ?></strong>
                    <?php if ($it['article_id']): ?><br><span class="text-sm text-muted"><a href="/articles/<?= (int) $it['article_id'] ?>/edit"><?= e($it['manufacturer_name']) ?> <?= e($it['article_name']) ?></a><?= $it['article_number'] ? ' · ' . e($it['article_number']) : '' ?><?= (int) ($it['is_consumable'] ?? 0) ? ' · Verbrauchsmaterial' : '' ?></span><?php endif; ?>
                    <?php if ($it['note']): ?><br><span class="text-sm text-muted"><?= e($it['note']) ?></span><?php endif; ?>
                </td>
                <td><?= $it['asset_type_name'] ? icon($it['asset_type_icon'] ?: 'box', 'icon icon-sm icon-muted') . ' ' . e($it['asset_type_name']) : '<span class="text-muted">–</span>' ?><?= (int) $it['creates_assets'] === 0 ? '<br><span class="text-xs text-muted">ohne Asset</span>' : '' ?></td>
                <td class="text-right"><?= (int) $it['quantity'] ?></td>
                <td class="text-right"><?= (int) $it['quantity_received'] ?><?= $open > 0 && in_array($status, ['ordered', 'partially_delivered'], true) ? ' <span class="text-xs text-warning">(' . $open . ' offen)</span>' : '' ?></td>
                <td class="text-right mono"><?= fmt_money($it['unit_price']) ?></td>
                <td class="text-right mono"><?= $it['unit_price'] !== null ? fmt_money((float) $it['unit_price'] * (int) $it['quantity']) : '–' ?></td>
                <td class="table-actions">
                    <?php if ($editable): ?>
                    <a class="btn btn-ghost btn-sm" href="/orders/<?= (int) $row['id'] ?>?edit_item=<?= (int) $it['id'] ?>#item-form" title="Bearbeiten"><?= icon('pen') ?></a>
                    <?php if ((int) $it['quantity_received'] === 0): ?>
                    <form method="post" action="/orders/<?= (int) $row['id'] ?>/items/<?= (int) $it['id'] ?>/delete" data-confirm="Position „<?= e($it['description']) ?>“ löschen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" title="Löschen"><?= icon('trash') ?></button></form>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="text-muted">Summe</td><td class="text-right font-semibold"><?= (int) $row['quantity_total'] ?></td><td class="text-right font-semibold"><?= (int) $row['quantity_received'] ?></td><td></td><td class="text-right mono font-semibold"><?= fmt_money($row['total_net']) ?></td><td></td></tr></tfoot>
    </table>
    </div>
    <?php else: ?>
    <p class="text-muted<?= $showItemForm ? '' : ' mb-0' ?>">Noch keine Positionen.</p>
    <?php endif; ?>

    <?php if ($editable): ?>
    <details class="item-form" id="item-form"<?= $showItemForm ? ' open' : '' ?>>
        <summary class="btn btn-secondary btn-sm"><?= icon($editing ? 'pen' : 'plus') ?> <?= $editing ? 'Position ' . (int) $editing['position'] . ' bearbeiten' : 'Position hinzufügen' ?></summary>
        <form method="post" action="/orders/<?= (int) $row['id'] ?>/items<?= $editing ? '/' . (int) $editing['id'] : '' ?>" class="mt-3" novalidate>
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group<?= has_error('article_id') ? ' has-error' : '' ?>">
                    <label for="i-article">Artikel</label>
                    <select id="i-article" name="article_id" data-article-select>
                        <option value="">– frei (nur für Positionen ohne Asset) –</option>
                        <?php foreach ($articles as $a): ?><option value="<?= (int) $a['id'] ?>" data-type="<?= (int) $a['asset_type_id'] ?>" data-label="<?= e($a['manufacturer_name'] . ' ' . $a['name']) ?>"<?= selected($itemVal('article_id'), $a['id']) ?>><?= e($a['manufacturer_name']) ?> <?= e($a['name']) ?><?= $a['article_number'] ? ' · ' . e($a['article_number']) : '' ?></option><?php endforeach; ?>
                    </select>
                    <p class="form-hint">Positionen, die Assets erzeugen, benötigen einen Stammartikel; Assettyp, Hersteller und Bezeichnung werden übernommen.</p>
                    <?= field_error('article_id') ?>
                </div>
                <div class="form-group<?= has_error('description') ? ' has-error' : '' ?>">
                    <label for="i-desc">Bezeichnung <span class="required">*</span></label>
                    <input id="i-desc" name="description" value="<?= e($itemVal('description')) ?>" maxlength="255" data-item-description placeholder="z. B. Notebook 14&quot; inkl. Dockingstation">
                    <?= field_error('description') ?>
                </div>
            </div>
            <div class="form-row form-row-3">
                <div class="form-group<?= has_error('asset_type_id') ? ' has-error' : '' ?>">
                    <label for="i-type">Assettyp</label>
                    <select id="i-type" name="asset_type_id" data-item-type>
                        <option value="">– keiner –</option>
                        <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($itemVal('asset_type_id', $editing['effective_asset_type_id'] ?? ''), $t['id']) ?>><?= e($t['name']) ?> (<?= e($t['inventory_prefix']) ?>)</option><?php endforeach; ?>
                    </select>
                    <?= field_error('asset_type_id') ?>
                </div>
                <div class="form-group<?= has_error('quantity') ? ' has-error' : '' ?>">
                    <label for="i-qty">Menge <span class="required">*</span></label>
                    <input id="i-qty" type="number" name="quantity" min="1" max="10000" value="<?= e($itemVal('quantity', '1')) ?>" required>
                    <?= field_error('quantity') ?>
                </div>
                <div class="form-group<?= has_error('unit_price') ? ' has-error' : '' ?>">
                    <label for="i-price">Einzelpreis netto (€)</label>
                    <input id="i-price" name="unit_price" inputmode="decimal" value="<?= e($itemVal('unit_price')) ?>" placeholder="0,00" class="mono">
                    <?= field_error('unit_price') ?>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group<?= has_error('note') ? ' has-error' : '' ?>">
                    <label for="i-note">Bemerkung</label>
                    <input id="i-note" name="note" value="<?= e($itemVal('note')) ?>" maxlength="255">
                </div>
                <div class="form-group">
                    <label class="checkbox-field mt-4"><input type="checkbox" name="creates_assets" value="1"<?= $old !== null ? checked($old['creates_assets'] ?? false) : checked($editing['creates_assets'] ?? true) ?>> <span>Erzeugt Assets beim Wareneingang (je Stück eine Inventarnummer)</span></label>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $editing ? 'Position speichern' : 'Position hinzufügen' ?></button>
                <?php if ($editing): ?><a class="btn btn-ghost" href="/orders/<?= (int) $row['id'] ?>#items">Abbrechen</a><?php endif; ?>
            </div>
        </form>
    </details>
    <?php endif; ?>
</div>

<div class="grid grid-2 mt-4">
    <div class="card" id="receipts">
        <div class="card-header"><h2>Wareneingänge</h2><?php if ($receivable): ?><a class="btn btn-secondary btn-sm" href="/orders/<?= (int) $row['id'] ?>/receive"><?= icon('download') ?> Buchen</a><?php endif; ?></div>
        <?php if ($receipts): ?>
        <ul class="list-plain">
            <?php foreach ($receipts as $g): ?>
            <li class="list-row">
                <div>
                    <a class="font-semibold" href="/orders/<?= (int) $row['id'] ?>/receipts/<?= (int) $g['id'] ?>"><?= fmt_date($g['received_at']) ?></a>
                    <?= $g['delivery_note_number'] ? ' · Lieferschein <span class="mono">' . e($g['delivery_note_number']) . '</span>' : '' ?>
                    <br><span class="text-sm text-muted"><?= (int) $g['quantity_total'] ?> Stück, <?= (int) $g['asset_count'] ?> Assets<?= $g['location_path'] ? ' → ' . e($g['location_path']) : '' ?> · <?= e($g['received_by_name'] ?? '?') ?></span>
                </div>
                <a class="btn btn-ghost btn-sm" href="/orders/<?= (int) $row['id'] ?>/receipts/<?= (int) $g['id'] ?>"><?= icon('chevron-right') ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="text-muted mb-0">Noch keine Lieferung gebucht.</p><?php endif; ?>
    </div>
    <div class="card" id="documents">
        <div class="card-header"><h2>Dokumente</h2><span class="text-muted text-sm"><?= count($documents) ?></span></div>
        <?php if ($documents): ?>
        <ul class="document-list">
            <?php foreach ($documents as $d): ?>
            <li class="document-item">
                <a class="document-thumb document-thumb-file" href="/documents/<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><?= icon('document') ?></a>
                <div class="document-meta">
                    <a href="/documents/<?= (int) $d['id'] ?>?download=1"><?= e($d['original_name']) ?></a>
                    <span class="text-muted text-xs"><?= e($documentTypes[$d['document_type']] ?? $d['document_type']) ?> · <?= fmt_bytes((int) $d['size_bytes']) ?> · <?= fmt_datetime($d['created_at']) ?> · <?= e($d['uploaded_by_name'] ?? '') ?><?= $d['note'] ? ' · ' . e($d['note']) : '' ?></span>
                </div>
                <?php if ($can('documents.manage')): ?>
                <form method="post" action="/documents/<?= (int) $d['id'] ?>/delete" data-confirm="Dokument wirklich löschen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" title="Löschen"><?= icon('trash') ?></button></form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?><p class="text-muted">Keine Dokumente hochgeladen.</p><?php endif; ?>
        <?php if ($can('documents.manage')): ?>
        <form method="post" action="/orders/<?= (int) $row['id'] ?>/documents" enctype="multipart/form-data" class="upload-form">
            <?= csrf_field() ?>
            <div class="form-row form-row-3">
                <div class="form-group"><label for="d-type">Art</label><select id="d-type" name="document_type"><?php foreach ($documentTypes as $k => $l): if ($k === 'photo' || $k === 'license') continue; ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="d-file">Datei</label><input id="d-file" type="file" name="file" required accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xlsx,.docx"></div>
                <div class="form-group"><label for="d-note">Notiz</label><input id="d-note" name="note" maxlength="500"></div>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('upload') ?> Hochladen</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($assets): ?>
<div class="card mt-4" id="assets">
    <div class="card-header"><h2>Erzeugte Assets</h2><?php if ($can('labels.print')): ?><a class="btn btn-secondary btn-sm" href="/labels?ids=<?= implode(',', array_map(static fn (array $a): int => (int) $a['id'], $assets)) ?>"><?= icon('print') ?> Alle Etiketten drucken</a><?php endif; ?></div>
    <div class="table-wrapper">
    <table class="table table-compact">
        <thead><tr><th>Inventarnr.</th><th>Typ</th><th>Bezeichnung</th><th>Seriennummer</th><th>Standort</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
            <tr data-href="/assets/<?= (int) $a['id'] ?>">
                <td class="mono font-semibold"><a href="/assets/<?= (int) $a['id'] ?>"><?= e($a['inventory_number']) ?></a></td>
                <td><?= icon($a['asset_type_icon'] ?: 'box', 'icon icon-sm icon-muted') ?> <?= e($a['asset_type_name']) ?></td>
                <td><?= e($a['name'] ?: ($a['article_name'] ?? '–')) ?></td>
                <td class="mono"><?= e($a['serial_number'] ?? '–') ?></td>
                <td><?= e($a['location_path'] ?? '–') ?></td>
                <td><?= badge($a['status_name'], $a['status_color']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if ($can('orders.manage')): ?>
<dialog id="template-dialog" class="dialog"><form method="post" action="/orders/<?= (int) $row['id'] ?>/templates"><?= csrf_field() ?><h2>Als Bestellvorlage speichern</h2><div class="form-group"><label for="template-name">Name <span class="required">*</span></label><input id="template-name" name="name" maxlength="150" required value="<?= e($row['supplier_name'] . ' – ') ?>"></div><div class="form-actions"><button class="btn btn-primary">Vorlage speichern</button><button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button></div></form></dialog>
<dialog id="cancel-dialog" class="dialog">
    <form method="post" action="/orders/<?= (int) $row['id'] ?>/status">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <h2>Bestellung stornieren</h2>
        <p class="text-muted">Die Bestellung <span class="mono"><?= e($row['order_number']) ?></span> wird als storniert markiert. Das ist nur möglich, solange noch keine Ware geliefert wurde.</p>
        <div class="form-group">
            <label for="c-reason">Grund <span class="required">*</span></label>
            <input id="c-reason" type="text" name="reason" maxlength="500" required placeholder="z. B. Lieferant kann nicht liefern">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger"><?= icon('x') ?> Stornieren</button>
            <button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php $scripts = ['/js/orders.js']; $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
