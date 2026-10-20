<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? null;
$oldItems = is_array($old['items'] ?? null) ? $old['items'] : [];
$val = static fn (string $key, string $default = ''): string => $old !== null ? (string) ($old[$key] ?? '') : $default;
?>
<div class="page-header">
    <div>
        <p class="breadcrumb text-sm text-muted mb-2"><a href="/orders/<?= (int) $row['id'] ?>"><?= icon('arrow-left', 'icon icon-sm') ?> Bestellung <?= e($row['order_number']) ?></a></p>
        <h1><?= icon('download') ?> Wareneingang buchen</h1>
        <p class="page-subtitle text-muted mb-0"><?= e($row['supplier_name']) ?> · Bestellung <span class="mono"><?= e($row['order_number']) ?></span> · <?= (int) $row['quantity_total'] - (int) $row['quantity_received'] ?> Stück offen</p>
    </div>
</div>

<?= field_error('items') ?>

<form method="post" action="/orders/<?= (int) $row['id'] ?>/receive" class="receive-form" novalidate>
    <?= csrf_field() ?>
    <div class="card card-form-wide">
        <div class="card-header"><h2>Lieferung</h2></div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('received_at') ? ' has-error' : '' ?>">
                <label for="r-date">Lieferdatum <span class="required">*</span></label>
                <input id="r-date" type="date" name="received_at" value="<?= e($val('received_at', date('Y-m-d'))) ?>" max="<?= date('Y-m-d') ?>" required>
                <?= field_error('received_at') ?>
            </div>
            <div class="form-group<?= has_error('delivery_note_number') ? ' has-error' : '' ?>">
                <label for="r-dn">Lieferscheinnummer</label>
                <input id="r-dn" name="delivery_note_number" value="<?= e($val('delivery_note_number')) ?>" maxlength="100" class="mono" autofocus>
                <?= field_error('delivery_note_number') ?>
            </div>
            <div class="form-group<?= has_error('location_id') ? ' has-error' : '' ?>">
                <label for="r-location">Lagerort der neuen Assets</label>
                <select id="r-location" name="location_id">
                    <option value="">– später zuordnen –</option>
                    <?php $sel = $val('location_id', (string) ($defaultLocationId ?? '')); foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected($sel, $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('location_id') ?>
            </div>
        </div>
        <div class="form-group<?= has_error('note') ? ' has-error' : '' ?>">
            <label for="r-note">Bemerkung</label>
            <input id="r-note" name="note" value="<?= e($val('note')) ?>" maxlength="2000" placeholder="z. B. Karton beschädigt, Zubehör fehlt">
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><h2>Positionen prüfen & Mengen erfassen</h2><span class="text-muted text-sm">Nur eingetragene Mengen werden gebucht</span></div>
        <?php if ($items === []): ?>
            <p class="text-muted mb-0">Alle Positionen sind bereits vollständig geliefert.</p>
        <?php else: ?>
        <div class="receive-items">
        <?php foreach ($items as $it):
            $id = (int) $it['id'];
            $line = $oldItems[$id] ?? [];
            $qty = (string) ($line['quantity'] ?? '');
            $serials = array_values((array) ($line['serials'] ?? []));
            $createsAssets = (int) $it['creates_assets'] === 1;
            $qtyKey = "items.{$id}.quantity";
        ?>
            <fieldset class="receive-item<?= has_error($qtyKey) ? ' has-error' : '' ?>" data-receive-item data-item-id="<?= $id ?>" data-serial="<?= $createsAssets && (int) $it['has_serial_number'] === 1 ? '1' : '0' ?>" data-creates-assets="<?= $createsAssets ? '1' : '0' ?>">
                <div class="receive-item-head">
                    <div class="receive-item-title">
                        <span class="text-muted">Pos. <?= (int) $it['position'] ?></span>
                        <strong><?= e($it['description']) ?></strong>
                        <span class="text-sm text-muted"><?= $it['asset_type_name'] ? icon($it['asset_type_icon'] ?: 'box', 'icon icon-sm icon-muted') . ' ' . e($it['asset_type_name']) . ' (' . e($it['inventory_prefix']) . ')' : 'ohne Asset' ?><?= $it['article_number'] ? ' · Art.-Nr. ' . e($it['article_number']) : '' ?></span>
                    </div>
                    <div class="receive-item-qty">
                        <label for="q-<?= $id ?>">Geliefert <span class="text-muted">(offen: <?= (int) $it['quantity_open'] ?> von <?= (int) $it['quantity'] ?>)</span></label>
                        <div class="qty-input">
                            <input id="q-<?= $id ?>" type="number" name="items[<?= $id ?>][quantity]" min="0" max="<?= (int) $it['quantity_open'] ?>" value="<?= e($qty) ?>" inputmode="numeric" data-receive-qty placeholder="0">
                            <button type="button" class="btn btn-ghost btn-sm" data-receive-all="<?= (int) $it['quantity_open'] ?>" title="Vollständig geliefert">alle</button>
                        </div>
                        <?= field_error($qtyKey) ?>
                    </div>
                </div>
                <?php if ($createsAssets): ?>
                <div class="receive-serials" data-serial-list>
                    <?php foreach ($serials as $i => $s): if ($i >= (int) $qty) break; $sKey = "items.{$id}.serials.{$i}"; ?>
                    <div class="form-group serial-field<?= has_error($sKey) ? ' has-error' : '' ?>">
                        <label for="s-<?= $id ?>-<?= $i ?>">Seriennummer Stück <?= $i + 1 ?></label>
                        <input id="s-<?= $id ?>-<?= $i ?>" name="items[<?= $id ?>][serials][]" value="<?= e((string) $s) ?>" class="mono" maxlength="120" autocomplete="off">
                        <?= field_error($sKey) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint receive-hint" data-serial-hint<?= $createsAssets && (int) $it['has_serial_number'] === 1 ? '' : ' hidden' ?>>Je Stück ein Feld – Scanner-Eingabe springt automatisch weiter. Leere Felder sind erlaubt; Dubletten werden abgewiesen.</p>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="form-actions receive-actions">
        <button type="submit" class="btn btn-primary btn-lg"<?= $items === [] ? ' disabled' : '' ?>><?= icon('check') ?> Wareneingang buchen & Assets anlegen</button>
        <a class="btn btn-ghost" href="/orders/<?= (int) $row['id'] ?>">Abbrechen</a>
        <span class="text-sm text-muted" data-receive-summary></span>
    </div>
</form>
<?php $scripts = ['/js/orders.js']; $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
