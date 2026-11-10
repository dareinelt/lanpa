<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$isNew = $event === null;
$backDate = $isNew ? (string) ($values['date'] ?? '') : $event['start_local']->format('Y-m-d');
$backUrl = '/helpdesk/kalender' . ($backDate !== '' ? '?datum=' . rawurlencode($backDate) : '');
$prefix = 'cal';
?>
<div class="page-header">
    <div>
        <h1><?= $isNew ? 'Neuer Termin' : e($event['title']) ?></h1>
        <p class="page-subtitle text-muted mb-0">
            <?php if ($isNew): ?>
                Termin im Help-Desk-Kalender eintragen
            <?php else: ?>
                <?= e(\App\Services\Helpdesk\HelpdeskCalendarService::longDay($event['start_local']->format('Y-m-d'))) ?> · <?= e(\App\Services\Helpdesk\HelpdeskCalendarService::timeLabel($event)) ?>
                · eingetragen von <?= e($event['created_by_name'] ?? 'system') ?>
            <?php endif; ?>
        </p>
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
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isNew ? 'Termin anlegen' : 'Speichern' ?></button>
            <a class="btn btn-secondary" href="<?= e($backUrl) ?>">Abbrechen</a>
        </div>
    </form>
    <?php else: ?>
        <dl class="hd-cal-readonly">
            <dt>Zeit</dt><dd><?= e(\App\Services\Helpdesk\HelpdeskCalendarService::timeLabel($event)) ?></dd>
            <?php if (!empty($event['location'])): ?><dt>Ort</dt><dd><?= e($event['location']) ?></dd><?php endif; ?>
            <?php if (!empty($event['description'])): ?><dt>Beschreibung</dt><dd class="pre-wrap"><?= e($event['description']) ?></dd><?php endif; ?>
        </dl>
    <?php endif; ?>
</div>

<?php if (!$isNew && $canEdit): ?>
<form method="post" action="/helpdesk/kalender/<?= (int) $event['id'] ?>/loeschen" class="hd-cal-delete" data-confirm="Diesen Termin wirklich löschen?">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger btn-sm"><?= icon('trash') ?> Termin löschen</button>
</form>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
