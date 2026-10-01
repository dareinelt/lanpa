<?php

declare(strict_types=1);

use App\Support\Html;

/** @var string $target */
?>
<section class="error-page">
    <h1>Weiter ohne Windows-Anmeldung</h1>
    <p>Dieser Browser konnte nicht automatisch mit einem Windows-Konto angemeldet werden.
        Alle öffentlichen Inhalte stehen trotzdem zur Verfügung.</p>
    <p><a class="button button--primary" href="<?= Html::e($target) ?>">Weiter</a></p>
    <details class="sso-hint">
        <summary>Hinweis für Administratoren: Anmeldedialog erschienen?</summary>
        <p>Fragt der Browser auf einem Domänen-PC nach Benutzername und Passwort, sendet er die
            Windows-Anmeldung nicht automatisch: Die Adresse dieser Seite muss auf den Clients in der
            Zone „Lokales Intranet“ liegen bzw. in der Richtlinie <code>AuthServerAllowlist</code>
            (Edge/Chrome) oder <code>network.negotiate-auth.trusted-uris</code> (Firefox) freigegeben
            sein – per Gruppenrichtlinie oder mit <code>scripts/sso-client-setup.ps1</code>
            (siehe README, „Anmeldedialog vermeiden“). Meldet das Protokoll des auth-Containers
            dabei <code>Invalid token was supplied (Permission denied)</code>, wurde die Anmeldung
            gesendet, aber serverseitig abgelehnt – auth-Container neu bauen (Patch gss-ntlmssp).</p>
    </details>
</section>
