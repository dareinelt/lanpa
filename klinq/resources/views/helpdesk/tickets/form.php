<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$isEdit = $ticket !== null;
// Datenquelle für Formularwerte: bestehendes Ticket, sonst Vorbelegung (Vorlage / Query-Parameter)
$row = $isEdit ? $ticket : $prefill;
$action = $isEdit ? '/helpdesk/tickets/' . (int) $ticket['id'] : '/helpdesk/tickets';
$wizardMode = !empty($wizardMode) && !$isEdit;
$modeQuery = $modeQuery ?? [];
$classicUrl = query_url('/helpdesk/tickets/new', $modeQuery, ['modus' => 'klassisch']);
$wizardUrl = query_url('/helpdesk/tickets/new', $modeQuery, ['modus' => 'assistent']);
$templateBaseUrl = '/helpdesk/tickets/new?modus=assistent';
$maxUploadBytes = (int) ($maxUploadBytes ?? 20 * 1024 * 1024);
$closeUrl = '/helpdesk/tickets';
$wizardStart = $wizardMode ? max(1, min(8, (int) ($wizardStart ?? 1))) : 1;
$wizardSteps = [
    1 => 'Vorlage oder Betreff',
    2 => 'Melder & Betroffener',
    3 => 'Beschreibung',
    4 => 'Typ, Kategorie & Unterkategorie',
    5 => 'Auswirkung, Dringlichkeit & Priorität',
    6 => 'Standort & Kostenstelle',
    7 => 'Gruppe, Bearbeiter & Vertretung',
    8 => 'Assets & Tags',
];
$selectedAssets = [];
if ($isEdit) {
    foreach ($linkedAssets as $la) {
        $selectedAssets[] = (int) $la['asset_id'];
    }
} elseif (!empty($prefill['asset_id'])) {
    $selectedAssets[] = (int) $prefill['asset_id'];
}
$oldAssets = $_SESSION['_old_input']['asset_ids'] ?? null;
if (is_array($oldAssets)) {
    $selectedAssets = array_map('intval', $oldAssets);
}
$tagValue = form_value($row, 'tags', $isEdit ? implode(', ', array_map(static fn (array $t): string => (string) $t['name'], $tags)) : '');
$requesterId = (int) form_value($row, 'requester_employee_id');
$affectedId = (int) form_value($row, 'affected_employee_id');
// Beschriftung fuer bereits gewaehlte Personen (Picker zeigt nur die Auswahl an)
$selectedEmployee = static function (int $id) use ($employees): ?array {
    foreach ($employees as $emp) {
        if ((int) $emp['id'] === $id) {
            return ['id' => (int) $emp['id'], 'name' => $emp['display_name'], 'meta' => $emp['department'] ?? ''];
        }
    }

    return null;
};
$selectedRequester = $selectedEmployee($requesterId);
$selectedAffected = $selectedEmployee($affectedId);
?>
<span hidden data-hd-ai-config data-enabled="<?= !empty($aiEnabled) ? '1' : '0' ?>"></span>
<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="/helpdesk/tickets"<?= $wizardMode ? ' data-hd-confirm-leave' : '' ?>>Tickets</a><?php if ($isEdit): ?> › <a href="/helpdesk/tickets/<?= (int) $ticket['id'] ?>"><?= e($ticket['number']) ?></a><?php endif; ?></div>
        <h1><?= $isEdit ? 'Ticket ' . e($ticket['number']) . ' bearbeiten' : 'Neues Ticket' ?></h1>
        <?php if (!$isEdit): ?><p class="text-sm text-muted hd-mode-hint">Der zuletzt verwendete Eingabemodus wird als Ihr Standard gespeichert – unabhängig vom Gerät.</p><?php endif; ?>
    </div>
    <?php if (!$isEdit): ?>
    <div class="page-actions">
        <?php if (!$wizardMode && $templates !== []): ?>
        <label for="template-select" class="text-sm text-muted">Vorlage</label>
        <select id="template-select" data-hd-template="/helpdesk/tickets/new">
            <option value="">– keine Vorlage –</option>
            <?php foreach ($templates as $tpl): ?><option value="<?= (int) $tpl['id'] ?>"<?= selected($templateId, $tpl['id']) ?>><?= e($tpl['name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="hd-mode-switch" role="group" aria-label="Eingabemodus">
            <?php if ($wizardMode): ?>
            <a class="btn btn-sm" href="<?= e($classicUrl) ?>" data-hd-confirm-leave>Klassisch</a>
            <span class="btn btn-sm btn-primary" aria-current="page"><?= icon('sparkle') ?> Assistent</span>
            <?php else: ?>
            <span class="btn btn-sm btn-primary" aria-current="page">Klassisch</span>
            <a class="btn btn-sm" href="<?= e($wizardUrl) ?>"><?= icon('sparkle') ?> Assistent</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($_SESSION['_errors'])): ?><div class="alert alert-danger"><?= icon('warning') ?> Bitte die markierten Felder prüfen.</div><?php endif; ?>

<form method="post" action="<?= $action ?>" class="card form-card<?= $wizardMode ? ' hd-wizard-form' : '' ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <?php if ($wizardMode): ?>
    <input type="hidden" name="modus" value="assistent">
    <input type="hidden" name="wizard_step" value="<?= $wizardStart ?>" data-hd-wizard-step>
    <?php endif; ?>
    <?php if ($templateId !== null): ?><input type="hidden" name="template" value="<?= (int) $templateId ?>"><?php endif; ?>
    <?php if ($isEdit): ?><input type="hidden" name="version" value="<?= (int) $ticket['version'] ?>"><?php endif; ?>

    <?php if ($wizardMode): ?>
    <div class="hd-wizard" data-hd-wizard data-hd-wizard-count="<?= count($wizardSteps) ?>" data-hd-wizard-start="<?= $wizardStart ?>">
        <div class="hd-wizard-progress">
            <div class="hd-wizard-progress-text">
                <span data-hd-wizard-position>Schritt 1 von <?= count($wizardSteps) ?></span>
                <span class="text-muted" data-hd-wizard-title><?= e($wizardSteps[1]) ?></span>
            </div>
            <progress max="<?= count($wizardSteps) ?>" value="1" data-hd-wizard-bar></progress>
        </div>

        <?php foreach ($wizardSteps as $n => $label): ?>
        <section class="hd-step" data-hd-step="<?= $n ?>" data-hd-step-title="<?= e($label) ?>">
            <header class="hd-step-header">
                <button type="button" class="hd-step-goto" data-hd-step-goto="<?= $n ?>">
                    <span class="hd-step-index" aria-hidden="true"><?= $n ?></span>
                    <span class="hd-step-heading">
                        <span class="hd-step-title"><?= e($label) ?></span>
                        <span class="hd-step-summary" data-hd-step-summary="<?= $n ?>"></span>
                    </span>
                </button>
                <button type="button" class="hd-step-toggle" data-hd-step-toggle="<?= $n ?>" aria-expanded="false" aria-controls="hd-step-body-<?= $n ?>" aria-label="Zusammenfassung einblenden">
                    <?= icon('chevron-down', 'icon hd-step-caret') ?>
                </button>
            </header>
            <div class="hd-step-body" id="hd-step-body-<?= $n ?>" data-hd-step-body="<?= $n ?>">
                <?php $step = $n; include __DIR__ . '/_fields.php'; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <div class="hd-wizard-nav">
            <button type="button" class="btn btn-secondary" data-hd-wizard-prev hidden><?= icon('chevron-left') ?> Zurück</button>
            <span class="hd-wizard-nav-spacer"></span>
            <a class="btn btn-ghost" href="<?= e($closeUrl) ?>" data-hd-wizard-close>Schließen</a>
            <button type="button" class="btn btn-primary" data-hd-wizard-next>Weiter <?= icon('chevron-right') ?></button>
            <button type="submit" class="btn btn-primary" data-hd-wizard-submit><?= icon('check') ?> Ticket anlegen</button>
        </div>
    </div>
    <?php else: ?>
    <fieldset>
        <legend>Anliegen</legend>
        <?php $step = 1; include __DIR__ . '/_fields.php'; ?>
        <?php $step = 3; include __DIR__ . '/_fields.php'; ?>
        <?php $step = 4; include __DIR__ . '/_fields.php'; ?>
    </fieldset>

    <fieldset>
        <legend>Priorisierung</legend>
        <?php $step = 5; include __DIR__ . '/_fields.php'; ?>
    </fieldset>

    <fieldset>
        <legend>Personen &amp; Zuordnung</legend>
        <?php $step = 2; include __DIR__ . '/_fields.php'; ?>
        <?php $step = 7; include __DIR__ . '/_fields.php'; ?>
        <?php $step = 6; include __DIR__ . '/_fields.php'; ?>
    </fieldset>

    <fieldset>
        <legend>Assets &amp; Tags</legend>
        <?php $step = 8; include __DIR__ . '/_fields.php'; ?>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isEdit ? 'Änderungen speichern' : 'Ticket anlegen' ?></button>
        <a class="btn btn-secondary" href="<?= $isEdit ? '/helpdesk/tickets/' . (int) $ticket['id'] : '/helpdesk/tickets' ?>">Abbrechen</a>
    </div>
    <?php endif; ?>
</form>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
