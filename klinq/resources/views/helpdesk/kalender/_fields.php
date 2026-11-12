<?php
/**
 * Formularfelder eines Termins (Kalenderformular und Dialog „Termin anlegen“ im Ticket).
 * Erwartet $values (title, date, end_date, start_time, end_time, all_day, location, description, recurrence,
 * recurrence_interval, recurrence_until) und $prefix (ID-Präfix).
 * Das Umschalten „Ganztägig“ blendet die Uhrzeitfelder über public/js/helpdesk-calendar.js aus,
 * die Auswahl einer Wiederholung blendet Abstand und Serienende ein.
 */
$v = static fn (string $key): string => (string) ($values[$key] ?? '');
$allDay = $v('all_day') === '1';
$recurrence = $v('recurrence');
$units = ['daily' => 'Tage', 'weekly' => 'Wochen', 'monthly' => 'Monate', 'yearly' => 'Jahre'];
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
<div class="hd-cal-series" data-hd-cal-series>
    <div class="form-row">
        <div class="form-group<?= has_error('recurrence') ? ' has-error' : '' ?>">
            <label for="<?= e($prefix) ?>-recurrence">Wiederholung</label>
            <select id="<?= e($prefix) ?>-recurrence" name="recurrence" data-hd-cal-recurrence>
                <option value="">Einmaliger Termin</option>
                <?php foreach (\App\Services\Helpdesk\HelpdeskCalendarService::RECURRENCES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $key === $recurrence ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('recurrence') ?>
        </div>
        <div class="form-group<?= has_error('recurrence_interval') ? ' has-error' : '' ?>" data-hd-cal-series-extra<?= $recurrence === '' ? ' hidden' : '' ?>>
            <label for="<?= e($prefix) ?>-recurrence-interval">Alle</label>
            <div class="hd-cal-interval">
                <input type="number" id="<?= e($prefix) ?>-recurrence-interval" name="recurrence_interval" min="1" max="99" step="1" value="<?= e($v('recurrence_interval') !== '' ? $v('recurrence_interval') : '1') ?>" data-hd-cal-interval>
                <span class="text-muted" data-hd-cal-interval-unit><?= e($units[$recurrence] ?? 'Wochen') ?></span>
            </div>
            <?= field_error('recurrence_interval') ?>
        </div>
        <div class="form-group<?= has_error('recurrence_until') ? ' has-error' : '' ?>" data-hd-cal-series-extra<?= $recurrence === '' ? ' hidden' : '' ?>>
            <label for="<?= e($prefix) ?>-recurrence-until">Serie endet am</label>
            <input type="date" id="<?= e($prefix) ?>-recurrence-until" name="recurrence_until" value="<?= e($v('recurrence_until')) ?>" data-hd-cal-until>
            <span class="form-hint text-muted text-xs">Leer lassen für eine unbefristete Serie</span>
            <?= field_error('recurrence_until') ?>
        </div>
    </div>
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
