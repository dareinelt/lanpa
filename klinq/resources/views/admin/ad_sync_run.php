<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $d = $run['details']; ?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/admin/ad-sync">AD-Synchronisation</a></p>
        <h1 class="page-title">Lauf #<?= (int) $run['id'] ?> <?= match ($run['status']) { 'success' => badge('Erfolgreich', 'success'), 'failed' => badge('Fehlgeschlagen', 'danger'), default => badge('Läuft', 'info') } ?> <?= !empty($d['dry_run']) ? badge('Testlauf', 'warning') : '' ?></h1>
        <p class="text-muted mb-0"><?= fmt_datetime($run['started_at']) ?> – <?= fmt_datetime($run['finished_at']) ?> · ausgelöst von <?= e($run['triggered_by']) ?></p>
    </div>
    <div class="page-actions"><a class="btn btn-ghost" href="/admin/ad-sync"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<?php if ($run['message']): ?><div class="alert <?= $run['status'] === 'failed' ? 'alert-error' : 'alert-info' ?>"><?= e($run['message']) ?></div><?php endif; ?>
<div class="grid grid-5">
    <div class="card stat-card"><span class="stat-value"><?= (int) $run['total_count'] ?></span><span class="stat-label">Gelesen</span></div>
    <div class="card stat-card is-success"><span class="stat-value"><?= (int) $run['created_count'] ?></span><span class="stat-label">Neu</span></div>
    <div class="card stat-card is-info"><span class="stat-value"><?= (int) $run['updated_count'] ?></span><span class="stat-label">Aktualisiert</span></div>
    <div class="card stat-card is-warning"><span class="stat-value"><?= (int) $run['deactivated_count'] ?></span><span class="stat-label">Deaktiviert</span></div>
    <div class="card stat-card <?= (int) $run['error_count'] > 0 ? 'is-danger' : '' ?>"><span class="stat-value"><?= (int) $run['error_count'] ?></span><span class="stat-label">Fehler</span></div>
</div>
<div class="grid grid-2 mt-4">
<?php foreach ([['created', 'Neu angelegt'], ['updated', 'Aktualisiert'], ['adopted', 'Manuelle Datensätze übernommen'], ['deactivated', 'Deaktiviert'], ['reactivated', 'Reaktiviert'], ['errors', 'Fehler']] as [$key, $label]): $items = (array) ($d[$key] ?? []); if (!$items) continue; ?>
    <div class="card card-flush">
        <div class="card-header"><h2><?= $label ?> <span class="badge badge-neutral"><?= count($items) ?></span></h2></div>
        <ul class="task-list <?= $key === 'errors' ? 'text-danger' : '' ?>"><?php foreach ($items as $i): ?><li><?= e((string) $i) ?></li><?php endforeach; ?></ul>
    </div>
<?php endforeach; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
