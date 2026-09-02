<?php

declare(strict_types=1);

use App\Support\Html;

/** @var int $status */
/** @var string $message */
?>
<section class="error-page">
    <h1>Seite nicht gefunden</h1>
    <p><?= Html::e($message !== '' ? $message : 'Die angeforderte Seite existiert nicht.') ?></p>
    <p><a class="button button--primary" href="/">Zur Startseite</a></p>
</section>
