<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<section data-ep-preview data-render-url="/admin/notfallplan/vorschau">
    <?= Csrf::field() ?>
    <div class="ep-warning ep-preview-banner">
        <strong>LIVE-VORSCHAU / SANDBOX – kein echter Einsatz</strong>
        <p>Ungespeicherter Editorstand. Keine Speicherung, Veröffentlichung, Kennwortprüfung, E-Mail oder SMS.
            Sie können diesen Tab auf einen zweiten Monitor ziehen.</p>
        <div class="toolbar">
            <button type="button" class="button button--ghost" data-ep-preview-reset>Simulation zurücksetzen</button>
            <button type="button" class="button button--ghost" data-ep-preview-retry>Verbindung erneut prüfen</button>
        </div>
        <p data-ep-preview-connection role="status">Verbindung zum Editor wird hergestellt …</p>
        <p data-ep-preview-result role="status" aria-live="polite"></p>
    </div>
    <div data-ep-preview-content></div>
</section>
<noscript><p class="flash flash--error">Die Live-Vorschau benötigt JavaScript. Der Entwurf bleibt im Editor erhalten.</p></noscript>
