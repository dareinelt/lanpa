<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$stateBadge = static fn (string $state): string => match ($state) {
    'ok' => badge('Aktuell', 'success'),
    'outdated' => badge('Veraltet', 'warning'),
    'draft' => badge('Entwurf offen', 'info'),
    'missing' => badge('Kein Protokoll', 'danger'),
    default => badge('Keine Arbeitsmittel', 'neutral'),
};
$statusBadge = static fn (string $s): string => badge($statusLabels[$s] ?? $s, match ($s) { 'signed' => 'success', 'draft' => 'info', 'superseded' => 'neutral', default => 'danger' });
$tabs = ['' => 'Alle', 'missing' => 'Kein Protokoll', 'outdated' => 'Veraltet', 'draft' => 'Entwurf offen', 'ok' => 'Aktuell'];
$query = ['q' => $q, 'state' => $state];
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Übergabeprotokolle</h1>
        <p class="page-subtitle text-muted mb-0">Je Mitarbeiter gilt das zuletzt unterschriebene Protokoll. Ändert sich der Bestand protokollrelevanter Arbeitsmittel, wird eine neue Version fällig.</p>
    </div>
    <div class="page-actions">
        <?php if ($can('handover.sign')): ?><a class="btn btn-secondary" href="/m/handover"><?= icon('phone') ?> Mobile Unterschrift</a><?php endif; ?>
        <?php if ($can('handover.template')): ?><a class="btn btn-secondary" href="/admin/handover-template"><?= icon('settings') ?> Vorlage</a><?php endif; ?>
    </div>
</div>

<?php if (!$pdfEnabled): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning') ?> <span>Der PDF-Dienst ist nicht konfiguriert (<code>PDF_SERVICE_URL</code>). Protokolle werden unterschrieben und archiviert; PDFs lassen sich später nachträglich erzeugen.</span></div>
<?php endif; ?>

<section class="grid grid-4 mb-4" aria-label="Zusammenfassung">
    <?php foreach (['missing' => ['Kein Protokoll', 'danger'], 'outdated' => ['Veraltet – Bestand geändert', 'warning'], 'draft' => ['Entwurf offen', 'info'], 'ok' => ['Aktuell', 'success']] as $key => [$label, $tone]): ?>
        <a class="card stat-card<?= $counts[$key] > 0 ? ' is-' . $tone : '' ?>" href="<?= e(query_url('/handover', $query, ['state' => $key])) ?>"><span class="stat-value"><?= (int) $counts[$key] ?></span><span class="stat-label"><?= $label ?></span></a>
    <?php endforeach; ?>
</section>

<div class="status-tabs" role="tablist" aria-label="Stand">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="status-tab<?= $state === $key ? ' is-active' : '' ?>" href="<?= e(query_url('/handover', $query, ['state' => $key !== '' ? $key : null])) ?>" role="tab"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<form method="get" action="/handover" class="filter-bar" role="search">
    <?php if ($state !== ''): ?><input type="hidden" name="state" value="<?= e($state) ?>"><?php endif; ?>
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, Benutzername, Personalnr., Abteilung …">
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="/handover<?= $state !== '' ? '?state=' . e($state) : '' ?>"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>

<div class="card card-flush">
    <div class="card-header"><h2>Mitarbeiter</h2><span class="text-muted text-sm"><?= count($rows) ?> Einträge</span></div>
    <?php if ($rows === []): ?>
        <div class="table-empty">Keine Mitarbeiter mit protokollrelevanten Arbeitsmitteln gefunden. Arbeitsmittel werden über die Artikel-Checkbox „Relevant für Übergabeprotokoll“ aufgenommen.</div>
    <?php else: ?>
    <div class="table-wrapper"><table class="table">
        <thead><tr><th>Mitarbeiter</th><th>Abteilung</th><th class="text-right">Relevante Assets</th><th>Gültiges Protokoll</th><th>Stand</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="is-clickable" data-href="/handover/employee/<?= (int) $r['id'] ?>">
                <td><strong><?= e($r['display_name']) ?></strong><br><span class="text-muted text-sm mono"><?= e(implode(' · ', array_filter([$r['username'], $r['personnel_number']]))) ?></span></td>
                <td><?= e($r['department'] ?? '–') ?></td>
                <td class="text-right"><?= (int) $r['relevant_count'] ?><?php if ($r['current_id'] !== null && (int) $r['current_item_count'] !== (int) $r['relevant_count']): ?> <span class="text-muted text-sm">(Protokoll: <?= (int) $r['current_item_count'] ?>)</span><?php endif; ?></td>
                <td><?php if ($r['current_id'] !== null): ?><a href="/handover/<?= (int) $r['current_id'] ?>">Version <?= (int) $r['current_version'] ?></a><br><span class="text-muted text-sm"><?= fmt_datetime($r['current_signed_at']) ?></span><?php else: ?><span class="text-muted">–</span><?php endif; ?></td>
                <td><?= $stateBadge($r['state']) ?></td>
                <td class="text-right">
                    <?php if ($r['draft_id'] !== null): ?>
                        <a class="btn btn-sm btn-primary" href="/handover/<?= (int) $r['draft_id'] ?>"><?= icon('pen') ?> Entwurf v<?= (int) $r['draft_version'] ?></a>
                    <?php elseif ($can('handover.manage') && in_array($r['state'], ['missing', 'outdated'], true)): ?>
                        <form method="post" action="/handover/employee/<?= (int) $r['id'] ?>/draft" class="inline-form"><?= csrf_field() ?><button class="btn btn-sm btn-secondary" type="submit"><?= icon('plus') ?> Protokoll erstellen</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php if ($recent !== []): ?>
<div class="card card-flush mt-4">
    <div class="card-header"><h2>Zuletzt bearbeitete Protokolle</h2></div>
    <div class="table-wrapper"><table class="table table-compact">
        <thead><tr><th>Nummer</th><th>Mitarbeiter</th><th>Version</th><th>Status</th><th class="text-right">Assets</th><th>Unterschrieben</th><th>PDF</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $p): ?>
            <tr class="is-clickable" data-href="/handover/<?= (int) $p['id'] ?>">
                <td class="mono"><strong><?= e($p['protocol_number']) ?></strong></td>
                <td><?= e($p['employee_name']) ?></td>
                <td>v<?= (int) $p['version'] ?></td>
                <td><?= $statusBadge($p['status']) ?></td>
                <td class="text-right"><?= (int) $p['item_count'] ?></td>
                <td><?= $p['signed_at'] ? fmt_datetime($p['signed_at']) : '<span class="text-muted">–</span>' ?></td>
                <td><?= $p['pdf_document_id'] ? '<a href="/handover/' . (int) $p['id'] . '/pdf" title="PDF öffnen">' . icon('document') . '</a>' : '<span class="text-muted">–</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
