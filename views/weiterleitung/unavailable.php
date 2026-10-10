<?php

declare(strict_types=1);

?>
<section class="error-page">
    <h1>Die Anwendung ist derzeit nicht erreichbar</h1>
    <p>Die aufgerufene Anwendung antwortet nicht, ist gestört oder die Weiterleitung
        über den Intranet-Server ist noch nicht eingerichtet.</p>
    <p>Bitte versuchen Sie es in einigen Minuten erneut. Wenn der Fehler bleibt,
        wenden Sie sich an die Administration.</p>
    <p class="error-page__code">Fehlercode: 503</p>
    <p>
        <a class="button button--primary" href="/">Zur Startseite</a>
        <?php /* Als Fehlerseite des Proxys steht die Seite unter der aufgerufenen Adresse. */ ?>
        <a class="button" href="">Erneut versuchen</a>
    </p>
</section>
