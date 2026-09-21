<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Zugriffssperre fuer geschuetzte Navigationselemente: Der eigentliche Inhalt
 * wird erst nach erfolgreicher SMS-Code-Verifizierung ausgeliefert.
 *
 * @var array<string,mixed> $item
 * @var list<array<string,mixed>> $breadcrumb
 * @var string $href
 */
?>
<?php require dirname(__DIR__) . '/partials/breadcrumb.php'; ?>

<section class="intro">
    <h1 class="intro__title"><?= Html::e((string) $item['title']) ?></h1>
    <p class="intro__text">Dieser Bereich ist geschützt. Zum Öffnen ist ein SMS-Zugangscode erforderlich.</p>
</section>

<p class="empty-state">
    <button type="button"
            class="button button--primary"
            data-protected-id="<?= (int) $item['id'] ?>"
            data-nav-href="<?= Html::e($href) ?>"
            data-nav-external="0"
            data-nav-title="<?= Html::e((string) $item['title']) ?>">
        Zugangscode anfordern
    </button>
</p>
