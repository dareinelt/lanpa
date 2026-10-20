<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div>
        <p class="breadcrumb mb-0"><a href="/admin">Administration</a> › Benutzer</p>
        <h1>Benutzer</h1>
        <p class="page-subtitle text-muted mb-0"><?= count($rows) ?> Konten</p>
    </div>
    <div class="page-actions"><a class="btn btn-primary" href="/admin/users/new"><?= icon('plus') ?> Benutzer anlegen</a></div>
</div>
<form method="get" action="/admin/users" class="filter-bar" role="search">
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Benutzername, Anzeigename, E-Mail …">
    </div>
    <div class="form-group">
        <label for="filter-role">Rolle</label>
        <select id="filter-role" name="role" data-autosubmit>
            <option value="">Alle Rollen</option>
            <?php foreach ($roleLabels as $name => $label): ?>
                <option value="<?= e($name) ?>"<?= selected($filters['role'], $name) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status" data-autosubmit>
            <option value="active"<?= selected($filters['status'], 'active') ?>>Aktiv</option>
            <option value="inactive"<?= selected($filters['status'], 'inactive') ?>>Inaktiv</option>
            <option value="all"<?= $filters['status'] === '' ? ' selected' : '' ?>>Alle</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
</form>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Benutzer gefunden.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Benutzername</th><th>Anzeigename</th><th>E-Mail</th><th>Rolle</th><th>Gruppen</th><th>Quelle</th><th>Letzte Anmeldung</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="is-clickable <?= (int) $r['is_active'] ? '' : 'is-muted' ?>" data-href="/admin/users/<?= (int) $r['id'] ?>/edit">
                <td><strong class="mono"><?= e($r['username']) ?></strong><?php if ((int) $r['id'] === $user['id']): ?> <span class="text-muted text-sm">(Sie)</span><?php endif; ?></td>
                <td><?= e($r['display_name']) ?></td>
                <td><?php if ($r['email']): ?><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php else: ?>–<?php endif; ?></td>
                <td><?= badge((string) $r['role_label'], $r['role'] === 'admin' ? 'danger' : ($r['role'] === 'readonly' ? 'neutral' : 'info')) ?></td>
                <td><?php if ($r['groups']): ?><?php foreach ($r['groups'] as $g): ?><?= badge($groupLabels[$g] ?? $g, 'neutral') ?> <?php endforeach; ?><?php else: ?>–<?php endif; ?></td>
                <td><?= $r['auth_source'] === 'ldap' ? badge('Active Directory', 'info') : badge('Lokal', 'neutral') ?></td>
                <td class="text-sm text-muted">
                    <?= $r['last_login_at'] ? e(fmt_datetime($r['last_login_at'])) : 'noch nie' ?>
                    <?php if ((int) $r['is_locked'] === 1): ?><br><?= badge('Gesperrt bis ' . fmt_datetime($r['locked_until']), 'warning') ?><?php endif; ?>
                </td>
                <td><?= active_badge($r['is_active']) ?></td>
                <td class="table-actions"><a class="btn btn-ghost btn-sm" href="/admin/users/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
