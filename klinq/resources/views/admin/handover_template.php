<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? [];
$name = (string) ($old['name'] ?? $template['name'] ?? 'Standardvorlage');
$blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
$options = json_encode([
    'types' => $blockTypes,
    'employeeFields' => $employeeFields,
    'assetColumns' => $assetColumns,
    'metaFields' => $metaFields,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/admin">Administration</a></p>
        <h1 class="page-title">Vorlage Übergabeprotokoll</h1>
        <p class="text-muted mb-0">Baukasten: Blöcke hinzufügen, anordnen und anpassen. Die Vorlage gilt für alle künftig angelegten Protokolle; bereits erstellte Versionen behalten ihren eingefrorenen Aufbau.</p>
    </div>
    <div class="page-actions">
        <?php if ($pdfHealthy === true): ?><?= badge('PDF-Dienst erreichbar', 'success') ?><?php elseif ($pdfHealthy === false): ?><?= badge('PDF-Dienst nicht erreichbar', 'danger') ?><?php else: ?><?= badge('PDF-Dienst nicht konfiguriert', 'warning') ?><?php endif; ?>
        <?php if ($template): ?><span class="text-muted text-sm">Zuletzt geändert <?= fmt_datetime($template['updated_at']) ?><?= $template['updated_by_display'] ? ' von ' . e($template['updated_by_display']) : '' ?></span><?php endif; ?>
    </div>
</div>

<form method="post" action="/admin/handover-template" id="hb-form" class="hb-layout" data-options="<?= e($options) ?>" data-preview-url="/admin/handover-template/preview" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="blocks" id="hb-blocks" value="<?= e($blocksJson) ?>">

    <div class="hb-editor">
        <div class="card">
            <div class="form-group<?= has_error('name') ? ' has-error' : '' ?>">
                <label for="hb-name">Name der Vorlage</label>
                <input id="hb-name" name="name" type="text" maxlength="120" value="<?= e($name) ?>">
                <?= field_error('name') ?>
            </div>
            <?= field_error('blocks') ?>
            <div class="hb-toolbar">
                <label for="hb-add-type" class="text-sm text-muted">Block hinzufügen</label>
                <select id="hb-add-type">
                    <?php foreach ($blockTypes as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-secondary" id="hb-add"><?= icon('plus') ?> Hinzufügen</button>
            </div>
        </div>

        <ol class="hb-blocks" id="hb-blocks-list" aria-label="Blöcke der Vorlage"></ol>

        <div class="card">
            <div class="cluster cluster-between">
                <div class="text-sm text-muted">Pflicht: mindestens ein Unterschriftsfeld für den Mitarbeiter.</div>
                <div class="cluster">
                    <button type="button" class="btn btn-ghost" id="hb-reset"><?= icon('refresh') ?> Änderungen verwerfen</button>
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?> Vorlage speichern</button>
                </div>
            </div>
        </div>

        <details class="card hb-help">
            <summary>Platzhalter für Texte</summary>
            <table class="table table-compact">
                <tbody>
                <?php foreach ($placeholders as $key => $label): ?><tr><td class="mono"><?= e($key) ?></td><td><?= e($label) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        </details>
    </div>

    <div class="hb-preview">
        <div class="card card-flush">
            <div class="card-header"><h2>Vorschau (Beispieldaten)</h2><span class="text-muted text-sm" id="hb-preview-state">aktuell</span></div>
            <div class="hp-paper" id="hb-preview"><?= $preview ?></div>
        </div>
    </div>
</form>

<template id="hb-block-template">
    <li class="hb-block card" data-index="">
        <div class="hb-block-head">
            <span class="hb-block-handle" aria-hidden="true">⋮⋮</span>
            <strong class="hb-block-title"></strong>
            <div class="hb-block-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-action="up" title="Nach oben" aria-label="Nach oben">↑</button>
                <button type="button" class="btn btn-ghost btn-sm" data-action="down" title="Nach unten" aria-label="Nach unten">↓</button>
                <button type="button" class="btn btn-ghost btn-sm" data-action="duplicate" title="Duplizieren" aria-label="Duplizieren">⧉</button>
                <button type="button" class="btn btn-ghost btn-sm text-danger" data-action="remove" title="Entfernen" aria-label="Entfernen">✕</button>
            </div>
        </div>
        <div class="hb-block-body"></div>
    </li>
</template>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/handover-template.js']; $extraStyles = ['/handover/print.css']; include __DIR__ . '/../partials/app_layout.php'; ?>
