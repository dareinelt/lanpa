<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$fmtValue = static function (mixed $v): string {
    if ($v === null || $v === '') {
        return '<span class="text-muted">–</span>';
    }
    if (is_bool($v)) {
        return $v ? 'ja' : 'nein';
    }
    if (is_array($v)) {
        return '<code>' . e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code>';
    }

    return e((string) $v);
};
$actionColor = static fn (string $a): string => match ($a) {
    'create', 'checkout', 'receive', 'assign', 'activate', 'import', 'login' => 'success',
    'delete', 'cancel', 'deactivate', 'login_failed', 'retire' => 'danger',
    'status', 'return', 'release', 'complete' => 'info',
    default => 'neutral',
};
$objectLink = static function (array $entry) use ($objectLinks): ?string {
    $t = (string) $entry['object_type'];
    return isset($objectLinks[$t]) && $entry['object_id'] ? sprintf($objectLinks[$t], (int) $entry['object_id']) : null;
};
?>
<div class="page-header">
    <div>
        <h1>Audit-Log</h1>
        <p class="page-subtitle text-muted mb-0">Wer hat wann was geändert – alle Anlagen, Änderungen, Bewegungen und Anmeldungen mit alten und neuen Werten.</p>
    </div>
</div>

<form method="get" action="/audit" class="filter-bar" role="search">
    <div class="form-group">
        <label for="filter-from">Von</label>
        <input id="filter-from" type="date" name="from" value="<?= e($filters['from']) ?>">
    </div>
    <div class="form-group">
        <label for="filter-to">Bis</label>
        <input id="filter-to" type="date" name="to" value="<?= e($filters['to']) ?>">
    </div>
    <div class="form-group">
        <label for="filter-object">Objekt</label>
        <select id="filter-object" name="object_type" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($objectTypes as $t): ?><option value="<?= e($t) ?>"<?= selected($filters['object_type'], $t) ?>><?= e($objectLabels[$t] ?? $t) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-action">Aktion</label>
        <select id="filter-action" name="action" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= selected($filters['action'], $a) ?>><?= e($actionLabels[$a] ?? $a) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-user">Benutzer</label>
        <select id="filter-user" name="username" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($usernames as $u): ?><option value="<?= e($u) ?>"<?= selected($filters['username'], $u) ?>><?= e($u) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Benutzer, Objekt, Bezeichnung …">
    </div>
    <?php if ($filters['object_id']): ?><input type="hidden" name="object_id" value="<?= (int) $filters['object_id'] ?>"><?php endif; ?>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <a class="btn btn-ghost" href="/audit"><?= icon('x') ?> Zurücksetzen</a>
</form>

<section class="card card-flush">
    <div class="card-header"><h2>Einträge</h2><span class="text-muted text-sm"><?= number_format($paginator->total, 0, ',', '.') ?> Einträge</span></div>
    <?php if (!$entries): ?>
        <div class="table-empty">Keine Einträge für diese Filter.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table table-compact audit-table">
        <thead><tr><th>Zeitpunkt</th><th>Benutzer</th><th>Aktion</th><th>Objekt</th><th>Änderungen</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <?php
            $old = $entry['old_data'] !== null ? (json_decode((string) $entry['old_data'], true) ?: []) : null;
            $new = $entry['new_data'] !== null ? (json_decode((string) $entry['new_data'], true) ?: []) : null;
            $keys = array_values(array_unique(array_merge(array_keys($old ?? []), array_keys($new ?? []))));
            $link = $objectLink($entry);
            ?>
            <tr>
                <td class="nowrap"><?= fmt_datetime($entry['created_at']) ?><?php if ($entry['ip_address']): ?><br><span class="text-muted text-xs mono"><?= e($entry['ip_address']) ?></span><?php endif; ?></td>
                <td><?= e($entry['username']) ?></td>
                <td><?= badge($actionLabels[$entry['action']] ?? $entry['action'], $actionColor((string) $entry['action'])) ?></td>
                <td>
                    <span class="text-muted text-xs"><?= e($objectLabels[$entry['object_type']] ?? $entry['object_type']) ?><?= $entry['object_id'] ? ' #' . (int) $entry['object_id'] : '' ?></span><br>
                    <?php if ($link): ?><a href="<?= e($link) ?>"><?= e($entry['object_label'] ?? '–') ?></a><?php else: ?><?= e($entry['object_label'] ?? '–') ?><?php endif; ?>
                </td>
                <td>
                    <?php if ($keys === []): ?>
                        <span class="text-muted">–</span>
                    <?php elseif ($old === null || $new === null): ?>
                        <dl class="audit-diff">
                            <?php $shown = 0; foreach (($new ?? $old) as $k => $v): if ($v === null || $v === '') { continue; } $shown++; ?><dt><?= e((string) $k) ?></dt><dd><?= $fmtValue($v) ?></dd><?php endforeach; ?>
                            <?php if ($shown === 0): ?><dt>–</dt><dd><span class="text-muted">keine Werte</span></dd><?php endif; ?>
                        </dl>
                    <?php else: ?>
                        <dl class="audit-diff">
                            <?php foreach ($keys as $k): ?>
                                <dt><?= e((string) $k) ?></dt>
                                <dd><span class="audit-old"><?= $fmtValue($old[$k] ?? null) ?></span> <?= icon('arrow-right', 'icon icon-xs') ?> <span class="audit-new"><?= $fmtValue($new[$k] ?? null) ?></span></dd>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
