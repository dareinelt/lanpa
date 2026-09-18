<?php

declare(strict_types=1);

use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var list<array<string,mixed>> $items */
/** @var list<array<string,mixed>> $breadcrumb */
/** @var string $descriptionMode */
?>
<?php require dirname(__DIR__) . '/partials/breadcrumb.php'; ?>

<section class="intro">
    <h1 class="intro__title"><?= Html::e((string) $item['title']) ?></h1>
    <?php if (trim((string) ($item['short_description'] ?? '')) !== '') { ?>
        <p class="intro__text"><?= Html::e((string) $item['short_description']) ?></p>
    <?php } ?>
</section>

<?php require dirname(__DIR__) . '/partials/tiles.php'; ?>
