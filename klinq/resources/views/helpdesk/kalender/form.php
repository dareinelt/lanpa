<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$isNew = $event === null;
// Beim Serientermin ist `occurrence` der bearbeitete Termintag, sonst der Beginn des Termins.
$day = $isNew ? '' : (string) ($values['occurrence'] ?? '');
$backDate = $isNew ? (string) ($values['date'] ?? '') : ($day !== '' ? $day : $event['start_local']->format('Y-m-d'));
$backUrl = '/helpdesk/kalender' . ($backDate !== '' ? '?datum=' . rawurlencode($backDate) : '');
$prefix = 'cal';
$isSeries = !$isNew && (string) ($event['recurrence'] ?? '') !== '';
$scope = (string) ($values['scope'] ?? \App\Services\Helpdesk\HelpdeskCalendarService::SCOPE_SERIES);
$dayLabel = $backDate !== '' ? \App\Services\Helpdesk\HelpdeskCalendarService::longDay($backDate) : '';
?>
<div class="page-header">
    <div>
        <h1><?= $isNew ? 'Neuer Termin' : e($event['title']) ?></h1>
        <p class="page-subtitle text-muted mb-0">
            <?php if ($isNew): ?>
                Termin im Help-Desk-Kalender eintragen
            <?php else: ?>
                <?= e(\App\Services\Helpdesk\HelpdeskCalendarService::longDay($backDate)) ?> · <?= e(\App\Services\Helpdesk\HelpdeskCalendarService::timeLabel($event, $backDate)) ?>
                · eingetragen von <?= e($event['created_by_name'] ?? 'system') ?>
            <?php endif; ?>
        </p>
        <?php if ($isSeries): ?>
            <p class="page-subtitle text-muted mb-0 hd-cal-series-note">
                <?= icon('refresh', 'icon icon-sm') ?> Serientermin · <?= e(\App\Services\Helpdesk\HelpdeskCalendarService::recurrenceLabel($event)) ?>
            </p>
        <?php endif; ?>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($backUrl) ?>"><?= icon('arrow-left') ?> Zum Kalender</a>
    </div>
</div>

<?php if (!$isNew && !empty($event['ticket_id'])): ?>
    <div class="alert alert-info"><?= icon('ticket') ?> Aus Ticket <a href="/helpdesk/tickets/<?= (int) $event['ticket_id'] ?>"><?= e($event['ticket_number'] ?? ('#' . (int) $event['ticket_id'])) ?></a><?= !empty($event['ticket_subject']) ? ' · ' . e($event['ticket_subject']) : '' ?></div>
<?php endif; ?>

<div class="card card-form card-form-wide">
    <?php if ($canEdit): ?>
    <form method="post" action="<?= $isNew ? '/helpdesk/kalender' : '/helpdesk/kalender/' . (int) $event['id'] ?>" novalidate>
        <?= csrf_field() ?>
        <?php include __DIR__ . '/_fields.php'; ?>
        <?php if ($isSeries): ?>
            <input type="hidden" name="occurrence" value="<?= e($day) ?>">
            <fieldset class="hd-cal-scope" data-hd-cal-scope>
                <legend>Änderung anwenden auf</legend>
                <div class="hd-cal-scope-options">
                    <label class="hd-cal-scope-option">
                        <input type="radio" name="scope" value="<?= \App\Services\Helpdesk\HelpdeskCalendarService::SCOPE_OCCURRENCE ?>"<?= $scope === \App\Services\Helpdesk\HelpdeskCalendarService::SCOPE_OCCURRENCE ? ' checked' : '' ?>>
                        <span>
                            <span class="hd-cal-scope-title">Nur dieser Termin</span>
                            <span class="hd-cal-scope-text"><?= e($dayLabel) ?> abweichend speichern; die übrigen Termine der Serie bleiben unverändert</span>
                        </span>
                    </label>
                    <label class="hd-cal-scope-option">
                        <input type="radio" name="scope" value="<?= \App\Services\Helpdesk\HelpdeskCalendarService::SCOPE_SERIES ?>"<?= $scope === \App\Services\Helpdesk\HelpdeskCalendarService::SCOPE_SERIES ? ' checked' : '' ?>>
                        <span>
                            <span class="hd-cal-scope-title">Alle Termine der Serie</span>
                            <span class="hd-cal-scope-text">Uhrzeit, Dauer und Wiederholung für die gesamte Serie übernehmen; abweichende Einzeltermine entfallen</span>
                        </span>
                    </label>
                </div>
                <p class="form-hint text-muted text-xs">Der erste Termin der Serie bleibt bestehen, auch wenn ein späterer Termin bearbeitet wurde.</p>
            </fieldset>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isNew ? 'Termin anlegen' : 'Speichern' ?></button>
            <a class="btn btn-secondary" href="<?= e($backUrl) ?>">Abbrechen</a>
        </div>
    </form>
    <?php else: ?>
        <dl class="hd-cal-readonly">
            <dt>Zeit</dt><dd><?= e(\App\Services\Helpdesk\HelpdeskCalendarService::timeLabel($event, $backDate)) ?></dd>
            <?php if ($isSeries): ?><dt>Wiederholung</dt><dd><?= e(\App\Services\Helpdesk\HelpdeskCalendarService::recurrenceLabel($event)) ?></dd><?php endif; ?>
            <?php if (!empty($event['location'])): ?><dt>Ort</dt><dd><?= e($event['location']) ?></dd><?php endif; ?>
            <?php if (!empty($event['description'])): ?><dt>Beschreibung</dt><dd class="pre-wrap"><?= e($event['description']) ?></dd><?php endif; ?>
        </dl>
    <?php endif; ?>
</div>

<?php if (!$isNew && $canEdit && !$isSeries): ?>
<form method="post" action="/helpdesk/kalender/<?= (int) $event['id'] ?>/loeschen" class="hd-cal-delete" data-confirm="Diesen Termin wirklich löschen?">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash') ?> Termin löschen</button>
</form>
<?php elseif ($isSeries && $canEdit): ?>
<div class="hd-cal-delete">
    <form method="post" action="/helpdesk/kalender/<?= (int) $event['id'] ?>/loeschen" data-confirm="Nur den Termin am <?= e($dayLabel) ?> absagen? Die übrigen Termine der Serie bleiben erhalten.">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="<?= \App\Services\Helpdesk\HelpdeskCalendarService::SCOPE_OCCURRENCE ?>">
        <input type="hidden" name="termin" value="<?= e($day) ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= icon('calendar') ?> Nur diesen Termin absagen</button>
    </form>
    <form method="post" action="/helpdesk/kalender/<?= (int) $event['id'] ?>/loeschen" data-confirm="Die gesamte Serie mit allen Terminen wirklich löschen?">
        <?= csrf_field() ?>
        <input type="hidden" name="scope" value="<?= \App\Services\Helpdesk\HelpdeskCalendarService::SCOPE_SERIES ?>">
        <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash') ?> Ganze Serie löschen</button>
    </form>
</div>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
