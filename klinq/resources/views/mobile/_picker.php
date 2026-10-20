<?php
/**
 * Autocomplete-Auswahl (Mitarbeiter/Standort). Erwartet: $name, $label, $searchUrl, $selected (['id','name','meta']|null);
 * optional $required (bool), $placeholder, $hint, $id
 */
$pid = $id ?? 'picker-' . $name;
?>
<div class="form-group picker<?= has_error($name) ? ' has-error' : '' ?>" data-picker data-search-url="<?= e($searchUrl) ?>">
    <label for="<?= e($pid) ?>"><?= e($label) ?><?= !empty($required) ? ' <span class="required">*</span>' : '' ?></label>
    <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) ($selected['id'] ?? '')) ?>" data-picker-value>
    <div class="picker-selected" data-picker-selected<?= $selected ? '' : ' hidden' ?>>
        <span class="picker-selected-text">
            <span class="picker-selected-label" data-picker-label><?= e($selected['name'] ?? '') ?></span>
            <span class="text-muted text-sm" data-picker-meta><?= e($selected['meta'] ?? '') ?></span>
        </span>
        <button type="button" class="btn btn-ghost btn-sm picker-clear" data-picker-clear aria-label="Auswahl entfernen"><?= icon('x') ?></button>
    </div>
    <input id="<?= e($pid) ?>" type="search" class="picker-input" placeholder="<?= e($placeholder ?? 'Suchen …') ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" data-picker-input<?= $selected ? ' hidden' : '' ?>>
    <ul class="picker-results" role="listbox" hidden data-picker-results></ul>
    <?php if (!empty($hint)): ?><span class="form-hint"><?= e($hint) ?></span><?php endif; ?>
    <?= field_error($name) ?>
</div>
