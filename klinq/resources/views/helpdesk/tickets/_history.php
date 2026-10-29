<?php
/**
 * Verlauf eines Tickets. Erwartet: $timeline (chronologisch aufsteigende Ereignisse).
 *
 * Kurz aufeinanderfolgende Aktionen desselben Benutzers (z. B. Ticket ansehen und
 * kommentieren) fasst App\Support\TimelineGrouper zu einem Eintrag zusammen. Die übrigen
 * Ereignisse der Gruppe klappt das „+“ am rechten Rand auf.
 */
require_once __DIR__ . '/../../partials/helpers.php';

$eventLabels = [
    'created' => 'Ticket angelegt', 'status_changed' => 'Status geändert', 'reopened' => 'Wieder geöffnet', 'resolved' => 'Gelöst', 'closed' => 'Geschlossen', 'cancelled' => 'Storniert',
    'assigned' => 'Zugewiesen', 'unassigned' => 'Zuweisung entfernt', 'group_changed' => 'Gruppe geändert', 'deputy_changed' => 'Vertretung geändert', 'field_changed' => 'Feld geändert',
    'comment' => 'Kommentar', 'internal_note' => 'Interne Notiz', 'attachment_added' => 'Anhang hinzugefügt', 'attachment_removed' => 'Anhang entfernt',
    'asset_linked' => 'Asset verknüpft', 'asset_unlinked' => 'Asset-Verknüpfung entfernt', 'tags_changed' => 'Tags geändert', 'watcher_added' => 'Beobachter hinzugefügt',
    'worklog_added' => 'Arbeitszeit erfasst', 'worklog_removed' => 'Arbeitszeit gelöscht', 'relation_added' => 'Beziehung angelegt', 'relation_removed' => 'Beziehung entfernt',
    'merged' => 'Zusammengeführt', 'merge_received' => 'Ticket aufgenommen', 'escalated' => 'Eskaliert', 'sla_warning' => 'SLA-Warnung', 'sla_breached' => 'SLA verletzt',
    'knowledge_linked' => 'Wissensartikel verknüpft', 'knowledge_created' => 'Wissensartikel erstellt', 'rule_applied' => 'Regel angewendet', 'notification' => 'Benachrichtigung',
    'viewed' => 'Ticket angesehen',
];
$eventClass = static fn (string $t): string => match ($t) { 'sla_breached', 'escalated', 'cancelled' => ' is-danger', 'resolved', 'closed' => ' is-success', 'sla_warning', 'reopened' => ' is-warning', default => '' };
$renderEvent = static function (array $ev) use ($eventLabels): string {
    $html = '<span class="timeline-time">' . fmt_datetime($ev['created_at']) . ' · ' . e($ev['user_name']) . '</span>';
    $html .= '<span class="timeline-title">' . e($eventLabels[$ev['type']] ?? ucfirst(str_replace('_', ' ', (string) $ev['type'])));
    if (!empty($ev['field']) && !in_array($ev['type'], ['status_changed', 'assigned', 'unassigned', 'tags_changed'], true)) {
        $html .= ' <span class="text-muted">(' . e($ev['field']) . ')</span>';
    }
    $html .= '</span>';
    if ($ev['old_value'] !== null || $ev['new_value'] !== null) {
        $html .= '<span class="timeline-detail">';
        if ($ev['old_value'] !== null && $ev['new_value'] !== null) {
            $html .= '<s class="text-muted">' . e($ev['old_value']) . '</s> → <strong>' . e($ev['new_value']) . '</strong>';
        } elseif ($ev['new_value'] !== null) {
            $html .= e($ev['new_value']);
        } else {
            $html .= '<s class="text-muted">' . e($ev['old_value']) . '</s>';
        }
        $html .= '</span>';
    }

    return $html;
};
?>
<?php if ($timeline === []): ?>
    <p class="empty-state">Kein Verlauf.</p>
<?php else: ?>
<ul class="timeline">
    <?php foreach (array_reverse(\App\Support\TimelineGrouper::group($timeline)) as $group): ?>
    <?php
    $newest = $group[count($group) - 1];
    $older = array_reverse(array_slice($group, 0, -1));
    ?>
    <li class="<?= trim($eventClass((string) $newest['type'])) ?>">
        <span class="timeline-dot"></span>
        <?php if ($older === []): ?>
            <?= $renderEvent($newest) ?>
        <?php else: ?>
        <details class="timeline-group">
            <summary class="timeline-group-summary">
                <?= $renderEvent($newest) ?>
                <span class="timeline-group-toggle" aria-hidden="true">
                    <span class="timeline-group-more">+<?= count($older) ?></span>
                    <span class="timeline-group-close">−</span>
                </span>
            </summary>
            <ul class="timeline-entries">
                <?php foreach ($older as $ev): ?>
                <li class="<?= trim($eventClass((string) $ev['type'])) ?>"><?= $renderEvent($ev) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
