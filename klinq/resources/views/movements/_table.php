<?php
/** Bewegungstabelle. Erwartet: $rows; optional $showStatus (bool), $showMissing (bool), $emptyText */
$showStatus ??= true; $showMissing ??= false; $emptyText ??= 'Keine Bewegungen gefunden.';
$statusBadge = static fn (string $s): string => match ($s) {
    'open' => badge('Offen', 'warning'),
    'completed' => badge('Abgeschlossen', 'success'),
    'cancelled' => badge('Storniert', 'neutral'),
    default => badge($s),
};
?>
<div class="card card-flush">
<?php if (!$rows): ?>
    <div class="table-empty"><?= e($emptyText) ?></div>
<?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr>
            <th>Datum</th>
            <th>Vorgang</th>
            <th>Asset</th>
            <th>Mitarbeiter</th>
            <th>Standort</th>
            <th>Kostenst.</th>
            <?php if ($showMissing): ?><th>Fehlt</th><?php endif; ?>
            <?php if ($showStatus): ?><th>Status</th><?php endif; ?>
            <th>Quelle</th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $isCheckout = $r['type'] === 'checkout'; $missing = App\Services\MovementService::missingLabels($r); ?>
            <tr class="is-clickable<?= $r['status'] === 'cancelled' ? ' is-muted' : '' ?>" data-href="/movements/<?= (int) $r['id'] ?>">
                <td class="text-sm nowrap"><?= fmt_datetime($r['movement_at']) ?></td>
                <td class="nowrap"><?= icon($isCheckout ? 'checkout' : 'return', 'icon ' . ($isCheckout ? 'text-info' : 'text-success')) ?> <?= $isCheckout ? 'Entnahme' : 'Retoure' ?></td>
                <td><span class="mono"><strong><?= e($r['inventory_number']) ?></strong></span><br><span class="text-muted text-sm"><?= e($r['asset_type_name']) ?><?= $r['asset_name'] || $r['article_name'] ? ' · ' . e($r['asset_name'] ?: $r['article_name']) : '' ?></span></td>
                <td><?= $r['employee_name'] ? e($r['employee_name']) : '<span class="text-muted">–</span>' ?></td>
                <td class="text-sm"><?php if ($isCheckout): ?><?= e($r['to_location_path'] ?? '–') ?><?php else: ?><?= e($r['to_location_path'] ?? '–') ?><?php endif; ?><?php if ($r['from_location_path'] && $r['from_location_path'] !== $r['to_location_path']): ?><br><span class="text-muted text-xs">von <?= e($r['from_location_path']) ?></span><?php endif; ?></td>
                <td class="mono"><?= e($r['cost_center_number'] ?? '–') ?></td>
                <?php if ($showMissing): ?><td><?php if ($missing): ?><?php foreach ($missing as $m): ?><?= badge($m, 'warning') ?> <?php endforeach; ?><?php elseif ($r['status'] === 'open'): ?><span class="text-muted text-sm">Prüfung ausstehend</span><?php else: ?><span class="text-muted">–</span><?php endif; ?></td><?php endif; ?>
                <?php if ($showStatus): ?><td><?= $statusBadge($r['status']) ?><?php if (!$showMissing && $missing): ?> <span class="text-warning text-xs" title="<?= e(implode(', ', $missing)) ?>"><?= icon('warning', 'icon icon-sm') ?></span><?php endif; ?></td><?php endif; ?>
                <td class="text-sm text-muted"><?= e(App\Services\MovementService::SOURCES[$r['source']] ?? $r['source']) ?><br><span class="text-xs"><?= e($r['created_by_name'] ?? '') ?></span></td>
                <td class="table-actions"><a class="btn btn-ghost btn-sm" href="/movements/<?= (int) $r['id'] ?>" title="Öffnen"><?= icon($r['status'] === 'open' ? 'pen' : 'chevron-right') ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
