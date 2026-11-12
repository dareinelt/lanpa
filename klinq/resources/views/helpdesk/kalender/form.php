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

<?php if (!$isNew && $checklist !== null && $checklist['total'] > 0): $checklistDone = $checklist['confirmed'] === $checklist['total']; ?>
<section class="card card-form card-form-wide hd-cal-checklist<?= $checklist['is_future'] ? ' is-future' : '' ?>" aria-labelledby="hd-cal-checklist-title">
    <div class="hd-cal-checklist-head">
        <h2 id="hd-cal-checklist-title" class="card-title"><?= icon('list') ?> Checkliste</h2>
        <?= badge($checklistDone ? 'Erledigt' : $checklist['confirmed'] . '/' . $checklist['total'] . ' bestätigt', $checklistDone ? 'success' : ($checklist['is_future'] ? 'neutral' : 'warning')) ?>
    </div>
    <?php if ($isSeries || $checklist['occurrence'] !== ''): ?>
        <p class="text-muted text-xs mb-0">Gilt nur für den Termin am <?= e(\App\Services\Helpdesk\HelpdeskCalendarService::longDay($checklist['occurrence'] !== '' ? $checklist['occurrence'] : $backDate)) ?>.</p>
    <?php endif; ?>
    <?php if ($checklist['is_future']): ?>
        <p class="alert alert-info hd-cal-checklist-future"><?= icon('clock') ?> Der Termin liegt in der Zukunft. Die Checkliste kann erst am Termintag abgearbeitet werden.</p>
    <?php endif; ?>
    <form method="post" action="/helpdesk/kalender/<?= (int) $event['id'] ?>/checkliste">
        <?= csrf_field() ?>
        <input type="hidden" name="termin" value="<?= e($checklist['occurrence']) ?>">
        <ul class="hd-cal-checklist-items">
            <?php foreach ($checklist['items'] as $item): ?>
                <li class="hd-cal-checklist-item<?= $item['is_confirmed'] ? ' is-confirmed' : '' ?>">
                    <span class="hd-cal-checklist-state" aria-hidden="true"><?= icon($item['is_confirmed'] ? 'check' : 'clock', 'icon icon-sm') ?></span>
                    <span class="hd-cal-checklist-label">
                        <?= e($item['label']) ?>
                        <?php if ($item['is_confirmed']): ?>
                            <span class="hd-cal-checklist-by text-xs text-muted">Bestätigt von <?= e((string) ($item['confirmed_by_name'] ?? 'unbekannt')) ?> am <?= e($item['confirmed_local']->format('d.m.Y H:i')) ?> Uhr</span>
                        <?php else: ?>
                            <span class="hd-cal-checklist-by text-xs text-muted">Offen</span>
                        <?php endif; ?>
                    </span>
                    <?php if ($checklist['can_confirm']): ?>
                        <?php if ($item['is_confirmed']): ?>
                            <button type="submit" name="revoke" value="<?= (int) $item['id'] ?>" class="btn btn-ghost btn-sm">Bestätigung zurücknehmen</button>
                        <?php else: ?>
                            <button type="submit" name="confirm" value="<?= (int) $item['id'] ?>" class="btn btn-primary btn-sm"><?= icon('check') ?> Bestätigt</button>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </form>
</section>
<?php endif; ?>

<div class="card card-form card-form-wide">
    <?php if ($canEdit): ?>
    <form method="post" action="<?= $isNew ? '/helpdesk/kalender' : '/helpdesk/kalender/' . (int) $event['id'] ?>" novalidate>
        <?= csrf_field() ?>
        <?php include __DIR__ . '/_fields.php'; ?>
        <?php include __DIR__ . '/_checklist_fields.php'; ?>
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
