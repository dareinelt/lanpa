<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$status = $row['expiry_status'];
$active = (int) $row['is_active'] === 1;
$assignable = $active && $status !== 'expired' && (int) $row['available_count'] > 0 && $can('licenses.manage');
$activeAssignments = array_values(array_filter($assignments, static fn (array $a): bool => $a['released_at'] === null));
$releasedAssignments = array_values(array_filter($assignments, static fn (array $a): bool => $a['released_at'] !== null));
$labelText = trim(($row['manufacturer_name'] ? $row['manufacturer_name'] . ' ' : '') . $row['product']);
$scripts = ['/js/picker.js'];
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/licenses">Lizenzen</a></p>
        <h1 class="page-title"><?= e($row['product']) ?> <?= badge($expiryLabels[$status] ?? $status, $expiryColors[$status] ?? 'neutral') ?><?= !$active ? ' ' . badge('Deaktiviert', 'neutral') : '' ?></h1>
        <p class="text-muted mb-0"><?= $row['manufacturer_name'] ? '<a href="/manufacturers/' . (int) $row['manufacturer_id'] . '">' . e($row['manufacturer_name']) . '</a>' : 'Ohne Hersteller' ?><?= $row['license_type'] ? ' · ' . e($row['license_type']) : '' ?><?= $row['license_number'] ? ' · <span class="mono">' . e($row['license_number']) . '</span>' : '' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($can('licenses.manage')): ?>
            <a class="btn btn-secondary" href="/licenses/<?= (int) $row['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
            <form method="post" action="/licenses/<?= (int) $row['id'] ?>/toggle"<?= $active ? ' data-confirm="Lizenz deaktivieren? Bestehende Zuordnungen bleiben erhalten, neue sind nicht mehr möglich."' : '' ?>><?= csrf_field() ?><input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>"><button type="submit" class="btn btn-ghost"><?= icon($active ? 'x' : 'refresh') ?> <?= $active ? 'Deaktivieren' : 'Aktivieren' ?></button></form>
        <?php endif; ?>
    </div>
</div>

<?php if ($status === 'expired'): ?>
<div class="alert alert-error"><?= icon('warning') ?> Diese Lizenz ist am <?= fmt_date($row['expires_at']) ?> abgelaufen<?= $activeAssignments ? ' und noch ' . count($activeAssignments) . ' Asset(s) zugeordnet' : '' ?>. Verlängern (Ablaufdatum anpassen) oder Zuordnungen aufheben.</div>
<?php elseif ($status === 'expiring'): ?>
<div class="alert alert-warning"><?= icon('clock') ?> Diese Lizenz läuft in <?= (int) $row['days_left'] ?> Tagen ab (<?= fmt_date($row['expires_at']) ?>).</div>
<?php endif; ?>

<div class="grid grid-4 mb-4">
    <div class="card stat-card<?= (int) $row['available_count'] <= 0 ? ' is-warning' : ' is-success' ?>"><span class="stat-value"><?= (int) $row['available_count'] ?></span><span class="stat-label">Verfügbar</span></div>
    <div class="card stat-card"><span class="stat-value"><?= (int) $row['used_count'] ?> <span class="stat-sub">/ <?= (int) $row['quantity'] ?></span></span><span class="stat-label">Verwendet</span></div>
    <div class="card stat-card<?= $status === 'expired' ? ' is-danger' : ($status === 'expiring' ? ' is-warning' : '') ?>"><span class="stat-value"><?= $row['expires_at'] ? fmt_date($row['expires_at']) : '∞' ?></span><span class="stat-label"><?= $row['expires_at'] ? ($status === 'expired' ? 'Abgelaufen' : 'Läuft ab') : 'Unbefristet' ?></span></div>
    <div class="card stat-card"><span class="stat-value"><?= $row['cost'] !== null ? fmt_money($row['cost']) : '–' ?></span><span class="stat-label">Kosten<?= $row['cost'] !== null && (int) $row['quantity'] > 1 ? ' · ' . fmt_money((float) $row['cost'] / (int) $row['quantity']) . ' je Einheit' : '' ?></span></div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Lizenz</h2></div>
        <dl class="detail-list">
            <dt>Hersteller</dt><dd><?= $row['manufacturer_name'] ? '<a href="/manufacturers/' . (int) $row['manufacturer_id'] . '">' . e($row['manufacturer_name']) . '</a>' : '–' ?></dd>
            <dt>Produkt</dt><dd><?= e($row['product']) ?></dd>
            <dt>Lizenztyp</dt><dd><?= e($row['license_type'] ?? '–') ?></dd>
            <dt>Lizenznummer</dt><dd class="mono"><?= e($row['license_number'] ?? '–') ?></dd>
            <dt>Lizenzschlüssel</dt>
            <dd>
                <?php if ($row['license_key'] === null || $row['license_key'] === ''): ?>–
                <?php elseif ($showKey): ?><code class="license-key"><?= e($row['license_key']) ?></code> <a class="btn btn-ghost btn-sm" href="/licenses/<?= (int) $row['id'] ?>">Ausblenden</a>
                <?php else: ?><span class="mono text-muted">••••••••••••</span> <a class="btn btn-ghost btn-sm" href="/licenses/<?= (int) $row['id'] ?>?show_key=1"><?= icon('key') ?> Anzeigen</a>
                <?php endif; ?>
            </dd>
            <dt>Anzahl</dt><dd><?= (int) $row['quantity'] ?> Einheiten</dd>
            <dt>Bemerkung</dt><dd><?= nl2br_e($row['note']) ?: '–' ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Beschaffung</h2></div>
        <dl class="detail-list">
            <dt>Kaufdatum</dt><dd><?= fmt_date($row['purchase_date'], '–') ?></dd>
            <dt>Ablaufdatum</dt><dd><?= $row['expires_at'] ? fmt_date($row['expires_at']) . ' <span class="text-muted text-sm">(' . ($status === 'expired' ? 'vor ' . abs((int) $row['days_left']) : 'in ' . (int) $row['days_left']) . ' Tagen)</span>' : 'unbefristet' ?></dd>
            <dt>Lieferant</dt><dd><?= $row['supplier_name'] ? '<a href="/suppliers/' . (int) $row['supplier_id'] . '">' . e($row['supplier_name']) . '</a>' : '–' ?></dd>
            <dt>Bestellung</dt><dd><?= $row['order_number'] ? '<a class="mono" href="/orders/' . (int) $row['purchase_order_id'] . '">' . e($row['order_number']) . '</a>' : '–' ?></dd>
            <dt>Kosten</dt><dd><?= $row['cost'] !== null ? fmt_money($row['cost']) : '–' ?></dd>
            <dt>Kostenstelle</dt><dd><?= $row['cost_center_number'] ? '<span class="mono">' . e($row['cost_center_number']) . '</span> ' . e($row['cost_center_name']) : '–' ?></dd>
            <dt>Angelegt</dt><dd><?= fmt_datetime($row['created_at']) ?></dd>
            <dt>Geändert</dt><dd><?= fmt_datetime($row['updated_at']) ?></dd>
        </dl>
    </div>
</div>

<div class="card card-flush mt-4" id="assignments">
    <div class="card-header"><h2>Zugeordnete Assets</h2><span class="text-muted text-sm"><?= count($activeAssignments) ?> von <?= (int) $row['quantity'] ?> Einheiten belegt</span></div>
    <?php if ($activeAssignments === []): ?>
        <div class="table-empty">Diese Lizenz ist noch keinem Asset zugeordnet.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table table-compact">
        <thead><tr><th>Inventarnr.</th><th>Typ</th><th>Bezeichnung</th><th>Mitarbeiter</th><th>Standort</th><th>Status</th><th>Zugeordnet</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($activeAssignments as $a): ?>
            <tr data-href="/assets/<?= (int) $a['asset_id'] ?>">
                <td class="mono font-semibold"><a href="/assets/<?= (int) $a['asset_id'] ?>"><?= e($a['inventory_number']) ?></a></td>
                <td class="nowrap"><?= icon($a['asset_type_icon'] ?: 'box', 'icon icon-sm icon-muted') ?> <?= e($a['asset_type_name']) ?></td>
                <td><?= e($a['asset_name'] ?? $a['article_name'] ?? '–') ?><?= $a['serial_number'] ? ' <span class="text-muted text-xs mono">' . e($a['serial_number']) . '</span>' : '' ?></td>
                <td><?= e($a['employee_name'] ?? '–') ?></td>
                <td class="text-sm"><?= e($a['location_path'] ?? '–') ?></td>
                <td><?= badge($a['status_name'], $a['status_color'] ?? 'neutral') ?></td>
                <td class="text-sm nowrap"><?= fmt_datetime($a['assigned_at']) ?><br><span class="text-muted text-xs"><?= e($a['assigned_by']) ?><?= $a['note'] ? ' · ' . e($a['note']) : '' ?></span></td>
                <td class="table-actions">
                    <?php if ($can('licenses.manage')): ?>
                    <form method="post" action="/licenses/<?= (int) $row['id'] ?>/assignments/<?= (int) $a['id'] ?>/release" data-confirm="Zuordnung zu <?= e($a['inventory_number']) ?> aufheben?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" title="Zuordnung aufheben"><?= icon('x') ?> Freigeben</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($can('licenses.manage')): ?>
<div class="card mt-4" id="assign">
    <div class="card-header"><h2>Asset zuordnen</h2><?php if (!$assignable): ?><span class="text-muted text-sm"><?= !$active ? 'Lizenz deaktiviert' : ($status === 'expired' ? 'Lizenz abgelaufen' : 'Keine freie Einheit') ?></span><?php endif; ?></div>
    <?php if ($assignable): ?>
    <form method="post" action="/licenses/<?= (int) $row['id'] ?>/assign" class="assign-form" novalidate data-license-assign>
        <?= csrf_field() ?>
        <div class="form-row">
            <?php $name = 'asset_id'; $label = 'Asset'; $searchUrl = '/api/assets/search'; $selected = null; $required = true; $placeholder = 'Inventarnummer, Seriennummer oder Bezeichnung …'; $hint = 'Eingabe mit Enter übernimmt auch eine gescannte Inventar-/Seriennummer direkt.'; $id = 'assign-asset'; include __DIR__ . '/../mobile/_picker.php'; ?>
            <div class="form-group<?= has_error('note') ? ' has-error' : '' ?>">
                <label for="assign-note">Bemerkung</label>
                <input id="assign-note" name="note" maxlength="255" value="<?= e(form_value(null, 'note')) ?>" placeholder="optional, z. B. Aktivierungsdatum">
                <?= field_error('note') ?>
            </div>
        </div>
        <input type="hidden" name="asset_code" value="" data-asset-code>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('link') ?> Zuordnen</button></div>
    </form>
    <?php else: ?>
    <p class="text-muted mb-0"><?= !$active ? 'Aktivieren Sie die Lizenz, um sie erneut zuzuordnen.' : ($status === 'expired' ? 'Passen Sie das Ablaufdatum an, um die Lizenz weiter zu verwenden.' : 'Alle ' . (int) $row['quantity'] . ' Einheiten sind belegt. Erhöhen Sie die Anzahl oder geben Sie eine Zuordnung frei.') ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid grid-2 mt-4">
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
        <form method="post" action="/licenses/<?= (int) $row['id'] ?>/documents" enctype="multipart/form-data" class="upload-form">
            <?= csrf_field() ?>
            <div class="form-row form-row-3">
                <div class="form-group"><label for="d-type">Art</label><select id="d-type" name="document_type"><?php foreach (['license', 'invoice', 'order', 'order_confirmation', 'other'] as $k): ?><option value="<?= e($k) ?>"><?= e($documentTypes[$k]) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="d-file">Datei</label><input id="d-file" type="file" name="file" required accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.csv,.xlsx,.docx"></div>
                <div class="form-group"><label for="d-note">Notiz</label><input id="d-note" name="note" maxlength="500"></div>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm"><?= icon('upload') ?> Hochladen</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card card-flush" id="history">
        <div class="card-header"><h2>Frühere Zuordnungen</h2><span class="text-muted text-sm"><?= count($releasedAssignments) ?></span></div>
        <?php if ($releasedAssignments === []): ?>
            <div class="table-empty">Keine aufgehobenen Zuordnungen.</div>
        <?php else: ?>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Inventarnr.</th><th>Zugeordnet</th><th>Freigegeben</th></tr></thead>
            <tbody>
            <?php foreach ($releasedAssignments as $a): ?>
                <tr class="is-muted" data-href="/assets/<?= (int) $a['asset_id'] ?>">
                    <td class="mono"><a href="/assets/<?= (int) $a['asset_id'] ?>"><?= e($a['inventory_number']) ?></a></td>
                    <td class="text-sm"><?= fmt_datetime($a['assigned_at']) ?><br><span class="text-muted text-xs"><?= e($a['assigned_by']) ?></span></td>
                    <td class="text-sm"><?= fmt_datetime($a['released_at']) ?><br><span class="text-muted text-xs"><?= e($a['released_by'] ?? '') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
