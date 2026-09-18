<?php

declare(strict_types=1);

use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var list<array<string,mixed>> $breadcrumb */
?>
<?php require dirname(__DIR__) . '/partials/breadcrumb.php'; ?>

<article class="rich-text">
    <h1 class="rich-text__title"><?= Html::e((string) $item['title']) ?></h1>
    <?= (string) ($item['content'] ?? '') ?>
</article>
