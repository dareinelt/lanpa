<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
use App\Services\Helpdesk\HelpdeskCalendarService as Cal;

$view = $range['view'];
$url = static fn (string $v, string $d): string => '/helpdesk/kalender?ansicht=' . rawurlencode($v) . '&datum=' . rawurlencode($d);
$eventUrl = static fn (array $e): string => '/helpdesk/kalender/' . (int) $e['id']
    . (!empty($e['is_series']) && (string) ($e['occurrence'] ?? '') !== '' ? '?termin=' . rawurlencode((string) $e['occurrence']) : '');
$newUrl = static fn (string $d, ?string $t = null): string => '/helpdesk/kalender/neu?datum=' . rawurlencode($d) . ($t !== null ? '&zeit=' . rawurlencode($t) : '&ganztags=1');
$isTodayInRange = in_array($today, $range['days'], true);
$weekdayShort = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$eventClass = static fn (array $e): string => 'hd-cal-event' . (!empty($e['ticket_id']) ? ' is-ticket' : '') . (!empty($e['is_series']) ? ' is-series' : '');
/** Kennzeichnung von Serienterminen (Vorkommen und abweichende Einzeltermine). */
$seriesMark = static fn (array $e): string => empty($e['is_series'])
    ? ''
    : '<span class="hd-cal-repeat" title="' . e('Serientermin: ' . Cal::recurrenceLabel($e)) . '">' . icon('refresh', 'icon icon-xs') . '</span>';
/** Stand der Checkliste (offen: hervorgehoben, künftig: neutral, erledigt: Häkchen). */
$checklistMark = static function (array $e): string {
    $total = (int) ($e['checklist_total'] ?? 0);
    if ($total === 0) {
        return '';
    }
    $done = (int) $e['checklist_confirmed'] >= $total;
    $state = $done ? 'is-done' : (!empty($e['checklist_future']) ? 'is-future' : 'is-open');

    return '<span class="hd-cal-checklist-mark ' . $state . '">' . icon($done ? 'check' : 'list', 'icon icon-xs')
        . (int) $e['checklist_confirmed'] . '/' . $total . '</span>';
};
$eventTitle = static function (array $e): string {
    $parts = [Cal::timeLabel($e), (string) $e['title']];
    if (!empty($e['location'])) {
        $parts[] = (string) $e['location'];
    }
    if (!empty($e['ticket_number'])) {
        $parts[] = 'Ticket ' . $e['ticket_number'];
    }
    if (!empty($e['is_series'])) {
        $parts[] = 'Serie: ' . Cal::recurrenceLabel($e);
    }
    if ((int) ($e['checklist_total'] ?? 0) > 0) {
        $parts[] = 'Checkliste ' . (int) $e['checklist_confirmed'] . '/' . (int) $e['checklist_total'] . ' bestätigt'
            . (!empty($e['checklist_future']) ? ' (erst am Termintag abzuarbeiten)' : '');
    }

    return implode(' · ', $parts);
};
$hasSeries = array_filter($events, static fn (array $e): bool => !empty($e['is_series'])) !== [];
?>
<div class="page-header">
    <div>
        <h1>Kalender</h1>
        <p class="page-subtitle text-muted mb-0">Termine des Help Desk für alle Agenten</p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
        <a class="btn btn-primary" data-hd-cal-new href="<?= e($newUrl($isTodayInRange ? $today : $range['date'], '09:00')) ?>"><?= icon('plus') ?> Neuer Termin</a>
    </div>
    <?php endif; ?>
</div>

<div class="card card-flush hd-cal" data-hd-cal data-today="<?= e($today) ?>">
    <div class="hd-cal-toolbar">
        <div class="hd-cal-nav">
            <a class="btn btn-secondary btn-sm" href="<?= e($url($view, $today)) ?>"<?= $isTodayInRange ? ' aria-current="date"' : '' ?>>Heute</a>
            <a class="btn btn-ghost btn-sm btn-icon" href="<?= e($url($view, $range['prev'])) ?>" aria-label="Zurück" title="Zurück"><?= icon('chevron-left') ?></a>
            <a class="btn btn-ghost btn-sm btn-icon" href="<?= e($url($view, $range['next'])) ?>" aria-label="Weiter" title="Weiter"><?= icon('chevron-right') ?></a>
            <h2 class="hd-cal-title"><?= e($range['title']) ?></h2>
        </div>
        <form method="get" action="/helpdesk/kalender" class="hd-cal-jump">
            <input type="hidden" name="ansicht" value="<?= e($view) ?>">
            <label class="hd-cal-sr" for="hd-cal-jump-date">Gehe zu Datum</label>
            <input type="date" id="hd-cal-jump-date" name="datum" value="<?= e($range['date']) ?>" data-hd-cal-jump>
        </form>
        <nav class="hd-cal-views" aria-label="Ansicht">
            <?php foreach (Cal::VIEWS as $key => $label): ?>
                <a class="hd-cal-view<?= $key === $view ? ' is-active' : '' ?>" href="<?= e($url($key, $range['date'])) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>

<?php if ($view === 'monat'):
    $month = substr($range['date'], 0, 7);
?>
    <div class="hd-cal-month" role="grid" aria-label="<?= e($range['title']) ?>">
        <div class="hd-cal-month-head" role="row">
            <?php foreach ($weekdayShort as $i => $wd): ?><div role="columnheader" class="<?= $i >= 5 ? 'is-weekend' : '' ?>"><?= e($wd) ?></div><?php endforeach; ?>
        </div>
        <?php foreach (array_chunk($range['days'], 7) as $week): ?>
        <div class="hd-cal-month-row" role="row">
            <?php foreach ($week as $i => $day):
                $dayEvents = Cal::onDay($events, $day, $timezone);
                $classes = 'hd-cal-month-cell' . (substr($day, 0, 7) !== $month ? ' is-outside' : '') . ($i >= 5 ? ' is-weekend' : '') . ($day === $today ? ' is-today' : '');
                $shown = array_slice($dayEvents, 0, 3);
                $more = count($dayEvents) - count($shown);
            ?>
            <div class="<?= e($classes) ?>" role="gridcell">
                <div class="hd-cal-month-day">
                    <a class="hd-cal-daynum" href="<?= e($url('tag', $day)) ?>" aria-label="<?= e(Cal::longDay($day)) ?>"><?= e(Cal::monthCellLabel($day)) ?></a>
                    <?php if ($canEdit): ?><a class="hd-cal-add" data-hd-cal-new href="<?= e($newUrl($day, '09:00')) ?>" aria-label="Neuer Termin am <?= e(Cal::longDay($day)) ?>" title="Neuer Termin"><?= icon('plus', 'icon icon-xs') ?></a><?php endif; ?>
                </div>
                <?php foreach ($shown as $ev): ?>
                    <a class="<?= e($eventClass($ev)) ?><?= !empty($ev['all_day']) ? ' is-allday' : '' ?>" href="<?= e($eventUrl($ev)) ?>" title="<?= e($eventTitle($ev)) ?>">
                        <?php if (empty($ev['all_day'])): ?><span class="hd-cal-event-time"><?= e($ev['start_local']->format('Y-m-d') === $day ? $ev['start_local']->format('H:i') : '…') ?></span><?php endif; ?>
                        <?= $seriesMark($ev) ?><span class="hd-cal-event-title"><?= e($ev['title']) ?></span><?= $checklistMark($ev) ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($more > 0): ?><a class="hd-cal-more" href="<?= e($url('tag', $day)) ?>">+<?= $more ?> weitere</a><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
<?php else:
    $columns = count($range['days']);
?>
    <div class="hd-cal-week hd-cal-cols-<?= $columns ?>">
        <div class="hd-cal-week-head">
            <div class="hd-cal-corner"></div>
            <?php foreach ($range['days'] as $day): ?>
                <a class="hd-cal-dayhead<?= $day === $today ? ' is-today' : '' ?>" href="<?= e($url('tag', $day)) ?>"<?= $day === $today ? ' aria-current="date"' : '' ?>>
                    <span class="hd-cal-dayhead-name"><?= e(Cal::weekdayName($day)) ?></span><span class="hd-cal-dayhead-short" aria-hidden="true"><?= e(mb_substr(Cal::weekdayName($day), 0, 2)) ?></span>
                    <span class="hd-cal-dayhead-num"><?= (int) substr($day, 8, 2) ?>.<?= (int) substr($day, 5, 2) ?>.</span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="hd-cal-allday">
            <div class="hd-cal-allday-label">Ganztägig</div>
            <?php foreach ($range['days'] as $day): ?>
                <div class="hd-cal-allday-cell<?= $day === $today ? ' is-today' : '' ?>">
                    <?php foreach (Cal::onDay($events, $day, $timezone, true) as $ev): ?>
                        <a class="<?= e($eventClass($ev)) ?> is-allday" href="<?= e($eventUrl($ev)) ?>" title="<?= e($eventTitle($ev)) ?>"><?= $seriesMark($ev) ?><span class="hd-cal-event-title"><?= e($ev['title']) ?></span><?= $checklistMark($ev) ?></a>
                    <?php endforeach; ?>
                    <?php if ($canEdit): ?><a class="hd-cal-allday-add" data-hd-cal-new href="<?= e($newUrl($day)) ?>" aria-label="Ganztägigen Termin am <?= e(Cal::longDay($day)) ?> anlegen"></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="hd-cal-scroll" data-hd-cal-scroll tabindex="0" aria-label="Tagesraster">
            <div class="hd-cal-body">
                <div class="hd-cal-hours" aria-hidden="true">
                    <?php for ($h = 0; $h < 24; $h++): ?><div class="hd-cal-hour-label"><?= $h > 0 ? sprintf('%02d:00', $h) : '' ?></div><?php endfor; ?>
                </div>
                <?php foreach ($range['days'] as $day): ?>
                <div class="hd-cal-day<?= $day === $today ? ' is-today' : '' ?>" data-day="<?= e($day) ?>">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <?php if ($canEdit): ?>
                            <a class="hd-cal-slot<?= $h < 7 || $h >= 19 ? ' is-off' : '' ?>" data-hd-cal-new href="<?= e($newUrl($day, sprintf('%02d:00', $h))) ?>" aria-label="Neuer Termin <?= e(Cal::shortDay($day)) ?> <?= sprintf('%02d:00', $h) ?>" tabindex="-1"></a>
                        <?php else: ?>
                            <div class="hd-cal-slot<?= $h < 7 || $h >= 19 ? ' is-off' : '' ?>"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php foreach (Cal::layoutDay($events, $day, $timezone) as $item): $ev = $item['event']; ?>
                        <a class="<?= e($eventClass($ev)) ?> is-timed<?= $item['height'] < 45 ? ' is-short' : '' ?><?= $ev['end_local'] < new \DateTimeImmutable('now', $timezone) ? ' is-past' : '' ?>" href="<?= e($eventUrl($ev)) ?>" title="<?= e($eventTitle($ev)) ?>"
                           data-top="<?= (int) $item['top'] ?>" data-height="<?= (int) $item['height'] ?>" data-col="<?= (int) $item['column'] ?>" data-cols="<?= (int) $item['columns'] ?>">
                            <span class="hd-cal-event-time"><?= e($item['continues_before'] ? '… ' . $ev['end_local']->format('H:i') : $ev['start_local']->format('H:i') . '–' . $ev['end_local']->format('H:i')) ?></span>
                            <span class="hd-cal-event-title"><?php if (!empty($ev['ticket_id'])): ?><?= icon('ticket', 'icon icon-xs') ?> <?php endif; ?><?= $seriesMark($ev) ?><?= e($ev['title']) ?></span>
                            <?php if (!empty($ev['location'])): ?><span class="hd-cal-event-meta"><?= e($ev['location']) ?></span><?php endif; ?>
                            <?= $checklistMark($ev) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
    <div class="hd-cal-legend text-xs text-muted">
        <span><span class="hd-cal-swatch"></span> Manuell eingetragen</span>
        <span><span class="hd-cal-swatch is-ticket"></span> Aus Ticket</span>
        <?php if ($hasSeries): ?><span><?= icon('refresh', 'icon icon-xs') ?> Serientermin</span><?php endif; ?>
        <?php if (array_filter($events, static fn (array $e): bool => (int) ($e['checklist_total'] ?? 0) > 0) !== []): ?><span><?= icon('list', 'icon icon-xs') ?> Checkliste (bestätigt/gesamt)</span><?php endif; ?>
        <?php if ($events === []): ?><span>· Keine Termine in diesem Zeitraum</span><?php endif; ?>
    </div>
</div>
<?php if ($canEdit):
    $values = $newValues;
    $prefix = 'ncal';
    $checklistItems = $newChecklistItems;
?>
<dialog id="hd-cal-new-dialog" class="dialog hd-cal-dialog" data-hd-cal-new-dialog aria-labelledby="hd-cal-new-title"<?= $newDialogOpen ? ' data-dialog-autoopen' : '' ?>>
    <form method="post" action="/helpdesk/kalender" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="dialog" value="neu">
        <input type="hidden" name="back" value="<?= e($url($view, $range['date'])) ?>">
        <div class="dialog-header">
            <h2 id="hd-cal-new-title"><?= icon('calendar') ?> Neuer Termin</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button>
        </div>
        <div class="dialog-body">
            <?php include __DIR__ . '/_fields.php'; ?>
            <?php include __DIR__ . '/_checklist_fields.php'; ?>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-secondary" data-dialog-close>Abbrechen</button>
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Termin anlegen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
