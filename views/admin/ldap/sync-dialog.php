<?php

declare(strict_types=1);

/*
 * Fortschritts-Overlay der manuellen AD-Synchronisation. Wird von admin-ldap.js
 * fuer das Formular mit data-ad-sync geoeffnet (Seite "Synchronisation", Dashboard).
 */
?>
<dialog id="ad-sync-dialog" class="ad-sync" aria-labelledby="ad-sync-title">
    <h2 id="ad-sync-title">Synchronisation</h2>
    <p class="ad-sync__status" id="ad-sync-status" role="status">Synchronisation wird gestartet …</p>
    <ol class="ad-sync__sources" id="ad-sync-sources" aria-live="polite"></ol>
    <p class="ad-sync__summary" id="ad-sync-summary" hidden></p>
    <div class="form__actions">
        <button type="button" class="button button--primary" id="ad-sync-ok" disabled>OK</button>
    </div>
</dialog>
