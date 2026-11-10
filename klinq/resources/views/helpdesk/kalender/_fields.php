<?php
/**
 * Formularfelder eines Termins (Kalenderformular und Dialog „Termin anlegen“ im Ticket).
 * Erwartet $values (title, date, end_date, start_time, end_time, all_day, location, description) und $prefix (ID-Präfix).
 * Das Umschalten „Ganztägig“ blendet die Uhrzeitfelder über public/js/helpdesk-calendar.js aus.
 */
$v = static fn (string $key): string => (string) ($values[$key] ?? '');
$allDay = $v('all_day') === '1';
?>
<div class="form-group<?= has_error('title') ? ' has-error' : '' ?>">
    <label for="<?= e($prefix) ?>-title">Titel <span class="required">*</span></label>
    <input id="<?= e($prefix) ?>-title" name="title" value="<?= e($v('title')) ?>" maxlength="200" required>
    <?= field_error('title') ?>
</div>
<div class="hd-cal-when" data-hd-cal-when>
    <div class="form-row">
        <div class="form-group<?= has_error('date') ? ' has-error' : '' ?>">
            <label for="<?= e($prefix) ?>-date">Beginn <span class="required">*</span></label>
            <div class="hd-cal-datetime">
                <input type="date" id="<?= e($prefix) ?>-date" name="date" value="<?= e($v('date')) ?>" required data-hd-cal-date>
                <input type="time" id="<?= e($prefix) ?>-start" name="start_time" value="<?= e($v('start_time')) ?>" step="300" aria-label="Beginnzeit" data-hd-cal-time<?= $allDay ? ' hidden disabled' : '' ?>>
            </div>
            <?= field_error('date') ?><?= field_error('start_time') ?>
        </div>
        <div class="form-group<?= has_error('end_date') || has_error('end_time') ? ' has-error' : '' ?>">
            <label for="<?= e($prefix) ?>-end-date">Ende</label>
            <div class="hd-cal-datetime">
                <input type="date" id="<?= e($prefix) ?>-end-date" name="end_date" value="<?= e($v('end_date') !== '' ? $v('end_date') : $v('date')) ?>" data-hd-cal-end-date>
                <input type="time" id="<?= e($prefix) ?>-end" name="end_time" value="<?= e($v('end_time')) ?>" step="300" aria-label="Endzeit" data-hd-cal-time data-hd-cal-end-time<?= $allDay ? ' hidden disabled' : '' ?>>
            </div>
            <?= field_error('end_date') ?><?= field_error('end_time') ?>
        </div>
    </div>
    <label class="checkbox-row"><input type="checkbox" name="all_day" value="1" data-hd-cal-allday<?= $allDay ? ' checked' : '' ?>> Ganztägig</label>
</div>
<div class="form-group<?= has_error('location') ? ' has-error' : '' ?>">
    <label for="<?= e($prefix) ?>-location">Ort <span class="text-muted">(optional)</span></label>
    <input id="<?= e($prefix) ?>-location" name="location" value="<?= e($v('location')) ?>" maxlength="200" placeholder="z. B. Raum 2.14, Station 3, Telefon">
    <?= field_error('location') ?>
</div>
<div class="form-group<?= has_error('description') ? ' has-error' : '' ?>">
    <label for="<?= e($prefix) ?>-description">Beschreibung <span class="text-muted">(optional)</span></label>
    <textarea id="<?= e($prefix) ?>-description" name="description" rows="4" maxlength="5000"><?= e($v('description')) ?></textarea>
    <?= field_error('description') ?>
</div>
