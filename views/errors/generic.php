<?php

declare(strict_types=1);

use App\Support\Html;

/** @var int $status */
/** @var string $message */
?>
<section class="error-page">
    <h1>Es ist ein Fehler aufgetreten</h1>
    <p><?= Html::e($message !== '' ? $message : 'Bitte versuchen Sie es später erneut.') ?></p>
    <p class="error-page__code">Fehlercode: <?= (int) $status ?></p>
    <p><a class="button button--primary" href="/">Zur Startseite</a></p>
</section>
