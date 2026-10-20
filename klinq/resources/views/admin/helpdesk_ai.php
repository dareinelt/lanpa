<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? null;
$val = static fn (string $key): string => (string) ($old[$key] ?? $settings[$key] ?? '');
$scripts = ['/js/helpdesk-ai-admin.js'];
$model = (string) ($settings['model'] ?? '');
$url = (string) ($settings['url'] ?? '');
$managed = !empty($settings['managed_by_intranet']);
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/admin">Administration</a></p>
        <h1 class="page-title">KI-Unterstützung (Help Desk)</h1>
        <p class="text-muted mb-0">Die KI hilft den Agenten beim Formulieren von Kommentaren, Lösungsbeschreibungen und Notizen.
            Angesprochen wird ein OpenAI-kompatibler Endpunkt (z. B. Ollama, vLLM, LocalAI) – die Adresse wird inklusive
            <code>/v1</code> angegeben.</p>
    </div>
</div>

<?php if (!empty($settings['api_key_unreadable'])): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning', 'icon icon-xs') ?> Der gespeicherte API-Schlüssel kann nicht entschlüsselt werden (<code>APP_KEY</code> wurde geändert). Bitte den Schlüssel erneut eingeben.</div>
<?php endif; ?>

<div class="grid grid-4 mb-4">
    <div class="card kpi-card"><span class="kpi-value"><?= !empty($settings['enabled']) ? 'Aktiv' : 'Inaktiv' ?></span><span class="kpi-label">KI-Unterstützung</span></div>
    <div class="card kpi-card"><span class="kpi-value text-sm mono"><?= e($model !== '' ? $model : '–') ?></span><span class="kpi-label">Modell</span></div>
    <div class="card kpi-card"><span class="kpi-value text-sm"><?= !empty($settings['api_key_set']) ? 'gesetzt' : 'ohne' ?></span><span class="kpi-label">API-Schlüssel</span></div>
    <div class="card kpi-card"><span class="kpi-value text-sm mono" title="<?= e($url) ?>"><?= e($url !== '' ? (mb_strlen($url) > 26 ? '…' . mb_substr($url, -25) : $url) : '–') ?></span><span class="kpi-label">Endpunkt</span></div>
</div>

<?php if ($managed): ?>
    <div class="alert alert-info mb-4"><?= icon('info', 'icon icon-xs') ?> Endpunkt, Modell und API-SchlÃ¼ssel werden im Intranet unter <em>Administration â†’ KLINQ â†’ KI-Endpunkt</em> gepflegt und von dort Ã¼bernommen. Die Werte hier sind nur lesend.</div>
<?php endif; ?>

<form method="post" action="/admin/helpdesk-ai" class="card" novalidate data-ai-form>
    <?= csrf_field() ?>
    <fieldset <?= $managed ? 'disabled' : '' ?>>

    <h3 class="text-sm text-muted">Betrieb</h3>
    <div class="form-group">
        <label class="checkbox-field"><input type="checkbox" name="enabled" value="1"<?= form_checked($settings, 'enabled', false) ?>> <span>KI-Unterstützung aktiv</span></label>
        <span class="form-hint">Nur wenn die KI aktiv und der Endpunkt erreichbar ist, erscheint „Mit KI verbessern“ im Kontextmenü der Textfelder.</span>
    </div>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('name') ? 'has-error' : '' ?>">
            <label for="ai-name">Anzeigename</label>
            <input id="ai-name" name="name" type="text" maxlength="60" value="<?= e($val('name')) ?>" placeholder="Lokale KI">
            <span class="form-hint">Erscheint im Dialog und im Kontextmenü.</span>
            <?= field_error('name') ?>
        </div>
        <div class="form-group <?= has_error('timeout') ? 'has-error' : '' ?>">
            <label for="ai-timeout">Zeitlimit (Sekunden)</label>
            <input id="ai-timeout" name="timeout" type="number" min="5" max="900" required value="<?= e($val('timeout')) ?>">
            <span class="form-hint">Große Modelle brauchen für längere Texte mehr Zeit.</span>
            <?= field_error('timeout') ?>
        </div>
        <div class="form-group <?= has_error('api_key') ? 'has-error' : '' ?>">
            <label for="ai-key">API-Schlüssel</label>
            <input id="ai-key" name="api_key" type="password" maxlength="4096" autocomplete="new-password" placeholder="<?= !empty($settings['api_key_set']) ? '•••••••• (gespeichert)' : 'optional' ?>">
            <span class="form-hint">Leer lassen, um den gespeicherten Schlüssel zu behalten. Er wird verschlüsselt abgelegt und nie angezeigt.</span>
            <?php if (!empty($settings['api_key_set'])): ?>
                <label class="checkbox-field"><input type="checkbox" name="remove_api_key" value="1"> <span>Gespeicherten Schlüssel löschen</span></label>
            <?php endif; ?>
            <?= field_error('api_key') ?>
        </div>
    </div>

    <h3 class="text-sm text-muted">Endpunkt</h3>
    <div class="form-group <?= has_error('url') ? 'has-error' : '' ?>">
        <label for="ai-url">Adresse des Endpunkts</label>
        <input id="ai-url" name="url" type="url" maxlength="2048" value="<?= e($val('url')) ?>" placeholder="http://ki-server:11434/v1">
        <span class="form-hint">Vollständige http(s)-Adresse inklusive <code>/v1</code>, ohne Parameter und ohne Zugangsdaten.</span>
        <?= field_error('url') ?>
    </div>
    <div class="form-group <?= has_error('model') ? 'has-error' : '' ?>">
        <label for="ai-model">Modell</label>
        <div class="ai-model-field">
            <input id="ai-model" name="model" type="text" maxlength="200" list="ai-models" value="<?= e($val('model')) ?>" placeholder="llama3.1:8b">
            <button type="button" class="btn btn-secondary" data-ai-models><?= icon('refresh') ?> Modell ermitteln</button>
        </div>
        <datalist id="ai-models"></datalist>
        <span class="form-hint">Wird über „Modell ermitteln“ aus dem Endpunkt gelesen und in das Feld übernommen.</span>
        <?= field_error('model') ?>
    </div>

    </fieldset>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary"<?= $managed ? ' disabled' : '' ?>><?= icon('check') ?> Speichern</button>
        <a class="btn btn-ghost" href="/admin">Abbrechen</a>
    </div>
</form>

<div class="card mt-4">
    <h3 class="text-sm text-muted">Verbindung testen</h3>
    <p class="text-muted text-sm">Getestet werden die Werte aus dem Formular – gespeichert werden muss vorher nicht.
        Ein bereits hinterlegter Schlüssel wird nur an die gespeicherte Adresse gesendet.</p>
    <div class="form-group">
        <label for="ai-test-prompt">Testfrage</label>
        <textarea id="ai-test-prompt" name="test_prompt" rows="3" maxlength="<?= (int) $maxPromptLength ?>" placeholder="<?= e($defaultTestPrompt) ?>"></textarea>
        <span class="form-hint">Leer lassen für die Standardfrage „<?= e($defaultTestPrompt) ?>“.</span>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-secondary" data-ai-test<?= $managed ? ' disabled' : '' ?>><?= icon('link') ?> Verbindung testen</button>
    </div>

    <div class="ai-test-result" data-ai-result hidden>
        <ol class="ai-test-steps" data-ai-steps></ol>
        <div class="ai-test-bubbles">
            <div class="ai-test-bubble ai-test-question" data-ai-question hidden></div>
            <div class="ai-test-bubble ai-test-answer" data-ai-answer hidden></div>
        </div>
        <p class="ai-test-meta text-xs text-muted" data-ai-meta hidden></p>
    </div>
</div>

<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
