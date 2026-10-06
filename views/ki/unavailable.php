<?php

declare(strict_types=1);

?>
<section class="error-page">
    <h1>Die KI-Oberfläche ist derzeit nicht verfügbar</h1>
    <p>Die KI-Anwendung startet gerade, wird gewartet oder ist vorübergehend gestört.
        Bitte versuchen Sie es in einigen Minuten erneut.</p>
    <p class="error-page__code">Fehlercode: 503</p>
    <p>
        <a class="button button--primary" href="/">Zur Startseite</a>
        <?php /* Als Fehlerseite des Proxys steht die Seite unter der aufgerufenen Adresse. */ ?>
        <a class="button" href="">Erneut versuchen</a>
    </p>
</section>