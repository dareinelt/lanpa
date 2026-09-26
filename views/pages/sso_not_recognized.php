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
</section>
