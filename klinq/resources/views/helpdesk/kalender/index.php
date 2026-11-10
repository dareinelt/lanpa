<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
use App\Services\Helpdesk\HelpdeskCalendarService as Cal;

$view = $range['view'];
$url = static fn (string $v, string $d): string => '/helpdesk/kalender?ansicht=' . rawurlencode($v) . '&datum=' . rawurlencode($d);
$eventUrl = static fn (array $e): string => '/helpdesk/kalender/' . (int) $e['id'];
$newUrl = static fn (string $d, ?string $t = null): string => '/helpdesk/kalender/neu?datum=' . rawurlencode($d) . ($t !== null ? '&zeit=' . rawurlencode($t) : '&ganztags=1');
$isTodayInRange = in_array($today, $range['days'], true);
$weekdayShort = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$eventClass = static fn (array $e): string => 'hd-cal-event' . (!empty($e['ticket_id']) ? ' is-ticket' : '');
$eventTitle = static function (array $e): string {
    $parts = [Cal::timeLabel($e), (string) $e['title']];
    if (!empty($e['location'])) {
        $parts[] = (string) $e['location'];
    }
    if (!empty($e['ticket_number'])) {
        $parts[] = 'Ticket ' . $e['ticket_number'];
    }

    return implode(' · ', $parts);
};
?>
<div class="page-header">
    <div>
        <h1>Kalender</h1>
        <p class="page-subtitle text-muted mb-0">Termine des Help Desk für alle Agenten</p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e($newUrl($isTodayInRange ? $today : $range['date'], '09:00')) ?>"><?= icon('plus') ?> Neuer Termin</a>
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
                    <?php if ($canEdit): ?><a class="hd-cal-add" href="<?= e($newUrl($day, '09:00')) ?>" aria-label="Neuer Termin am <?= e(Cal::longDay($day)) ?>" title="Neuer Termin"><?= icon('plus', 'icon icon-xs') ?></a><?php endif; ?>
                </div>
                <?php foreach ($shown as $ev): ?>
                    <a class="<?= e($eventClass($ev)) ?><?= !empty($ev['all_day']) ? ' is-allday' : '' ?>" href="<?= e($eventUrl($ev)) ?>" title="<?= e($eventTitle($ev)) ?>">
                        <?php if (empty($ev['all_day'])): ?><span class="hd-cal-event-time"><?= e($ev['start_local']->format('Y-m-d') === $day ? $ev['start_local']->format('H:i') : '…') ?></span><?php endif; ?>
                        <span class="hd-cal-event-title"><?= e($ev['title']) ?></span>
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
                        <a class="<?= e($eventClass($ev)) ?> is-allday" href="<?= e($eventUrl($ev)) ?>" title="<?= e($eventTitle($ev)) ?>"><span class="hd-cal-event-title"><?= e($ev['title']) ?></span></a>
                    <?php endforeach; ?>
                    <?php if ($canEdit): ?><a class="hd-cal-allday-add" href="<?= e($newUrl($day)) ?>" aria-label="Ganztägigen Termin am <?= e(Cal::longDay($day)) ?> anlegen"></a><?php endif; ?>
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
                            <a class="hd-cal-slot<?= $h < 7 || $h >= 19 ? ' is-off' : '' ?>" href="<?= e($newUrl($day, sprintf('%02d:00', $h))) ?>" aria-label="Neuer Termin <?= e(Cal::shortDay($day)) ?> <?= sprintf('%02d:00', $h) ?>" tabindex="-1"></a>
                        <?php else: ?>
                            <div class="hd-cal-slot<?= $h < 7 || $h >= 19 ? ' is-off' : '' ?>"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php foreach (Cal::layoutDay($events, $day, $timezone) as $item): $ev = $item['event']; ?>
                        <a class="<?= e($eventClass($ev)) ?> is-timed<?= $item['height'] < 45 ? ' is-short' : '' ?><?= $ev['end_local'] < new \DateTimeImmutable('now', $timezone) ? ' is-past' : '' ?>" href="<?= e($eventUrl($ev)) ?>" title="<?= e($eventTitle($ev)) ?>"
                           data-top="<?= (int) $item['top'] ?>" data-height="<?= (int) $item['height'] ?>" data-col="<?= (int) $item['column'] ?>" data-cols="<?= (int) $item['columns'] ?>">
                            <span class="hd-cal-event-time"><?= e($item['continues_before'] ? '… ' . $ev['end_local']->format('H:i') : $ev['start_local']->format('H:i') . '–' . $ev['end_local']->format('H:i')) ?></span>
                            <span class="hd-cal-event-title"><?php if (!empty($ev['ticket_id'])): ?><?= icon('ticket', 'icon icon-xs') ?> <?php endif; ?><?= e($ev['title']) ?></span>
                            <?php if (!empty($ev['location'])): ?><span class="hd-cal-event-meta"><?= e($ev['location']) ?></span><?php endif; ?>
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
        <?php if ($events === []): ?><span>· Keine Termine in diesem Zeitraum</span><?php endif; ?>
    </div>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
