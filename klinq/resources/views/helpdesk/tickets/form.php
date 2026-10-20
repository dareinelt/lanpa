<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$isEdit = $ticket !== null;
// Datenquelle für Formularwerte: bestehendes Ticket, sonst Vorbelegung (Vorlage / Query-Parameter)
$row = $isEdit ? $ticket : $prefill;
$action = $isEdit ? '/helpdesk/tickets/' . (int) $ticket['id'] : '/helpdesk/tickets';
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
$selectedRequester = null;
foreach ($employees as $emp) {
    if ((int) $emp['id'] === $requesterId) {
        $selectedRequester = ['id' => $emp['id'], 'name' => $emp['display_name'], 'meta' => $emp['department'] ?? ''];
        break;
    }
}
?>
<span hidden data-hd-ai-config data-enabled="<?= !empty($aiEnabled) ? '1' : '0' ?>"></span>
<div class="page-header">
    <div>
        <div class="breadcrumb"><a href="/helpdesk/tickets">Tickets</a><?php if ($isEdit): ?> › <a href="/helpdesk/tickets/<?= (int) $ticket['id'] ?>"><?= e($ticket['number']) ?></a><?php endif; ?></div>
        <h1><?= $isEdit ? 'Ticket ' . e($ticket['number']) . ' bearbeiten' : 'Neues Ticket' ?></h1>
    </div>
    <?php if (!$isEdit && $templates !== []): ?>
    <div class="page-actions">
        <label for="template-select" class="text-sm text-muted">Vorlage</label>
        <select id="template-select" data-hd-template="/helpdesk/tickets/new">
            <option value="">– keine Vorlage –</option>
            <?php foreach ($templates as $tpl): ?><option value="<?= (int) $tpl['id'] ?>"<?= selected($templateId, $tpl['id']) ?>><?= e($tpl['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($_SESSION['_errors'])): ?><div class="alert alert-danger"><?= icon('warning') ?> Bitte die markierten Felder prüfen.</div><?php endif; ?>

<form method="post" action="<?= $action ?>" class="card form-card" novalidate>
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="version" value="<?= (int) $ticket['version'] ?>"><?php endif; ?>

    <fieldset>
        <legend>Anliegen</legend>
        <div class="form-group<?= has_error('subject') ? ' has-error' : '' ?>">
            <label for="f-subject">Betreff *</label>
            <input id="f-subject" name="subject" required minlength="3" maxlength="255" value="<?= e(form_value($row, 'subject')) ?>" data-hd-kb-suggest="#kb-suggest" data-hd-field="ticket_subject" autofocus>
            <?= field_error('subject') ?>
        </div>
        <?php if ($can('knowledgebase.view')): ?><div id="kb-suggest" class="kb-suggest" hidden></div><?php endif; ?>
        <?php $canEditDescription = !$isEdit || $can('helpdesk.admin'); ?>
        <div class="form-group<?= has_error('description') ? ' has-error' : '' ?>">
            <label for="f-description">Beschreibung<?= $canEditDescription ? ' *' : '' ?></label>
            <textarea id="f-description" name="description" rows="8" required maxlength="20000" data-hd-field="ticket_description"<?= $canEditDescription ? '' : ' readonly aria-readonly="true"' ?>><?= e(form_value($row, 'description')) ?></textarea>
            <?php if ($canEditDescription): ?>
            <p class="form-hint">Was ist passiert, seit wann, welche Fehlermeldung, was wurde bereits versucht?</p>
            <?php else: ?>
            <p class="form-hint"><?= icon('shield', 'icon icon-xs') ?> Der Beschreibungstext kann nur von Administratoren geändert werden. Ergänzungen bitte als Kommentar oder interne Notiz erfassen.</p>
            <?php endif; ?>
            <?= field_error('description') ?>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('ticket_type_id') ? ' has-error' : '' ?>">
                <label for="f-type">Typ *</label>
                <select id="f-type" name="ticket_type_id" required>
                    <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected(form_value($row, 'ticket_type_id', $types[0]['id'] ?? ''), $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('ticket_type_id') ?>
            </div>
            <div class="form-group<?= has_error('category_id') ? ' has-error' : '' ?>">
                <label for="f-category">Kategorie</label>
                <select id="f-category" name="category_id" data-hd-category="#f-subcategory">
                    <option value="">– keine –</option>
                    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected(form_value($row, 'category_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('category_id') ?>
            </div>
            <div class="form-group<?= has_error('subcategory_id') ? ' has-error' : '' ?>">
                <label for="f-subcategory">Unterkategorie</label>
                <select id="f-subcategory" name="subcategory_id" data-selected="<?= e(form_value($row, 'subcategory_id')) ?>">
                    <option value="">– keine –</option>
                    <?php foreach ($subcategories as $sc): ?><option value="<?= (int) $sc['id'] ?>"<?= selected(form_value($row, 'subcategory_id'), $sc['id']) ?>><?= e($sc['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('subcategory_id') ?>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>Priorisierung</legend>
        <div class="form-row form-row-3">
            <div class="form-group">
                <label for="f-impact">Auswirkung</label>
                <select id="f-impact" name="impact">
                    <?php foreach ($impactLabels as $k => $l): ?><option value="<?= (int) $k ?>"<?= selected(form_value($row, 'impact', 2), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="f-urgency">Dringlichkeit</label>
                <select id="f-urgency" name="urgency">
                    <?php foreach ($urgencyLabels as $k => $l): ?><option value="<?= (int) $k ?>"<?= selected(form_value($row, 'urgency', 2), $k) ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group<?= has_error('priority_id') ? ' has-error' : '' ?>">
                <label for="f-priority">Priorität <span class="text-muted">(überschreibt Matrix)</span></label>
                <select id="f-priority" name="priority_id">
                    <option value="">automatisch aus Auswirkung × Dringlichkeit</option>
                    <?php foreach ($priorities as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected(form_value($row, 'priority_id'), $p['id']) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('priority_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group<?= has_error('sla_id') ? ' has-error' : '' ?>">
                <label for="f-sla">SLA <span class="text-muted">(leer = nach Priorität/Kategorie)</span></label>
                <select id="f-sla" name="sla_id">
                    <option value="">automatisch</option>
                    <?php foreach ($slas as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected(form_value($row, 'sla_id'), $s['id']) ?>><?= e($s['name']) ?> (<?= (int) $s['response_minutes'] ?> / <?= (int) $s['resolution_minutes'] ?> min)</option><?php endforeach; ?>
                </select>
                <?= field_error('sla_id') ?>
            </div>
            <div class="form-group<?= has_error('external_reference') ? ' has-error' : '' ?>">
                <label for="f-extref">Externe Referenz</label>
                <input id="f-extref" name="external_reference" maxlength="100" value="<?= e(form_value($row, 'external_reference')) ?>" placeholder="z. B. Hersteller-Case, Lieferanten-Ticket">
                <?= field_error('external_reference') ?>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>Personen &amp; Zuordnung</legend>
        <div class="form-row">
            <div class="form-group picker<?= has_error('requester_employee_id') ? ' has-error' : '' ?>" data-picker data-search-url="/api/employees/search" data-hd-employee-assets="#f-assets">
                <label for="f-requester">Melder</label>
                <input type="hidden" name="requester_employee_id" value="<?= e((string) ($selectedRequester['id'] ?? '')) ?>" data-picker-value>
                <div class="picker-selected" data-picker-selected<?= $selectedRequester ? '' : ' hidden' ?>>
                    <span class="picker-selected-text">
                        <span class="picker-selected-label" data-picker-label><?= e($selectedRequester['name'] ?? '') ?></span>
                        <span class="text-muted text-sm" data-picker-meta><?= e($selectedRequester['meta'] ?? '') ?></span>
                    </span>
                    <button type="button" class="btn btn-ghost btn-sm picker-clear" data-picker-clear aria-label="Auswahl entfernen"><?= icon('x') ?></button>
                </div>
                <input id="f-requester" type="search" class="picker-input" placeholder="Name, Benutzername oder Personalnr. …" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" data-picker-input<?= $selectedRequester ? ' hidden' : '' ?>>
                <ul class="picker-results" role="listbox" hidden data-picker-results></ul>
                <p class="form-hint">Phonetische Suche – auch bei abweichender Schreibweise (z. B. „Meier“ statt „Maier“). Leer lassen für unbekannt/extern.</p>
                <?= field_error('requester_employee_id') ?>
            </div>
            <div class="form-group<?= has_error('affected_employee_id') ? ' has-error' : '' ?>">
                <label for="f-affected">Betroffener Mitarbeiter <span class="text-muted">(falls abweichend)</span></label>
                <select id="f-affected" name="affected_employee_id" data-hd-employee-assets="#f-assets">
                    <option value="">– wie Melder –</option>
                    <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= selected(form_value($row, 'affected_employee_id'), $emp['id']) ?>><?= e($emp['display_name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('affected_employee_id') ?>
            </div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('group_id') ? ' has-error' : '' ?>">
                <label for="f-group">Gruppe</label>
                <select id="f-group" name="group_id" data-hd-group="#f-assignee">
                    <option value="">– automatisch (Kategorie/Regel) –</option>
                    <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= selected(form_value($row, 'group_id'), $g['id']) ?>><?= e($g['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('group_id') ?>
            </div>
            <div class="form-group<?= has_error('assignee_user_id') ? ' has-error' : '' ?>">
                <label for="f-assignee">Bearbeiter</label>
                <select id="f-assignee" name="assignee_user_id" data-selected="<?= e(form_value($row, 'assignee_user_id')) ?>">
                    <option value="">– nicht zugewiesen –</option>
                    <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected(form_value($row, 'assignee_user_id'), $a['id']) ?>><?= e($a['display_name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('assignee_user_id') ?>
            </div>
            <div class="form-group<?= has_error('deputy_user_id') ? ' has-error' : '' ?>">
                <label for="f-deputy">Vertretung</label>
                <select id="f-deputy" name="deputy_user_id">
                    <option value="">– keine –</option>
                    <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected(form_value($row, 'deputy_user_id'), $a['id']) ?>><?= e($a['display_name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('deputy_user_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group<?= has_error('location_id') ? ' has-error' : '' ?>">
                <label for="f-location">Standort</label>
                <select id="f-location" name="location_id">
                    <option value="">– keiner –</option>
                    <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected(form_value($row, 'location_id'), $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('location_id') ?>
            </div>
            <div class="form-group<?= has_error('cost_center_id') ? ' has-error' : '' ?>">
                <label for="f-cc">Kostenstelle</label>
                <select id="f-cc" name="cost_center_id">
                    <option value="">– keine –</option>
                    <?php foreach ($costCenters as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected(form_value($row, 'cost_center_id'), $c['id']) ?>><?= e($c['number']) ?> – <?= e($c['description'] ?? $c['name'] ?? '') ?></option><?php endforeach; ?>
                </select>
                <?= field_error('cost_center_id') ?>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>Assets &amp; Tags</legend>
        <div class="form-row">
            <?php if ($isEdit): ?>
            <div class="form-group">
                <label>Verknüpfte Assets</label>
                <p class="form-hint"><?= $linkedAssets === [] ? 'Keine.' : e(implode(', ', array_map(static fn (array $la): string => (string) $la['inventory_number'], $linkedAssets))) ?> – Assets werden auf der <a href="/helpdesk/tickets/<?= (int) $ticket['id'] ?>#assets">Ticketseite</a> verwaltet.</p>
            </div>
            <?php else: ?>
            <div class="form-group<?= has_error('asset_id') ? ' has-error' : '' ?>">
                <label for="f-assets">Betroffene Assets <span class="text-muted">(Mehrfachauswahl mit Strg)</span></label>
                <select id="f-assets" name="asset_ids[]" multiple size="6" data-selected="<?= e(implode(',', $selectedAssets)) ?>">
                    <?php
                    $offered = $employeeAssets;
                    // Bereits verknüpfte Assets immer anbieten, auch wenn sie nicht (mehr) dem Melder gehören
                    $offeredIds = array_map(static fn (array $a): int => (int) $a['id'], $offered);
                    foreach ($linkedAssets as $la) {
                        if (!in_array((int) $la['asset_id'], $offeredIds, true)) {
                            $offered[] = ['id' => $la['asset_id'], 'inventory_number' => $la['inventory_number'], 'name' => $la['asset_name'] ?? $la['article_name'] ?? ''];
                        }
                    }
                    ?>
                    <?php foreach ($offered as $ea): ?><option value="<?= (int) $ea['id'] ?>"<?= in_array((int) $ea['id'], $selectedAssets, true) ? ' selected' : '' ?>><?= e($ea['inventory_number']) ?> · <?= e($ea['name'] ?? $ea['article_name'] ?? '') ?></option><?php endforeach; ?>
                </select>
                <p class="form-hint">Die Liste zeigt die Assets des Melders bzw. des betroffenen Mitarbeiters.</p>
                <?= field_error('asset_id') ?>
            </div>
            <?php endif; ?>
            <div class="form-group<?= has_error('tags') ? ' has-error' : '' ?>">
                <label for="f-tags">Tags <span class="text-muted">(kommagetrennt)</span></label>
                <input id="f-tags" name="tags" list="f-tags-list" data-hd-tags value="<?= e($tagValue) ?>" placeholder="drucker, vpn, onboarding">
                <datalist id="f-tags-list"></datalist>
                <?= field_error('tags') ?>
            </div>
        </div>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isEdit ? 'Änderungen speichern' : 'Ticket anlegen' ?></button>
        <a class="btn btn-secondary" href="<?= $isEdit ? '/helpdesk/tickets/' . (int) $ticket['id'] : '/helpdesk/tickets' ?>">Abbrechen</a>
    </div>
</form>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
