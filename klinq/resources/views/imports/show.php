<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isPreview = $run['status'] === 'preview';
$rowBadge = static fn (string $s): string => match ($s) {
    'valid' => badge('Gültig', 'success'),
    'warning' => badge('Hinweis', 'warning'),
    'error' => badge('Fehler', 'danger'),
    'duplicate' => badge('Dublette', 'warning'),
    'imported' => badge('Importiert', 'success'),
    default => badge('Übersprungen', 'neutral'),
};
$runBadge = match ($run['status']) {
    'completed' => badge('Durchgeführt', 'success'),
    'preview' => badge('Vorschau – noch nichts gespeichert', 'info'),
    'cancelled' => badge('Verworfen', 'neutral'),
    default => badge('Fehlgeschlagen', 'danger'),
};
$problemCount = (int) $run['rows_error'] + (int) $run['rows_duplicate'];
$importable = (int) $run['rows_valid'] + (int) $run['rows_warning'];
$tabs = $isPreview
    ? ['' => 'Alle', 'valid' => 'Gültig', 'warning' => 'Mit Hinweisen', 'error' => 'Fehler', 'duplicate' => 'Dubletten']
    : ['' => 'Alle', 'imported' => 'Importiert', 'error' => 'Fehler', 'duplicate' => 'Dubletten', 'skipped' => 'Übersprungen'];
$tabCounts = ['valid' => (int) $run['rows_valid'], 'warning' => (int) $run['rows_warning'], 'error' => (int) $run['rows_error'], 'duplicate' => (int) $run['rows_duplicate'], 'imported' => (int) $run['rows_imported'], 'skipped' => max(0, (int) $run['rows_total'] - (int) $run['rows_imported'] - (int) $run['rows_error'] - (int) $run['rows_duplicate']), '' => (int) $run['rows_total']];
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/imports">Import</a></p>
        <h1>Import #<?= (int) $run['id'] ?> <?= $runBadge ?></h1>
        <p class="text-muted text-sm mb-0"><span class="mono"><?= e($run['original_name']) ?></span> · <?= e(fmt_bytes((int) $run['file_size'])) ?> · <?= e($run['encoding'] ?? '') ?> · Trennzeichen <?= e(\App\Support\CsvReader::delimiterLabel((string) $run['delimiter'])) ?> · hochgeladen <?= fmt_datetime($run['created_at']) ?> von <?= e($run['created_by_name'] ?? '–') ?><?= $run['committed_at'] ? ' · durchgeführt ' . fmt_datetime($run['committed_at']) : '' ?></p>
    </div>
    <div class="page-actions">
        <?php if ($problemCount > 0): ?>
            <a class="btn btn-secondary" href="/imports/<?= (int) $run['id'] ?>/errors"><?= icon('download') ?> Fehlerreport (CSV)</a>
        <?php endif; ?>
        <?php if ($isPreview): ?>
            <form method="post" action="/imports/<?= (int) $run['id'] ?>/cancel" class="inline-form" data-confirm="Import verwerfen? Die hochgeladene Datei wird gelöscht."><?= csrf_field() ?><button class="btn btn-ghost" type="submit"><?= icon('x') ?> Verwerfen</button></form>
        <?php endif; ?>
    </div>
</div>

<?php if ($run['error_message']): ?>
<div class="alert alert-error"><?= icon('warning') ?> <?= e($run['error_message']) ?></div>
<?php endif; ?>

<div class="grid grid-4">
    <div class="card stat-card"><span class="stat-value"><?= (int) $run['rows_total'] ?></span><span class="stat-label">Datenzeilen</span></div>
    <?php if ($isPreview): ?>
        <div class="card stat-card is-success"><span class="stat-value"><?= $importable ?></span><span class="stat-label">Importierbar<?= (int) $run['rows_warning'] > 0 ? ' (' . (int) $run['rows_warning'] . ' mit Hinweisen)' : '' ?></span></div>
    <?php else: ?>
        <div class="card stat-card is-success"><span class="stat-value"><?= (int) $run['rows_imported'] ?></span><span class="stat-label">Importiert</span></div>
    <?php endif; ?>
    <div class="card stat-card <?= (int) $run['rows_error'] > 0 ? 'is-danger' : '' ?>"><span class="stat-value"><?= (int) $run['rows_error'] ?></span><span class="stat-label">Fehlerhafte Zeilen</span></div>
    <div class="card stat-card <?= (int) $run['rows_duplicate'] > 0 ? 'is-warning' : '' ?>"><span class="stat-value"><?= (int) $run['rows_duplicate'] ?></span><span class="stat-label">Dubletten</span></div>
</div>

<div class="dashboard-grid mt-4">
    <?php if ($isPreview): ?>
    <section class="card col-span-7 import-commit">
        <div class="card-header"><h2>Import durchführen</h2></div>
        <?php if ($importable === 0): ?>
            <div class="alert alert-error mb-0"><?= icon('warning') ?> Keine importierbare Zeile vorhanden. Korrigieren Sie die Datei anhand des Fehlerreports und laden Sie sie erneut hoch.</div>
        <?php else: ?>
            <p><strong><?= $importable ?></strong> Zeile(n) werden als Assets angelegt<?= $problemCount > 0 ? '; <strong>' . $problemCount . '</strong> Zeile(n) mit Fehlern oder Dubletten werden übersprungen und im Protokoll festgehalten' : '' ?>. Vor dem Anlegen wird jede Zeile erneut geprüft.</p>
            <form method="post" action="/imports/<?= (int) $run['id'] ?>/commit" class="stack-sm" data-confirm="<?= $importable ?> Asset(s) jetzt anlegen?">
                <?= csrf_field() ?>
                <?php if ($problemCount > 0): ?>
                <label class="checkbox-field"><input type="checkbox" name="strict" value="1"> <span><strong>Nur vollständig fehlerfreie Datei importieren</strong> – abbrechen, falls noch Fehler oder Dubletten enthalten sind.</span></label>
                <?php endif; ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $importable ?> Asset(s) importieren</button>
                    <span class="text-muted text-sm">Vorschau läuft nach 24 Stunden ab.</span>
                </div>
            </form>
        <?php endif; ?>
    </section>
    <?php else: ?>
    <section class="card col-span-7">
        <div class="card-header"><h2>Ergebnis</h2></div>
        <?php if ($run['status'] === 'completed'): ?>
            <p><?= icon('check', 'icon text-success') ?> <strong><?= (int) $run['rows_imported'] ?></strong> Asset(s) wurden angelegt<?= $problemCount > 0 ? ', <strong>' . $problemCount . '</strong> Zeile(n) übersprungen (siehe Fehlerreport)' : '' ?>. Der Lauf ist im Audit-Log festgehalten.</p>
            <?php if ($importedRows): ?>
            <div class="import-asset-links">
                <?php foreach ($importedRows as $ir): ?><a class="badge badge-neutral mono" href="/assets/<?= (int) $ir['asset_id'] ?>"><?= e($ir['inventory_number']) ?></a><?php endforeach; ?>
                <?php if ((int) $run['rows_imported'] > count($importedRows)): ?><span class="text-muted text-sm">… und <?= (int) $run['rows_imported'] - count($importedRows) ?> weitere</span><?php endif; ?>
            </div>
            <?php endif; ?>
        <?php elseif ($run['status'] === 'cancelled'): ?>
            <p class="mb-0 text-muted">Dieser Import wurde verworfen. Es wurden keine Daten übernommen.</p>
        <?php else: ?>
            <p class="mb-0 text-muted">Dieser Import konnte nicht verarbeitet werden. Es wurden keine Daten übernommen.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card col-span-5">
        <div class="card-header"><h2>Erkannte Spalten</h2></div>
        <dl class="detail-list">
            <dt>Zugeordnet</dt>
            <dd><?php $mapped = $columnsFound['mapped'] ?? []; echo $mapped ? implode(' ', array_map(static fn (string $k): string => badge($columns[$k][0] ?? $k, 'success'), array_keys(array_intersect_key($columns, $mapped)))) : '<span class="text-muted">–</span>'; ?></dd>
            <dt>Ignoriert</dt>
            <dd><?php $un = $columnsFound['unmapped'] ?? []; echo $un ? implode(' ', array_map(static fn (string $k): string => badge($k, 'neutral'), $un)) : '<span class="text-muted">keine</span>'; ?></dd>
            <dt>Optionen</dt>
            <dd class="text-sm"><?= $options['legacy'] ? 'Altbestand (Jahreskennung 88)' : 'Kein Altbestand' ?> · <?= $options['create_manufacturers'] ? 'Unbekannte Hersteller anlegen' : 'Unbekannte Hersteller = Fehler' ?></dd>
            <?php if (($columnsFound['skipped_rows'] ?? 0) > 0): ?>
            <dt>Nicht gelesen</dt><dd class="text-warning text-sm"><?= (int) $columnsFound['skipped_rows'] ?> Zeile(n) über dem Limit</dd>
            <?php endif; ?>
        </dl>
    </section>
</div>

<section class="card card-flush mt-4">
    <div class="card-header">
        <h2>Zeilen</h2>
        <nav class="status-tabs" aria-label="Zeilenstatus">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="status-tab<?= $statusFilter === $key ? ' is-active' : '' ?>" href="<?= e(query_url($basePath, [], $key !== '' ? ['status' => $key] : [])) ?>"><?= e($label) ?> <span class="status-tab-count"><?= $tabCounts[$key] ?? 0 ?></span></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Zeilen mit diesem Status.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table table-compact import-rows">
        <thead><tr>
            <th class="text-right">Zeile</th>
            <th>Status</th>
            <th>Inventarnr.</th>
            <th>Asset</th>
            <th>Meldungen</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $msgs = $row['messages'] ?? []; ?>
            <tr class="import-row is-<?= e($row['status']) ?>">
                <td class="text-right text-muted"><?= (int) $row['row_number'] ?></td>
                <td class="nowrap"><?= $rowBadge($row['status']) ?></td>
                <td class="mono nowrap">
                    <?php if ($row['asset_id']): ?><a href="/assets/<?= (int) $row['asset_id'] ?>"><?= e($row['inventory_number']) ?></a>
                    <?php elseif ($row['inventory_number']): ?><?= e($row['inventory_number']) ?>
                    <?php else: ?><span class="text-muted">automatisch</span><?php endif; ?>
                </td>
                <td class="text-sm"><?= e($row['summary']) ?: '<span class="text-muted">–</span>' ?></td>
                <td class="text-sm">
                    <?php if (!$msgs): ?><span class="text-muted">–</span><?php else: ?>
                    <ul class="import-messages">
                        <?php foreach ($msgs as $m): ?>
                            <li class="is-<?= e($m['level']) ?>"><?= icon($m['level'] === 'error' ? 'x' : ($m['level'] === 'duplicate' ? 'warning' : 'info')) ?> <?= e($m['text']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
