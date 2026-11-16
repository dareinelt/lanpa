<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; $isLdap = !$isNew && $row['auth_source'] === 'ldap'; $pwErrors = has_error('password') || has_error('password_confirmation'); ?>
<div class="page-header">
    <div>
        <p class="breadcrumb mb-0"><a href="/admin">Administration</a> › <a href="/admin/users">Benutzer</a> › <?= e($isNew ? 'Neu' : $row['username']) ?></p>
        <h1><?= e($title) ?></h1>
    </div>
    <div class="page-actions"><a class="btn btn-ghost" href="/admin/users"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="grid grid-2 users-form-grid">
<div class="card card-form">
    <div class="card-header"><h2>Konto</h2></div>
    <form method="post" action="<?= e($isNew ? '/admin/users' : '/admin/users/' . (int) $row['id']) ?>" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="f-username">Benutzername *</label>
            <?php if ($isNew): ?>
                <input id="f-username" name="username" value="<?= e(old('username')) ?>" required maxlength="64" pattern="[a-zA-Z0-9][a-zA-Z0-9._\-@]{2,63}" class="mono" autofocus autocomplete="off">
                <?= field_error('username') ?>
                <p class="form-hint">3–64 Zeichen: Buchstaben, Ziffern, <code>. _ - @</code>. Kann später nicht geändert werden.</p>
            <?php else: ?>
                <input id="f-username" value="<?= e($row['username']) ?>" class="mono" disabled>
                <p class="form-hint">Quelle: <?= $isLdap ? 'Active Directory (Anmeldung mit AD-Passwort)' : 'lokales Konto' ?></p>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="f-display">Anzeigename *</label>
            <input id="f-display" name="display_name" value="<?= e(form_value($row, 'display_name')) ?>" required maxlength="150">
            <?= field_error('display_name') ?>
        </div>
        <div class="form-group">
            <label for="f-email">E-Mail</label>
            <input id="f-email" name="email" type="email" value="<?= e(form_value($row, 'email')) ?>" maxlength="255">
            <?= field_error('email') ?>
        </div>
        <?php $currentRole = (string) form_value($row, 'role', $isNew ? 'portal' : ''); ?>
        <?php $roleGroupMap = is_array($impliedGroupsByRole ?? null) ? $impliedGroupsByRole : []; ?>
        <?php $roleImpliedNames = $roleGroupMap[$currentRole] ?? []; ?>
        <div class="form-group">
            <label for="f-role">Rolle *</label>
            <select id="f-role" name="role" required<?= ($isSelf || $isLdap) ? ' aria-describedby="role-hint"' : '' ?> data-role-groups="<?= e((string) json_encode($roleGroupMap, JSON_UNESCAPED_UNICODE)) ?>">
                <option value="">– bitte wählen –</option>
                <?php foreach ($roleLabels as $name => $label): ?>
                    <option value="<?= e($name) ?>"<?= selected($currentRole, $name) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error('role') ?>
            <?php if ($isSelf): ?>
                <p class="form-hint" id="role-hint">Die eigene Administratorrolle kann nicht entzogen werden.</p>
            <?php elseif ($isLdap): ?>
                <?php if ((int) ($row['role_override'] ?? 0) === 1): ?>
                    <p class="form-hint" id="role-hint">Rolle ist lokal in KLINQ festgelegt und übersteuert die Intranet-SSO-Gruppenzuordnung<?= !empty($row['sso_role_label']) ? ' (Intranet-SSO: ' . e((string) $row['sso_role_label']) . ')' : '' ?>.</p>
                <?php else: ?>
                    <p class="form-hint" id="role-hint">Wird aus dem Intranet-SSO (AD-Gruppen) übernommen; eine hier abweichend gewählte Rolle übersteuert die Intranet-SSO-Zuordnung.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($groups): ?>
        <div class="form-group">
            <label>Berechtigungsgruppen</label>
            <div class="checkbox-row" data-permission-groups>
                <?php $checkedGroups = array_map('intval', (array) ($_SESSION['_old_input']['groups'] ?? $selectedGroups)); ?>
                <?php $ssoGroupList = array_map('intval', (array) ($ssoGroups ?? [])); ?>
                <?php foreach ($groups as $g): ?>
                    <?php
                    $gid = (int) $g['id'];
                    $gname = (string) $g['name'];
                    $fromSso = $isLdap && in_array($gid, $ssoGroupList, true);
                    $isManual = !$fromSso && in_array($gid, $checkedGroups, true);
                    $fromRole = !$fromSso && in_array($gname, $roleImpliedNames, true);
                    $isChecked = $fromSso || $isManual || $fromRole;
                    $isDisabled = $fromSso || $fromRole;
                    $originNote = $fromSso ? '(Intranet-SSO)' : ($fromRole ? '(in Rolle enthalten)' : '');
                    ?>
                    <label class="checkbox-field">
                        <input type="checkbox" name="groups[]" value="<?= $gid ?>"
                            data-group-name="<?= e($gname) ?>"
                            data-from-sso="<?= $fromSso ? '1' : '0' ?>"
                            data-manual-checked="<?= $isManual ? '1' : '0' ?>"
                            <?= $isChecked ? 'checked' : '' ?><?= $isDisabled ? ' disabled' : '' ?>>
                        <span><?= e($g['label']) ?> <small class="text-muted" data-group-origin<?= $originNote === '' ? ' hidden' : '' ?>><?= e($originNote) ?></small></span>
                    </label>
                    <?php if ($fromSso): ?>
                        <input type="hidden" name="groups[]" value="<?= $gid ?>">
                    <?php elseif ($isManual && $fromRole): ?>
                        <input type="hidden" name="groups[]" value="<?= $gid ?>" data-manual-hidden="<?= $gid ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="form-hint">Bereits über Intranet-SSO (LDAP/AD-Gruppen) oder die gewählte Rolle gesetzte Berechtigungen sind ebenfalls angehakt. Zusätzlich gesetzte Haken gelten ergänzend und bleiben bei späteren Intranet-SSO-Anmeldungen erhalten.</p>
        </div>
        <?php endif; ?>
        <?php if ($isNew): ?>
        <div class="form-row">
            <div class="form-group">
                <label for="f-password">Passwort *</label>
                <input id="f-password" name="password" type="password" required minlength="<?= (int) $passwordMin ?>" autocomplete="new-password">
                <?= field_error('password') ?>
            </div>
            <div class="form-group">
                <label for="f-password2">Passwort wiederholen *</label>
                <input id="f-password2" name="password_confirmation" type="password" required autocomplete="new-password">
                <?= field_error('password_confirmation') ?>
            </div>
        </div>
        <p class="form-hint">Mindestens <?= (int) $passwordMin ?> Zeichen mit Buchstaben und mindestens einer Ziffer oder einem Sonderzeichen. Der Benutzer sollte das Passwort nach der ersten Anmeldung unter <em>Passwort ändern</em> selbst neu setzen.</p>
        <?php endif; ?>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?><?= $isSelf ? ' disabled' : '' ?>> <span>Aktiv</span></label>
        <?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
        <?= field_error('is_active') ?>
        <label class="checkbox-field"><input type="checkbox" name="can_sign_electronically" value="1"<?= form_checked($row, 'can_sign_electronically', false) ?>> <span>Digitale Signatur (Entnahme/Rückgabe) erlauben</span></label>
        <p class="form-hint">Opt-in: Erlaubt die manuelle Erfassung von Entnahme/Rückgabe am Desktop, bei der der Vorgang durch erneute Eingabe des eigenen Passworts elektronisch signiert wird.</p>
        <?= field_error('can_sign_electronically') ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="/admin/users">Abbrechen</a>
        </div>
    </form>
</div>
<?php if (!$isNew): ?>
<div class="stack">
    <div class="card card-form">
        <div class="card-header"><h2>Passwort zurücksetzen</h2></div>
        <?php if ($isLdap): ?>
            <p class="text-muted mb-0">Dieses Konto meldet sich über das Active Directory an – das Passwort wird dort verwaltet.</p>
        <?php else: ?>
        <form method="post" action="/admin/users/<?= (int) $row['id'] ?>/password" novalidate autocomplete="off">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="f-newpw">Neues Passwort *</label>
                <input id="f-newpw" name="password" type="password" required minlength="<?= (int) $passwordMin ?>" autocomplete="new-password">
                <?= $pwErrors ? field_error('password') : '' ?>
            </div>
            <div class="form-group">
                <label for="f-newpw2">Neues Passwort wiederholen *</label>
                <input id="f-newpw2" name="password_confirmation" type="password" required autocomplete="new-password">
                <?= $pwErrors ? field_error('password_confirmation') : '' ?>
            </div>
            <p class="form-hint">Setzt Fehlversuche und eine ggf. bestehende Sperre zurück. Das neue Passwort dem Benutzer auf sicherem Weg mitteilen.</p>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary"><?= icon('key') ?> Passwort setzen</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-header"><h2>Kontostatus</h2></div>
        <dl class="detail-list detail-list-compact">
            <dt>Letzte Anmeldung</dt><dd><?= $row['last_login_at'] ? e(fmt_datetime($row['last_login_at'])) : 'noch nie' ?></dd>
            <dt>Fehlversuche</dt><dd><?= (int) $row['failed_logins'] ?></dd>
            <dt>Gesperrt bis</dt><dd><?= (int) $row['is_locked'] === 1 ? e(fmt_datetime($row['locked_until'])) : '–' ?></dd>
            <dt>Angelegt</dt><dd><?= e(fmt_datetime($row['created_at'])) ?></dd>
        </dl>
        <?php if (!$isSelf): ?>
        <form method="post" action="/admin/users/<?= (int) $row['id'] ?>/toggle-active" class="mt-3">
            <?= csrf_field() ?>
            <button type="submit" class="btn <?= (int) $row['is_active'] ? 'btn-secondary' : 'btn-secondary' ?>"><?= icon((int) $row['is_active'] ? 'x' : 'check') ?> <?= (int) $row['is_active'] ? 'Konto deaktivieren' : 'Konto aktivieren' ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
