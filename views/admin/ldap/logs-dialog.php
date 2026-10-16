<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Protokoll-Overlay einer Anmelde-Instanz. admin-ldap.js oeffnet es fuer die
 * Formulare mit data-ad-logs und holt den Inhalt ueber den Endpunkt
 * "protokoll/inhalt" nach. Ohne JavaScript liefert der Server die Uebersicht
 * mit bereits geoeffnetem Overlay.
 */

/** @var array<string,mixed>|null $logs */
$logs = is_array($logs ?? null) ? $logs : null;
$state = (string) ($logs['state'] ?? '');
$downloadUrl = (string) ($logs['download_url'] ?? '');

if ($logs === null) {
    $status = 'Protokoll wird geladen …';
    $statusClass = '';
} elseif ($state === 'ready') {
    $lines = (int) ($logs['lines'] ?? 0);
    $status = $lines === 1 ? '1 Eintrag' : $lines . ' Einträge';
    if ((string) ($logs['generated_at'] ?? '') !== '') {
        $status .= ', erstellt am ' . (string) $logs['generated_at'];
    }
    $statusClass = ' ad-logs__status--ok';
} elseif ($state === 'error') {
    $status = (string) ($logs['message'] ?? '');
    $statusClass = ' ad-logs__status--error';
} else {
    $status = 'Das Protokoll wird erstellt …';
    $statusClass = '';
}
?>
<dialog id="ad-logs-dialog" class="ad-logs" aria-labelledby="ad-logs-title"<?= $logs !== null ? ' open' : '' ?>>
    <h2 id="ad-logs-title">Protokoll <span id="ad-logs-service"><?= Html::e((string) ($logs['service'] ?? '')) ?></span></h2>
    <p class="ad-logs__hint">
        Die letzten <strong>100 Einträge</strong> der Anmelde-Instanz. Die Protokolle der letzten
        <strong>48 Stunden</strong> stehen als Datei zum Herunterladen bereit.
    </p>
    <p class="ad-logs__status<?= $statusClass ?>" id="ad-logs-status" role="status"><?= Html::e($status) ?></p>
    <pre class="ad-logs__output" id="ad-logs-output" tabindex="0" aria-label="Protokollausgabe"><?= Html::e((string) ($logs['tail'] ?? '')) ?></pre>
    <div class="form__actions">
        <a class="button button--ghost" id="ad-logs-download" href="<?= $downloadUrl !== '' ? Html::e($downloadUrl) : '#' ?>"<?= $downloadUrl === '' ? ' hidden' : '' ?>>Letzte 48 Stunden herunterladen</a>
        <a class="button button--primary" id="ad-logs-close" href="/admin/ad">Schließen</a>
    </div>
</dialog>
