<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
$activeNav = 'helpdesk-admin';
$areaLabel = 'Help Desk';
$colorName = static fn (?string $c): string => $colors[$c ?? 'neutral'] ?? ($c ?? 'neutral');
$yesNo = static fn (mixed $v): string => (int) $v === 1 ? 'Ja' : 'Nein';
$cellActive = static fn (array $r): string => array_key_exists('is_active', $r) ? active_badge($r['is_active']) : '–';
$memberCount = static fn (array $r): int => (int) ($r['member_count'] ?? (is_array($r['members'] ?? null) ? count($r['members']) : 0));
?>
<div class="page-header">
    <div><h1 class="page-title">Help-Desk-Administration</h1><p class="page-subtitle text-muted"><?= $isQuickReport ? 'Öffentliches Formular für Anwender ohne Anmeldung.' : ($isReplyTemplates ? 'Fester Präfix-Text für Agenten-E-Mails.' : 'Stammdaten, SLAs, Vorlagen und Automatisierungen.') ?></p></div>
    <div class="page-actions"><?php if ($can('helpdesk.admin')): ?><a class="btn btn-secondary" href="/helpdesk/admin/mail"><?= icon('mail') ?> E-Mail-Eingang</a> <?php endif; ?><?php if (!$isQuickReport && !$isReplyTemplates): ?><a class="btn btn-primary" href="/helpdesk/admin/<?= e($kind) ?>/new"><?= icon('plus') ?> Neu</a><?php endif; ?></div>
</div>
<div class="status-tabs admin-tabs" role="tablist" aria-label="Administrationsbereiche">
    <?php foreach ($kinds as $key => $label): ?>
        <a class="status-tab<?= $kind === $key ? ' is-active' : '' ?>" href="/helpdesk/admin?tab=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<?php if (!$isQuickReport && !$isReplyTemplates): ?>
<div class="card mb-4">
    <div class="card-header"><h2>Konfiguration</h2><span class="text-muted text-sm">aus Umgebung</span></div>
    <div class="grid grid-4">
        <?php foreach ($config as $key => $value): ?>
            <div><span class="kpi-value text-sm"><?= is_bool($value) ? e($yesNo($value)) : e((string) $value) ?></span><span class="kpi-label"><?= e(str_replace('_', ' ', $key)) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>
<?php elseif ($isQuickReport): ?>
<div class="card mb-4">
    <div class="card-header"><h2>Störungsformular für Anwender</h2><span class="text-muted text-sm"><?= $quickReport['enabled'] ? 'aktiv' : 'deaktiviert' ?></span></div>
    <p class="text-muted text-sm">Öffentlicher Link ohne Anmeldung – nur Betreff und Beschreibung. Melder (inkl. AD-Abgleich), Rechnername und IP-Adresse werden automatisch erfasst. Diesen Link im Intranet, als Desktopverknüpfung oder per Gruppenrichtlinie verteilen.</p>
    <div class="form-row">
        <div class="form-group">
            <label for="quick-report-url">Link zum Formular</label>
            <input id="quick-report-url" class="mono" value="<?= e($quickReport['url']) ?>" readonly>
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <a class="btn btn-secondary" href="/stoerung" target="_blank" rel="noopener"><?= icon('ticket') ?> Formular öffnen</a>
        </div>
    </div>
    <?php if ($quickReport['networks'] !== [] || !$quickReport['enabled']): ?>
    <p class="text-muted text-xs mb-0"><?= $quickReport['networks'] !== [] ? 'Zugriff beschränkt auf: ' . e(implode(', ', $quickReport['networks'])) . '.' : '' ?><?= $quickReport['enabled'] ? '' : ' Aktivierung über HELPDESK_QUICK_REPORT_ENABLED=true.' ?></p>
    <?php endif; ?>
</div>
<div class="card mb-4">
    <div class="card-header"><h2>Sammelkonten</h2><span class="text-muted text-sm">Pflicht für zusätzlichen Benutzer</span></div>
    <p class="text-muted text-sm">Anmeldenamen von AD-/SSO-Konten („Sammelkonten“ bzw. anonyme Konten), bei denen im Formular zwingend ein zusätzlicher Benutzer angegeben werden muss. Konto eintippen, Vorschlag auswählen und mit <kbd>Enter</kbd> übernehmen; die eingetragenen Konten stehen darunter und lassen sich dort wieder entfernen. Schreibweisen wie <span class="mono">DOMAIN\konto</span> oder <span class="mono">konto@domain</span> werden auf den Anmeldenamen reduziert.</p>
    <form method="post" action="/helpdesk/admin/quick-report" novalidate>
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group<?= has_error('shared_accounts') ? ' has-error' : '' ?>">
                <label for="quick-report-shared-account-input">Konten</label>
                <?php $accountValue = (string) old('shared_accounts', $quickReport['shared_accounts']); ?>
                <div class="account-picker" data-shared-accounts data-suggest-url="/api/helpdesk/quick-report/accounts/suggest">
                    <div data-shared-account-ui hidden>
                        <div class="account-picker-entry">
                            <input id="quick-report-shared-account-input" type="text" class="mono" autocomplete="off" spellcheck="false" placeholder="empfang" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="quick-report-shared-account-suggestions" data-shared-account-input>
                            <button type="button" class="btn btn-secondary" data-shared-account-add><?= icon('plus') ?> Hinzufügen</button>
                        </div>
                        <ul class="account-picker-suggestions" id="quick-report-shared-account-suggestions" role="listbox" aria-label="Kontovorschläge" hidden data-shared-account-suggestions></ul>
                        <ul class="account-picker-list" aria-label="Eingetragene Sammelkonten" hidden data-shared-account-list>
                            <?php foreach (preg_split('/[\r\n,;]+/', $accountValue) ?: [] as $account): ?>
                                <?php if (trim($account) !== ''): ?>
                                    <li class="account-picker-chip"><?= e(trim($account)) ?><button type="button" class="account-picker-remove" aria-label="Konto entfernen" data-shared-account-remove>&times;</button></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                        <span class="form-hint" data-shared-account-empty<?= trim($accountValue) === '' ? '' : ' hidden' ?>>Leer = kein Konto ist verpflichtet.</span>
                        <span class="form-error" role="alert" hidden data-shared-account-feedback></span>
                    </div>
                    <textarea id="quick-report-shared-accounts" name="shared_accounts" rows="6" class="mono" placeholder="empfang&#10;besprechungsraum1" data-shared-account-value><?= e($accountValue) ?></textarea>
                </div>
                <?= field_error('shared_accounts') ?>
            </div>
            <div class="form-group<?= has_error('shared_accounts_hint') ? ' has-error' : '' ?>">
                <label for="quick-report-shared-hint">Erklärungstext</label>
                <textarea id="quick-report-shared-hint" name="shared_accounts_hint" rows="6" maxlength="1000"><?= e(old('shared_accounts_hint', $quickReport['shared_accounts_hint'])) ?></textarea>
                <span class="form-hint">Erscheint über dem automatisch aufgeklappten Feld „Zusätzlicher Benutzer“; leer = Standardtext.</span>
                <?= field_error('shared_accounts_hint') ?>
            </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button></div>
    </form>
</div>
<?php unset($_SESSION['_old_input'], $_SESSION['_errors']); ?>
<?php elseif ($isReplyTemplates): ?>
<div class="card mb-4">
    <div class="card-header"><h2>Vorlageneinstellungen</h2><span class="text-muted text-sm">Präfix für Agenten-E-Mails, Eingangsbestätigung, Schließungstext, Fuß-Hinweis</span></div>
    <p class="text-muted text-sm">Der Präfix-Text wird beim Senden einer E-Mail aus einem Ticket automatisch <strong>vor</strong> den Text des Agenten gesetzt. Er ist im Sendedialog weder sichtbar noch änderbar. Leer = kein Präfix.</p>
    <form method="post" action="/helpdesk/admin/reply-templates" novalidate>
        <?= csrf_field() ?>
        <div class="form-group<?= has_error('prefix_text') ? ' has-error' : '' ?>">
            <label for="reply-template-prefix">Präfix-Text</label>
            <textarea id="reply-template-prefix" name="prefix_text" rows="8" maxlength="<?= (int) $replyTemplate['max_length'] ?>" placeholder="Sehr geehrte Damen und Herren,&#10;&#10;vielen Dank für Ihre Nachricht."><?= e(old('prefix_text', $replyTemplate['prefix_text'])) ?></textarea>
            <span class="form-hint">Leerzeile = neuer Absatz, einfacher Zeilenumbruch bleibt erhalten. Höchstens <?= (int) $replyTemplate['max_length'] ?> Zeichen. Gilt nur für die Agenten-Mail aus dem Ticket, nicht für die Info-E-Mail beim Zusammenführen.</span>
            <?= field_error('prefix_text') ?>
        </div>
        <div class="form-group<?= has_error('confirmation_text') ? ' has-error' : '' ?>">
            <label for="reply-template-confirmation">Bestätigungstext (Eingangsbestätigung)</label>
            <textarea id="reply-template-confirmation" name="confirmation_text" rows="10" maxlength="<?= (int) $replyTemplate['max_confirmation_length'] ?>" placeholder="Guten Tag {name},&#10;&#10;vielen Dank für Ihre Meldung. Wir haben Ihr Ticket mit der Nummer {ticketnummer} aufgenommen und melden uns zeitnah bei Ihnen."><?= e(old('confirmation_text', $replyTemplate['confirmation_text'])) ?></textarea>
            <span class="form-hint">Wird bei Meldungen aus dem Störungsformular und dem Störungspostfach automatisch versendet; bei Sammelkonten zusätzlich an den angegebenen Benutzer. Beim Anlegen durch einen Agenten nur, wenn im Assistenten „Bestätigung senden“ gewählt wird. Leer = keine Eingangsbestätigung (es greift die allgemeine Info-Mail).</span>
            <?= field_error('confirmation_text') ?>
        </div>
        <div class="form-group">
            <span class="form-hint">
                Platzhalter:
                <?php foreach ($replyTemplate['placeholders'] as $placeholder => $label): ?>
                    <code><?= e($placeholder) ?></code> <?= e($label) ?><?= $placeholder === array_key_last($replyTemplate['placeholders']) ? '' : ' · ' ?>
                <?php endforeach; ?>
            </span>
        </div>
        <div class="form-group<?= has_error('close_text') ? ' has-error' : '' ?>">
            <label for="reply-template-close">Schließungstext (Benachrichtigung beim Schließen)</label>
            <textarea id="reply-template-close" name="close_text" rows="10" maxlength="<?= (int) $replyTemplate['max_close_length'] ?>" placeholder="Guten Tag {name},&#10;&#10;Ihr Ticket {ticketnummer} „{betreff}“ wurde am {geschlossen_am} geschlossen.&#10;&#10;Grund: {schliessungsgrund}&#10;Lösung: {loesung}&#10;&#10;Bei Rückfragen antworten Sie einfach auf diese E-Mail."><?= e(old('close_text', $replyTemplate['close_text'])) ?></textarea>
            <span class="form-hint">Wird beim Schließen eines Tickets an den Melder gesendet, solange der Agent im Statusdialog „Melder über die Schließung informieren“ angehakt lässt. Höchstens <?= (int) $replyTemplate['max_close_length'] ?> Zeichen. Leer = es greift die allgemeine Statusmail des Help Desk.</span>
            <?= field_error('close_text') ?>
        </div>
        <div class="form-group">
            <span class="form-hint">
                Platzhalter im Schließungstext:
                <?php foreach ($replyTemplate['close_placeholders'] as $placeholder => $label): ?>
                    <code><?= e($placeholder) ?></code> <?= e($label) ?><?= $placeholder === array_key_last($replyTemplate['close_placeholders']) ? '' : ' · ' ?>
                <?php endforeach; ?>
            </span>
        </div>
        <div class="form-group<?= has_error('footer_text') ? ' has-error' : '' ?>">
            <label for="reply-template-footer">Hinweis am Fuß der E-Mail</label>
            <textarea id="reply-template-footer" name="footer_text" rows="4" maxlength="<?= (int) $replyTemplate['max_footer_length'] ?>" placeholder="Diese E-Mail wurde automatisch vom IT Help Desk ({firma}) erzeugt."><?= e(old('footer_text', $replyTemplate['footer_text'])) ?></textarea>
            <span class="form-hint">Wird unter jede Benachrichtigungsmail des Help Desk gesetzt (auch unter die Eingangsbestätigung), nicht unter die von Agenten verfasste E-Mail. Höchstens <?= (int) $replyTemplate['max_footer_length'] ?> Zeichen. Leer = kein Hinweis.</span>
            <?= field_error('footer_text') ?>
        </div>
        <div class="form-group">
            <span class="form-hint">
                Platzhalter im Fuß-Hinweis:
                <?php foreach ($replyTemplate['footer_placeholders'] as $placeholder => $label): ?>
                    <code><?= e($placeholder) ?></code> <?= e($label) ?><?= $placeholder === array_key_last($replyTemplate['footer_placeholders']) ? '' : ' · ' ?>
                <?php endforeach; ?>
            </span>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button></div>
    </form>
</div>
<?php unset($_SESSION['_old_input'], $_SESSION['_errors']); ?>
<?php endif; ?>
<?php if (!$isQuickReport && !$isReplyTemplates): ?>
<div class="card card-flush">
    <div class="card-header"><h2><?= e($kinds[$kind] ?? 'Bereich') ?></h2><span class="text-muted text-sm"><?= count($rows) ?> Einträge</span></div>
    <?php if ($rows === []): ?>
        <div class="table-empty">Noch keine Einträge vorhanden.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="table table-compact">
            <thead>
            <?php if (in_array($kind, ['types', 'statuses', 'priorities'], true)): ?>
                <tr><th>Code</th><th>Name</th><th>Farbe</th><th>Sortierung</th><th>Aktiv</th><th>Nutzung</th><th></th></tr>
            <?php elseif ($kind === 'groups'): ?>
                <tr><th>Name</th><th>E-Mail</th><th>Mitglieder</th><th>Offen</th><th>Aktiv</th><th>Nutzung</th><th></th></tr>
            <?php elseif ($kind === 'categories'): ?>
                <tr><th>Name</th><th>Übergeordnet</th><th>Standardgruppe</th><th>Standardpriorität</th><th>Aktiv</th><th>Tickets</th><th></th></tr>
            <?php elseif ($kind === 'slas'): ?>
                <tr><th>Name</th><th>Priorität</th><th>Kategorie</th><th>Reaktion</th><th>Lösung</th><th>Geschäftszeiten</th><th>Warn/Eskalation</th><th>Aktiv</th><th></th></tr>
            <?php elseif ($kind === 'tags'): ?>
                <tr><th>Name</th><th>Farbe</th><th>Nutzung</th><th></th></tr>
            <?php elseif ($kind === 'templates'): ?>
                <tr><th>Name</th><th>Typ</th><th>Kategorie</th><th>Portal</th><th>Aktiv</th><th>Sortierung</th><th></th></tr>
            <?php else: ?>
                <tr><th>Name</th><th>Auslöser</th><th>Aktiv</th><th>Sortierung</th><th>Stoppt</th><th></th></tr>
            <?php endif; ?>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                <?php if (in_array($kind, ['types', 'statuses', 'priorities'], true)): ?>
                    <td class="mono"><?= e($r['code'] ?? '–') ?><?php if ($kind === 'statuses'): ?><div class="text-xs text-muted"><?= e($statusCategories[$r['category']] ?? $r['category'] ?? '–') ?><?= !empty($r['pauses_sla']) ? ' · SLA pausiert' : '' ?><?= ($r['category'] ?? '') === 'pending' ? ' · Pausengrund' : '' ?></div><?php elseif ($kind === 'priorities'): ?><div class="text-xs text-muted">Stufe <?= (int) ($r['level'] ?? 0) ?><?= !empty($r['is_default']) ? ' · Standard' : '' ?></div><?php endif; ?></td>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><span class="color-swatch is-<?= e($r['color'] ?? 'neutral') ?>"></span><?= e($colorName($r['color'] ?? 'neutral')) ?></td>
                    <td class="mono"><?= (int) ($r['sort_order'] ?? 0) ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($usage[(int) $r['id']] ?? 0) ?></td>
                <?php elseif ($kind === 'groups'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['email'] ?? '–') ?></td>
                    <td class="mono"><?= $memberCount($r) ?></td>
                    <td class="mono"><?= (int) ($r['open_tickets'] ?? 0) ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($usage[(int) $r['id']] ?? 0) ?></td>
                <?php elseif ($kind === 'categories'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['parent_name'] ?? '–') ?></td>
                    <td><?= e($r['default_group_name'] ?? '–') ?></td>
                    <td><?= e($r['default_priority_name'] ?? '–') ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($r['ticket_count'] ?? 0) ?></td>
                <?php elseif ($kind === 'slas'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><?= !empty($r['is_default']) ? ' ' . badge('Standard', 'info') : '' ?><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['priority_name'] ?? '–') ?></td>
                    <td><?= e($r['category_name'] ?? '–') ?></td>
                    <td class="mono"><?= (int) ($r['response_minutes'] ?? 0) ?> min</td>
                    <td class="mono"><?= (int) ($r['resolution_minutes'] ?? 0) ?> min</td>
                    <td><?= !empty($r['business_hours_only']) ? e(($r['business_days'] ?? '') . ' · ' . substr((string) ($r['business_start'] ?? ''), 0, 5) . '–' . substr((string) ($r['business_end'] ?? ''), 0, 5)) : 'Nein' ?></td>
                    <td class="mono"><?= e((string) ($r['warning_percent'] ?? '–')) ?> / <?= e((string) ($r['escalation_percent'] ?? '–')) ?> %</td>
                    <td><?= $cellActive($r) ?></td>
                <?php elseif ($kind === 'tags'): ?>
                    <td><span class="tag-chip is-<?= e($r['color'] ?? 'neutral') ?>"><?= e($r['name'] ?? '–') ?></span></td>
                    <td><span class="color-swatch is-<?= e($r['color'] ?? 'neutral') ?>"></span><?= e($colorName($r['color'] ?? 'neutral')) ?></td>
                    <td class="mono"><?= (int) ($usage[(int) $r['id']] ?? $r['ticket_count'] ?? 0) ?></td>
                <?php elseif ($kind === 'templates'): ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td><?= e($r['type_name'] ?? '–') ?></td>
                    <td><?= e($r['category_name'] ?? '–') ?><?= !empty($r['subcategory_name']) ? ' / ' . e($r['subcategory_name']) : '' ?></td>
                    <td><?= badge(!empty($r['is_portal_visible']) ? 'Portal' : 'Intern', !empty($r['is_portal_visible']) ? 'success' : 'neutral') ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($r['sort_order'] ?? 0) ?></td>
                <?php else: ?>
                    <td><strong><?= e($r['name'] ?? '–') ?></strong><div class="text-xs text-muted"><?= e($r['description'] ?? '') ?></div></td>
                    <td class="mono"><?= e($r['trigger_event'] ?? '–') ?></td>
                    <td><?= $cellActive($r) ?></td>
                    <td class="mono"><?= (int) ($r['sort_order'] ?? 0) ?></td>
                    <td><?= e($yesNo($r['stop_processing'] ?? 0)) ?></td>
                <?php endif; ?>
                    <td class="table-actions nowrap">
                        <a class="btn btn-ghost btn-sm" href="/helpdesk/admin/<?= e($kind) ?>/<?= (int) $r['id'] ?>/edit"><?= icon('pen') ?> Bearbeiten</a>
                        <form method="post" action="/helpdesk/admin/<?= e($kind) ?>/<?= (int) $r['id'] ?>/toggle"<?= in_array($kind, ['tags', 'rules'], true) ? ' data-confirm="' . e(($kind === 'tags' ? 'Tag' : 'Regel') . ' wirklich löschen?') . '"' : '' ?>><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm"><?= icon(in_array($kind, ['tags', 'rules'], true) ? 'trash' : (!empty($r['is_active']) ? 'x' : 'refresh')) ?> <?= in_array($kind, ['tags', 'rules'], true) ? 'Löschen' : (!empty($r['is_active']) ? 'Deaktivieren' : 'Aktivieren') ?></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php $scripts = $isQuickReport ? ['/js/helpdesk-quick-report.js'] : []; $innerContent = ob_get_clean(); include __DIR__ . '/../../partials/app_layout.php'; ?>
