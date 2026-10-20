<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$today = date('Y-m-d');
$warranty = $row['warranty_until'];
$warrantyBadge = $warranty === null ? '' : ($warranty < $today ? badge('abgelaufen', 'danger') : ($warranty < date('Y-m-d', strtotime('+90 days')) ? badge('läuft bald ab', 'warning') : badge('gültig', 'success')));
$displayName = $row['name'] ?: ($row['article_name'] ?? $row['asset_type_name']);
$eventLabels = [
    'created' => 'Angelegt', 'status_changed' => 'Statuswechsel', 'assignment_changed' => 'Zuordnung Mitarbeiter', 'location_changed' => 'Standortwechsel',
    'cost_center_changed' => 'Kostenstelle', 'field_changed' => 'Änderung', 'note' => 'Kommentar', 'checkout' => 'Entnahme', 'return' => 'Rückgabe',
    'label_printed' => 'Etikett gedruckt', 'movement_completed' => 'Vorgang abgeschlossen', 'movement_cancelled' => 'Vorgang storniert', 'goods_receipt' => 'Wareneingang', 'import' => 'Import', 'license_assigned' => 'Lizenz zugewiesen', 'license_removed' => 'Lizenz entfernt',
];
$eventClass = ['status_changed' => 'is-warning', 'created' => 'is-success', 'checkout' => 'is-warning', 'return' => 'is-success', 'movement_cancelled' => 'is-danger'];
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/assets">Assets</a> / <?= e($row['asset_type_name']) ?></p>
        <div class="asset-title">
            <h1 class="mono"><?= e($row['inventory_number']) ?></h1>
            <?= badge($row['status_name'], $row['status_color']) ?>
            <?php if ((int) $row['is_legacy']): ?><?= badge('Altbestand', 'neutral') ?><?php endif; ?>
        </div>
        <p class="text-muted mb-0"><?= e(implode(' · ', array_filter([$row['manufacturer_name'], $displayName, $row['category_name']]))) ?></p>
    </div>
    <div class="page-actions">
        <?php if ($can('labels.print')): ?><a class="btn btn-secondary" href="/labels?ids=<?= (int) $row['id'] ?>"><?= icon('print') ?> Etikett</a><?php endif; ?>
        <?php if ($can('movements.checkout') && (int) $row['status_available'] === 1): ?><a class="btn btn-secondary" href="/movements/checkout?asset=<?= e(rawurlencode($row['inventory_number'])) ?>"><?= icon('checkout') ?> Entnahme</a><?php endif; ?>
        <?php if ($can('movements.return') && $row['employee_id']): ?><a class="btn btn-secondary" href="/movements/return?asset=<?= e(rawurlencode($row['inventory_number'])) ?>"><?= icon('return') ?> Rückgabe</a><?php endif; ?>
        <?php if ($can('assets.manage')): ?>
            <button type="button" class="btn btn-secondary" data-dialog-open="status-dialog"><?= icon('swap') ?> Status</button>
            <a class="btn btn-primary" href="/assets/<?= (int) $row['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-3">
    <div class="card">
        <div class="card-header"><h2>Identifikation</h2></div>
        <dl class="detail-list">
            <dt>Assettyp</dt><dd><?= icon($row['asset_type_icon'] ?: 'box', 'icon icon-muted') ?> <?= e($row['asset_type_name']) ?></dd>
            <dt>Kategorie</dt><dd><?= e($row['category_name'] ?? '–') ?></dd>
            <dt>Hersteller</dt><dd><?= e($row['manufacturer_name'] ?? '–') ?></dd>
            <dt>Artikel</dt><dd><?= $row['article_id'] ? '<a href="/articles/' . (int) $row['article_id'] . '/edit">' . e($row['article_name']) . '</a>' . ($row['article_number'] ? ' <span class="text-muted text-sm mono">' . e($row['article_number']) . '</span>' : '') : '–' ?></dd>
            <dt>Bezeichnung</dt><dd><?= e($row['name'] ?? '–') ?></dd>
            <dt>Seriennummer</dt><dd class="mono"><?= e($row['serial_number'] ?? '–') ?></dd>
            <?php if ($row['mac_address'] || (int) $row['has_mac_address']): ?><dt>MAC-Adresse</dt><dd class="mono"><?= e($row['mac_address'] ?? '–') ?></dd><?php endif; ?>
            <?php if ($row['imei'] || (int) $row['has_imei']): ?><dt>IMEI</dt><dd class="mono"><?= e($row['imei'] ?? '–') ?></dd><?php endif; ?>
            <?php if ($row['parent_asset_id']): ?><dt>Gehört zu</dt><dd><a class="mono" href="/assets/<?= (int) $row['parent_asset_id'] ?>"><?= e($row['parent_inventory_number']) ?></a></dd><?php endif; ?>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Beschaffung</h2></div>
        <dl class="detail-list">
            <dt>Kaufdatum</dt><dd><?= fmt_date($row['purchase_date']) ?></dd>
            <dt>Lieferant</dt><dd><?= $row['supplier_id'] ? '<a href="/suppliers/' . (int) $row['supplier_id'] . '/edit">' . e($row['supplier_name']) . '</a>' : '–' ?></dd>
            <dt>Bestellung</dt><dd><?= $row['purchase_order_id'] ? '<a class="mono" href="/orders/' . (int) $row['purchase_order_id'] . '">' . e($row['order_number']) . '</a>' : '–' ?></dd>
            <dt>Anschaffungskosten</dt><dd><?= fmt_money($row['purchase_price']) ?></dd>
            <dt>Garantieende</dt><dd><?= fmt_date($warranty) ?> <?= $warrantyBadge ?></dd>
            <dt>Angelegt</dt><dd><?= fmt_datetime($row['created_at']) ?></dd>
            <dt>Zuletzt geändert</dt><dd><?= fmt_datetime($row['updated_at']) ?> <span class="text-muted text-xs">(v<?= (int) $row['version'] ?>)</span></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Zuordnung</h2></div>
        <dl class="detail-list">
            <dt>Mitarbeiter</dt><dd><?= $row['employee_id'] ? '<a href="/employees/' . (int) $row['employee_id'] . '">' . e($row['employee_name']) . '</a>' . ((int) $row['employee_active'] ? '' : ' ' . badge('inaktiv', 'neutral')) . ($row['employee_department'] ? '<br><span class="text-muted text-sm">' . e($row['employee_department']) . '</span>' : '') : '<span class="text-muted">Nicht zugeordnet</span>' ?></dd>
            <?php if ($row['assigned_at']): ?><dt>Ausgegeben seit</dt><dd><?= fmt_date($row['assigned_at']) ?></dd><?php endif; ?>
            <?php if ($row['expected_return_at']): ?><dt>Rückgabe erwartet</dt><dd class="<?= $row['expected_return_at'] < $today ? 'text-danger' : '' ?>"><?= fmt_date($row['expected_return_at']) ?><?= $row['expected_return_at'] < $today ? ' ' . badge('überfällig', 'danger') : '' ?></dd><?php endif; ?>
            <dt>Standort</dt><dd><?= $row['location_id'] ? '<a href="/locations/' . (int) $row['location_id'] . '">' . e($row['location_path']) . '</a>' : '–' ?></dd>
            <dt>Kostenstelle</dt><dd><?= $row['cost_center_id'] ? '<span class="mono">' . e($row['cost_center_number']) . '</span> ' . e($row['cost_center_name']) : '–' ?></dd>
            <dt>Status</dt><dd><?= badge($row['status_name'], $row['status_color']) ?></dd>
        </dl>
    </div>
</div>

<?php if ($row['note']): ?>
<div class="card mt-4">
    <div class="card-header"><h2>Bemerkung</h2></div>
    <p class="mb-0"><?= nl2br_e($row['note']) ?></p>
</div>
<?php endif; ?>

<?php if ($children): ?>
<div class="card card-flush mt-4">
    <div class="card-header"><h2>Zugehörige Assets</h2><a class="btn btn-link btn-sm" href="/assets?status=all&parent_asset_id=<?= (int) $row['id'] ?>">Alle anzeigen</a></div>
    <table class="table table-compact">
        <thead><tr><th>Inventarnummer</th><th>Typ</th><th>Bezeichnung</th><th>Seriennummer</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($children as $c): ?>
            <tr class="is-clickable" data-href="/assets/<?= (int) $c['id'] ?>">
                <td class="mono"><strong><?= e($c['inventory_number']) ?></strong></td>
                <td><?= e($c['category_name'] ?? $c['asset_type_name']) ?></td>
                <td><?= e($c['name'] ?: ($c['article_name'] ?? '–')) ?></td>
                <td class="mono text-sm"><?= e($c['serial_number'] ?? '–') ?></td>
                <td><?= badge($c['status_name'], $c['status_color']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($movements)): ?>
<div class="card card-flush mt-4" id="movements">
    <div class="card-header"><h2>Bewegungen</h2><?php if ($can('movements.view')): ?><a class="btn btn-link btn-sm" href="/movements?range=all&q=<?= e(rawurlencode($row['inventory_number'])) ?>">Alle anzeigen</a><?php endif; ?></div>
    <table class="table table-compact">
        <thead><tr><th>Datum</th><th>Vorgang</th><th>Mitarbeiter</th><th>Standort</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($movements as $m): ?>
            <tr class="<?= $can('movements.view') ? 'is-clickable' : '' ?><?= $m['status'] === 'cancelled' ? ' is-muted' : '' ?>"<?= $can('movements.view') ? ' data-href="/movements/' . (int) $m['id'] . '"' : '' ?>>
                <td class="nowrap"><?= fmt_datetime($m['movement_at']) ?></td>
                <td class="nowrap"><?= icon($m['type'] === 'checkout' ? 'checkout' : 'return', 'icon icon-muted') ?> <?= $m['type'] === 'checkout' ? 'Entnahme' : 'Rückgabe' ?></td>
                <td><?= e($m['employee_name'] ?? '–') ?></td>
                <td class="text-sm"><?= e($m['to_location_path'] ?? '–') ?></td>
                <td><?= match ($m['status']) { 'open' => badge('Offen', 'warning'), 'completed' => badge('Abgeschlossen', 'success'), default => badge('Storniert', 'neutral') } ?></td>
                <td class="table-actions text-muted text-sm"><?= e(App\Services\MovementService::SOURCES[$m['source']] ?? $m['source']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if (!empty($licenses) || $can('licenses.manage')): ?>
<div class="card card-flush mt-4" id="licenses">
    <div class="card-header"><h2>Lizenzen</h2><?php if ($can('licenses.view')): ?><a class="btn btn-link btn-sm" href="/licenses?status=available">Verfügbare Lizenzen</a><?php endif; ?></div>
    <?php if (empty($licenses)): ?>
        <div class="table-empty">Keine Lizenz zugeordnet. Die Zuordnung erfolgt auf der Lizenzseite unter „Asset zuordnen“.</div>
    <?php else: ?>
    <table class="table table-compact">
        <thead><tr><th>Produkt</th><th>Hersteller</th><th>Lizenztyp</th><th>Lizenznummer</th><th>Ablauf</th><th>Zugeordnet</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($licenses as $l): $ls = $l['expiry_status']; ?>
            <tr class="is-clickable" data-href="/licenses/<?= (int) $l['id'] ?>">
                <td class="font-semibold"><a href="/licenses/<?= (int) $l['id'] ?>"><?= e($l['product']) ?></a></td>
                <td><?= e($l['manufacturer_name'] ?? '–') ?></td>
                <td><?= e($l['license_type'] ?? '–') ?></td>
                <td class="mono text-sm"><?= e($l['license_number'] ?? '–') ?></td>
                <td class="nowrap"><?= $l['expires_at'] ? fmt_date($l['expires_at']) . ' ' : '' ?><?= badge(App\Services\LicenseService::EXPIRY_LABELS[$ls] ?? $ls, App\Services\LicenseService::EXPIRY_COLORS[$ls] ?? 'neutral') ?></td>
                <td class="text-sm"><?= fmt_datetime($l['assigned_at']) ?><br><span class="text-muted text-xs"><?= e($l['assigned_by']) ?></span></td>
                <td class="table-actions">
                    <?php if ($can('licenses.manage')): ?>
                    <form method="post" action="/licenses/<?= (int) $l['id'] ?>/assignments/<?= (int) $l['assignment_id'] ?>/release" data-confirm="Lizenz „<?= e($l['product']) ?>“ von diesem Asset entfernen?"><?= csrf_field() ?><input type="hidden" name="back" value="/assets/<?= (int) $row['id'] ?>#licenses"><button type="submit" class="btn btn-ghost btn-sm" title="Zuordnung aufheben"><?= icon('x') ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tickets !== null): ?>
<div class="card card-flush mt-4" id="tickets">
    <div class="card-header"><h2>Help-Desk-Tickets</h2>
        <div>
            <a class="btn btn-link btn-sm" href="/helpdesk/tickets?view=all&amp;asset_id=<?= (int) $row['id'] ?>">Alle anzeigen</a>
            <?php if ($can('helpdesk.create')): ?><a class="btn btn-secondary btn-sm" href="/helpdesk/tickets/new?asset_id=<?= (int) $row['id'] ?>"><?= icon('plus') ?> Ticket erstellen</a><?php endif; ?>
        </div>
    </div>
    <?php if ($tickets === []): ?>
        <div class="table-empty">Keine Tickets zu diesem Asset.</div>
    <?php else: ?>
    <table class="table table-compact">
        <thead><tr><th>Nummer</th><th>Betreff</th><th>Status</th><th>Priorität</th><th>Bearbeiter</th><th>Aktualisiert</th></tr></thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
            <tr class="is-clickable<?= in_array($t['status_category'], ['resolved', 'closed', 'cancelled'], true) ? ' text-muted' : '' ?>" data-href="/helpdesk/tickets/<?= (int) $t['id'] ?>">
                <td class="mono"><a href="/helpdesk/tickets/<?= (int) $t['id'] ?>"><?= e($t['number']) ?></a></td>
                <td><?= e($t['subject']) ?></td>
                <td><?= badge($t['status_name'], $t['status_color']) ?></td>
                <td><?= badge($t['priority_name'], $t['priority_color']) ?></td>
                <td><?= e($t['assignee_name'] ?? '–') ?></td>
                <td class="nowrap text-sm"><?= fmt_datetime($t['updated_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card mt-4" id="history">
    <div class="card-header"><h2>Historie</h2><span class="text-muted text-sm"><?= count($history) ?> Einträge</span></div>
    <?php if ($can('assets.manage')): ?>
    <form method="post" action="/assets/<?= (int) $row['id'] ?>/note" class="history-form mb-4">
        <?= csrf_field() ?>
        <div class="form-group mb-2">
            <label for="h-note">Kommentar zur Historie hinzufügen</label>
            <textarea id="h-note" name="note" rows="2" maxlength="500" placeholder="z. B. Display getauscht, Akku geprüft …" required></textarea>
        </div>
        <button type="submit" class="btn btn-secondary btn-sm"><?= icon('plus') ?> Kommentar speichern</button>
    </form>
    <?php endif; ?>
    <?php if (!$history): ?>
        <p class="text-muted mb-0">Noch keine Einträge.</p>
    <?php else: ?>
    <ul class="timeline">
        <?php foreach ($history as $h): $label = $fieldLabels[$h['field']][0] ?? $h['field']; ?>
            <li class="<?= $eventClass[$h['event_type']] ?? '' ?>">
                <span class="timeline-dot"></span>
                <div class="timeline-time"><?= fmt_datetime($h['created_at']) ?> · <?= e($h['user_display_name'] ?? $h['actor_name']) ?></div>
                <div class="timeline-title">
                    <?= e($eventLabels[$h['event_type']] ?? $h['event_type']) ?><?= $h['field'] && !in_array($h['event_type'], ['status_changed', 'assignment_changed', 'location_changed', 'cost_center_changed'], true) ? ': ' . e($label) : '' ?>
                </div>
                <?php if ($h['event_type'] === 'created'): ?>
                    <div class="timeline-detail">Inventarnummer <span class="mono"><?= e($h['new_value']) ?></span> vergeben</div>
                <?php elseif ($h['field'] !== null): ?>
                    <div class="timeline-detail">
                        <?php if ($h['old_value'] !== null): ?><span class="value-old"><?= e($h['old_value']) ?></span><span class="value-arrow">→</span><?php endif; ?>
                        <?= $h['new_value'] !== null ? '<strong>' . e($h['new_value']) . '</strong>' : '<span class="text-muted">– entfernt –</span>' ?>
                    </div>
                <?php endif; ?>
                <?php if ($h['note']): ?><div class="history-note"><?= e($h['note']) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php if ($can('assets.manage')): ?>
<dialog id="status-dialog" class="dialog">
    <form method="post" action="/assets/<?= (int) $row['id'] ?>/status">
        <?= csrf_field() ?>
        <div class="dialog-header"><h2>Status ändern</h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button></div>
        <div class="dialog-body">
            <p class="text-muted text-sm">Aktuell: <?= badge($row['status_name'], $row['status_color']) ?>. Für Entnahme und Rückgabe bitte die Bewegungs-Workflows verwenden.</p>
            <div class="form-group">
                <label for="s-status">Neuer Status</label>
                <select id="s-status" name="status" required>
                    <?php foreach ($statuses as $s): if ((int) $s['id'] === (int) $row['status_id']) { continue; } $needsRetire = (int) $s['is_final'] === 1 && !$can('assets.retire'); ?>
                        <option value="<?= e($s['code']) ?>"<?= $needsRetire ? ' disabled' : '' ?>><?= e($s['name']) ?><?= (int) $s['is_final'] === 1 ? ' (endgültig)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="s-note">Begründung / Bemerkung</label>
                <textarea id="s-note" name="note" rows="3" maxlength="500" placeholder="optional, erscheint in der Historie"></textarea>
            </div>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Status setzen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
