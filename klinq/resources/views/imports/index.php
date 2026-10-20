<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$statusBadge = static fn (string $s): string => match ($s) {
    'completed' => badge('Durchgeführt', 'success'),
    'preview' => badge('Vorschau', 'info'),
    'cancelled' => badge('Verworfen', 'neutral'),
    default => badge('Fehlgeschlagen', 'danger'),
};
?>
<div class="page-header">
    <div>
        <h1>Import</h1>
        <p class="page-subtitle text-muted mb-0">Assets aus CSV-Dateien übernehmen – mit Prüfung, Vorschau, Dublettenerkennung und Protokoll.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/imports/template"><?= icon('download') ?> CSV-Vorlage</a>
    </div>
</div>

<div class="dashboard-grid">
    <section class="card col-span-5" id="upload">
        <div class="card-header"><h2>Neuer Import</h2></div>
        <ol class="import-steps">
            <li><strong>Datei hochladen</strong> – CSV mit Kopfzeile (UTF-8 oder Windows-1252; Semikolon, Komma oder Tabulator).</li>
            <li><strong>Vorschau prüfen</strong> – jede Zeile wird validiert, Dubletten und fehlende Stammdaten werden gemeldet. Es wird noch nichts gespeichert.</li>
            <li><strong>Import durchführen</strong> – nur fehlerfreie Zeilen werden angelegt; das Ergebnis bleibt als Protokoll erhalten.</li>
        </ol>
        <form method="post" action="/imports" enctype="multipart/form-data" class="stack-sm" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="import-file">CSV-Datei <span class="required">*</span></label>
                <input id="import-file" type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required>
                <?= field_error('file') ?>
                <span class="form-hint">Maximal <?= $maxMb ?> MB und <?= number_format($maxRows, 0, ',', '.') ?> Zeilen je Datei.</span>
            </div>
            <div class="form-group">
                <label for="import-delimiter">Trennzeichen</label>
                <select id="import-delimiter" name="delimiter">
                    <option value="">Automatisch erkennen</option>
                    <option value="semicolon"<?= selected(old('delimiter'), 'semicolon') ?>>Semikolon (;)</option>
                    <option value="comma"<?= selected(old('delimiter'), 'comma') ?>>Komma (,)</option>
                    <option value="tab"<?= selected(old('delimiter'), 'tab') ?>>Tabulator</option>
                    <option value="pipe"<?= selected(old('delimiter'), 'pipe') ?>>Senkrechter Strich (|)</option>
                </select>
            </div>
            <label class="checkbox-field"><input type="checkbox" name="legacy" value="1"<?= old('legacy', '1') === '1' ? ' checked' : '' ?>> <span><strong>Altbestand</strong> – Zeilen ohne Inventarnummer erhalten eine Nummer mit Jahreskennung <strong>88</strong> (z. B. PC88001); pro Zeile über die Spalte „Altbestand“ übersteuerbar.</span></label>
            <label class="checkbox-field"><input type="checkbox" name="create_manufacturers" value="1"<?= old('create_manufacturers', '1') === '1' ? ' checked' : '' ?>> <span><strong>Unbekannte Hersteller anlegen</strong> – sonst werden Zeilen mit unbekanntem Hersteller als Fehler gemeldet.</span></label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= icon('upload') ?> Hochladen und prüfen</button>
            </div>
        </form>
    </section>

    <section class="card card-flush col-span-7">
        <div class="card-header"><h2>Spalten der CSV-Datei</h2><span class="text-muted text-sm">Reihenfolge beliebig, Groß-/Kleinschreibung egal</span></div>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Spalte</th><th>Bedeutung</th><th>Beispiel</th></tr></thead>
            <tbody>
            <?php foreach ($columns as $key => [$label, $aliases, $required, $hint, $example]): ?>
                <tr>
                    <td class="nowrap"><strong><?= e($label) ?></strong><?= $required ? ' <span class="text-danger" title="Pflicht">*</span>' : '' ?><br><span class="text-muted text-xs">auch: <?= e(implode(', ', array_slice($aliases, 0, 3))) ?></span></td>
                    <td class="text-sm"><?= $hint !== '' ? e($hint) : '<span class="text-muted">Freitext</span>' ?></td>
                    <td class="mono text-sm"><?= e($example) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="text-muted text-sm card-footnote">* Assettyp kann entfallen, wenn die Inventarnummer angegeben ist (Typ wird aus dem Präfix abgeleitet). Nicht zugeordnete Spalten werden ignoriert und in der Vorschau aufgeführt.</p>
    </section>
</div>

<section class="card card-flush mt-4">
    <div class="card-header"><h2>Importprotokoll</h2><span class="text-muted text-sm"><?= $paginator->total ?> Läufe</span></div>
    <?php if (!$runs): ?>
        <div class="table-empty">Noch kein Import durchgeführt.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table table-compact">
        <thead><tr><th>Zeitpunkt</th><th>Datei</th><th>Status</th><th>Von</th><th class="text-right">Zeilen</th><th class="text-right">Importiert</th><th class="text-right">Fehler</th><th class="text-right">Dubletten</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
            <tr class="is-clickable" data-href="/imports/<?= (int) $r['id'] ?>">
                <td class="nowrap"><?= fmt_datetime($r['created_at']) ?></td>
                <td><span class="mono text-sm"><?= e($r['original_name']) ?></span><br><span class="text-muted text-xs"><?= e(fmt_bytes((int) $r['file_size'])) ?></span></td>
                <td><?= $statusBadge($r['status']) ?><?= $r['error_message'] ? '<br><span class="text-danger text-xs">' . e($r['error_message']) . '</span>' : '' ?></td>
                <td class="text-sm"><?= e($r['created_by_name'] ?? '–') ?></td>
                <td class="text-right"><?= (int) $r['rows_total'] ?></td>
                <td class="text-right"><?= $r['status'] === 'completed' ? '<strong>' . (int) $r['rows_imported'] . '</strong>' : '<span class="text-muted">–</span>' ?></td>
                <td class="text-right"><?= (int) $r['rows_error'] > 0 ? '<span class="text-danger">' . (int) $r['rows_error'] . '</span>' : '0' ?></td>
                <td class="text-right"><?= (int) $r['rows_duplicate'] > 0 ? '<span class="text-warning">' . (int) $r['rows_duplicate'] . '</span>' : '0' ?></td>
                <td class="text-right"><a class="btn btn-ghost btn-sm" href="/imports/<?= (int) $r['id'] ?>">Details</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
