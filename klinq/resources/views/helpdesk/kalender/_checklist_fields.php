<?php
/**
 * Checkliste eines Termins (Kalenderformular und Dialog „Termin anlegen“ im Ticket).
 * Erwartet $prefix (ID-Präfix) und optional $checklistItems (list<array{id:?int,label:string}>).
 * Ohne JavaScript ist die Liste mit drei leeren Zeilen sichtbar; mit JavaScript (public/js/helpdesk-calendar.js)
 * blendet „Checkliste anlegen“ sie ein und Zeilen lassen sich hinzufügen und entfernen.
 */
$checklistRows = array_values($checklistItems ?? []);
$checklistMax = \App\Services\Helpdesk\HelpdeskCalendarService::MAX_CHECKLIST_ITEMS;
$checklistLabelMax = \App\Services\Helpdesk\HelpdeskCalendarService::MAX_CHECKLIST_LABEL;
$checklistRow = static function (string $index, ?int $id, string $label) use ($prefix, $checklistLabelMax): string {
    return '<li class="hd-cal-checklist-row" data-hd-cal-checklist-row>'
        . ($id !== null ? '<input type="hidden" name="checklist[' . e($index) . '][id]" value="' . $id . '">' : '')
        . '<input type="text" name="checklist[' . e($index) . '][label]" value="' . e($label) . '" maxlength="' . $checklistLabelMax . '"'
        . ' placeholder="z. B. Backup geprüft" aria-label="Checklistenpunkt" id="' . e($prefix) . '-checklist-' . e($index) . '">'
        . '<button type="button" class="btn btn-ghost btn-sm btn-icon" data-hd-cal-checklist-remove aria-label="Punkt entfernen" title="Punkt entfernen" hidden>'
        . icon('x') . '</button></li>';
};
?>
<div class="hd-cal-checklist-edit<?= has_error('checklist') ? ' has-error' : '' ?>" data-hd-cal-checklist data-max="<?= $checklistMax ?>">
    <input type="hidden" name="checklist_submitted" value="1">
    <button type="button" class="btn btn-secondary btn-sm" data-hd-cal-checklist-open hidden><?= icon('list') ?> Checkliste anlegen</button>
    <fieldset class="hd-cal-checklist-panel" data-hd-cal-checklist-panel>
        <legend>Checkliste <span class="text-muted">(optional)</span></legend>
        <p class="form-hint text-muted text-xs">Der bearbeitende Agent hakt jeden Punkt als „Bestätigt“ ab. Bei Serienterminen gelten die Punkte für jeden Termin der Serie und werden je Termin abgehakt.</p>
        <ol class="hd-cal-checklist-rows" data-hd-cal-checklist-rows>
            <?php foreach ($checklistRows as $i => $row): ?>
                <?= $checklistRow((string) $i, isset($row['id']) && $row['id'] !== null ? (int) $row['id'] : null, (string) $row['label']) ?>
            <?php endforeach; ?>
            <?php for ($i = count($checklistRows); $i < count($checklistRows) + 3 && $i < $checklistMax; $i++): ?>
                <?= $checklistRow((string) $i, null, '') ?>
            <?php endfor; ?>
        </ol>
        <template data-hd-cal-checklist-template><?= $checklistRow('__INDEX__', null, '') ?></template>
        <button type="button" class="btn btn-ghost btn-sm" data-hd-cal-checklist-add hidden><?= icon('plus') ?> Punkt hinzufügen</button>
        <?= field_error('checklist') ?>
    </fieldset>
</div>
