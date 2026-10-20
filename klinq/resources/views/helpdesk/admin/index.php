<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk-admin';
$areaLabel = 'Help Desk';
$colorName = static fn (?string $c): string => $colors[$c ?? 'neutral'] ?? ($c ?? 'neutral');
$yesNo = static fn (mixed $v): string => (int) $v === 1 ? 'Ja' : 'Nein';
$cellActive = static fn (array $r): string => array_key_exists('is_active', $r) ? active_badge($r['is_active']) : '–';
$memberCount = static fn (array $r): int => (int) ($r['member_count'] ?? (is_array($r['members'] ?? null) ? count($r['members']) : 0));
?>
<div class="page-header">
    <div><h1 class="page-title">Help-Desk-Administration</h1><p class="page-subtitle text-muted">Stammdaten, SLAs, Vorlagen und Automatisierungen.</p></div>
    <div class="page-actions"><?php if ($can('helpdesk.admin')): ?><a class="btn btn-secondary" href="/helpdesk/admin/mail"><?= icon('mail') ?> E-Mail-Eingang</a> <?php endif; ?><a class="btn btn-primary" href="/helpdesk/admin/<?= e($kind) ?>/new"><?= icon('plus') ?> Neu</a></div>
</div>
<div class="status-tabs admin-tabs" role="tablist" aria-label="Administrationsbereiche">
    <?php foreach ($kinds as $key => $label): ?>
        <a class="status-tab<?= $kind === $key ? ' is-active' : '' ?>" href="/helpdesk/admin?tab=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<div class="card mb-4">
    <div class="card-header"><h2>Konfiguration</h2><span class="text-muted text-sm">aus Umgebung</span></div>
    <div class="grid grid-4">
        <?php foreach ($config as $key => $value): ?>
            <div><span class="kpi-value text-sm"><?= is_bool($value) ? e($yesNo($value)) : e((string) $value) ?></span><span class="kpi-label"><?= e(str_replace('_', ' ', $key)) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>
<div class="card mb-4">
    <div class="card-header"><h2>Störungsformular für Anwender</h2><span class="text-muted text-sm"><?= $quickReport['enabled'] ? 'aktiv' : 'deaktiviert' ?></span></div>
    <p class="text-muted text-sm">Öffentlicher Link ohne Anmeldung – nur Betreff und Beschreibung. Melder (inkl. AD-Abgleich), Rechnername und IP-Adresse werden automatisch erfasst. Diesen Link im Intranet, als Desktopverknüpfung oder per Gruppenrichtlinie verteilen.</p>
    <div class="form-row">
        <div class="form-group">
            <label for="quick-report-url">Link zum Formular</label>
            <input id="quick-report-url" class="mono" value="<?= e($quickReport['url']) ?>" readonly>
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <a class="btn btn-secondary" href="/stoerung" target="_blank" rel="noopener"><?= icon('ticket') ?> Formular öffnen</a>
        </div>
    </div>
    <p class="text-muted text-xs mb-0">Tickets werden unter dem Konto <strong><?= e($quickReport['system_user']) ?></strong> angelegt (benötigt das Recht helpdesk.create).<?= $quickReport['networks'] !== [] ? ' Zugriff beschränkt auf: ' . e(implode(', ', $quickReport['networks'])) . '.' : '' ?><?= $quickReport['enabled'] ? '' : ' Aktivierung über HELPDESK_QUICK_REPORT_ENABLED=true.' ?></p>
</div>
<div class="card card-flush">
    <div class="card-header"><h2><?= e($kinds[$kind] ?? 'Bereich') ?></h2><span class="text-muted text-sm"><?= count($rows) ?> Einträge</span></div>
    <?php if ($rows === []): ?>
        <div class="table-empty">Noch keine Einträge vorhanden.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="table table-compact">
            <thead>
            <?php if (in_array($kind, ['types', 'statuses', 'priorities'], true)): ?>
                <tr><th>Code</th><th>Name</th><th>Farbe</th><th>Sortierung</th><th>Aktiv</th><th>Nutzung</th><th></th></tr>
            <?php elseif ($kind === 'groups'): ?>
                <tr><th>Name</th><th>E-Mail</th><th>Mitglieder</th><th>Offen</th><th>Aktiv</th><th>Nutzung</th><th></th></tr>
            <?php elseif ($kind === 'categories'): ?>
                <tr><th>Name</th><th>Übergeordnet</th><th>Standardgruppe</th><th>Standardpriorität</th><th>Aktiv</th><th>Tickets</th><th></th></tr>
            <?php elseif ($kind === 'slas'): ?>
                <tr><th>Name</th><th>Priorität</th><th>Kategorie</th><th>Reaktion</th><th>Lösung</th><th>Geschäftszeiten</th><th>Warn/Eskalation</th><th>Aktiv</th><th></th></tr>
            <?php elseif ($kind === 'tags'): ?>
                <tr><th>Name</th><th>Farbe</th><th>Nutzung</th><th></th></tr>
            <?php elseif ($kind === 'templates'): ?>
                <tr><th>Name</th><th>Typ</th><th>Kategorie</th><th>Portal</th><th>Aktiv</th><th>Sortierung</th><th></th></tr>
            <?php else: ?>
                <tr><th>Name</th><th>Auslöser</th><th>Aktiv</th><th>Sortierung</th><th>Stoppt</th><th></th></tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                <?php if (in_array($kind, ['types', 'statuses', 'priorities'], true)): ?>
                    <td class="mono"><?= e($r['code'] ?? '–') ?><?php if ($kind === 'statuses'): ?><div class="text-xs text-muted"><?= e($statusCategories[$r['category']] ?? $r['category'] ?? '–') ?><?= !empty($r['pauses_sla']) ? ' · SLA pausiert' : '' ?></div><?php elseif ($kind === 'priorities'): ?><div class="text-xs text-muted">Stufe <?= (int) ($r['level'] ?? 0) ?><?= !empty($r['is_default']) ? ' · Standard' : '' ?></div><?php endif; ?></td>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><span class="color-swatch is-<?= e($r['color'] ?? 'neutral') ?>"></span><?= e($colorName($r['color'] ?? 'neutral')) ?></td>
                    <td class="mono"><?= (int) ($r['sort_order'] ?? 0) ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($usage[(int) $r['id']] ?? 0) ?></td>
                <?php elseif ($kind === 'groups'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['email'] ?? '–') ?></td>
                    <td class="mono"><?= $memberCount($r) ?></td>
                    <td class="mono"><?= (int) ($r['open_tickets'] ?? 0) ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($usage[(int) $r['id']] ?? 0) ?></td>
                <?php elseif ($kind === 'categories'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['parent_name'] ?? '–') ?></td>
                    <td><?= e($r['default_group_name'] ?? '–') ?></td>
                    <td><?= e($r['default_priority_name'] ?? '–') ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($r['ticket_count'] ?? 0) ?></td>
                <?php elseif ($kind === 'slas'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><?= !empty($r['is_default']) ? ' ' . badge('Standard', 'info') : '' ?><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['priority_name'] ?? '–') ?></td>
                    <td><?= e($r['category_name'] ?? '–') ?></td>
                    <td class="mono"><?= (int) ($r['response_minutes'] ?? 0) ?> min</td>
                    <td class="mono"><?= (int) ($r['resolution_minutes'] ?? 0) ?> min</td>
                    <td><?= !empty($r['business_hours_only']) ? e(($r['business_days'] ?? '') . ' · ' . substr((string) ($r['business_start'] ?? ''), 0, 5) . '–' . substr((string) ($r['business_end'] ?? ''), 0, 5)) : 'Nein' ?></td>
                    <td class="mono"><?= e((string) ($r['warning_percent'] ?? '–')) ?> / <?= e((string) ($r['escalation_percent'] ?? '–')) ?> %</td>
                    <td><?= $cellActive($r) ?></td>
                <?php elseif ($kind === 'tags'): ?>
                    <td><span class="tag-chip is-<?= e($r['color'] ?? 'neutral') ?>"><?= e($r['name'] ?? '–') ?></span></td>
                    <td><span class="color-swatch is-<?= e($r['color'] ?? 'neutral') ?>"></span><?= e($colorName($r['color'] ?? 'neutral')) ?></td>
                    <td class="mono"><?= (int) ($usage[(int) $r['id']] ?? $r['ticket_count'] ?? 0) ?></td>
                <?php elseif ($kind === 'templates'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['type_name'] ?? '–') ?></td>
                    <td><?= e($r['category_name'] ?? '–') ?><?= !empty($r['subcategory_name']) ? ' / ' . e($r['subcategory_name']) : '' ?></td>
                    <td><?= badge(!empty($r['is_portal_visible']) ? 'Portal' : 'Intern', !empty($r['is_portal_visible']) ? 'success' : 'neutral') ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($r['sort_order'] ?? 0) ?></td>
                <?php else: ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td class="mono"><?= e($r['trigger_event'] ?? '–') ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($r['sort_order'] ?? 0) ?></td>
                    <td><?= e($yesNo($r['stop_processing'] ?? 0)) ?></td>
                <?php endif; ?>
                    <td class="table-actions nowrap">
                        <a class="btn btn-ghost btn-sm" href="/helpdesk/admin/<?= e($kind) ?>/<?= (int) $r['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
                        <form method="post" action="/helpdesk/admin/<?= e($kind) ?>/<?= (int) $r['id'] ?>/toggle"<?= in_array($kind, ['tags', 'rules'], true) ? ' data-confirm="' . e(($kind === 'tags' ? 'Tag' : 'Regel') . ' wirklich löschen?') . '"' : '' ?>><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm"><?= icon(in_array($kind, ['tags', 'rules'], true) ? 'trash' : (!empty($r['is_active']) ? 'x' : 'refresh')) ?> <?= in_array($kind, ['tags', 'rules'], true) ? 'Löschen' : (!empty($r['is_active']) ? 'Deaktivieren' : 'Aktivieren') ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
