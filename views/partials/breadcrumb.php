<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * @var list<array<string,mixed>> $breadcrumb
 */
if ($breadcrumb !== []) { ?>
    <nav class="breadcrumb" aria-label="Seitenpfad">
        <ol class="breadcrumb__list">
            <li class="breadcrumb__item">
                <a href="/">Start</a>
            </li>
            <?php foreach ($breadcrumb as $index => $crumb) {
                $crumbId = (int) $crumb['id'];
                $crumbType = (string) $crumb['type'];
                $href = $crumbType === 'page' ? '/seite?id=' . $crumbId : '/unterseite?id=' . $crumbId;
                $isLast = $index === count($breadcrumb) - 1;
                ?>
                <li class="breadcrumb__item" aria-hidden="true">›</li>
                <li class="breadcrumb__item">
                    <?php if ($isLast) { ?>
                        <span aria-current="page"><?= Html::e((string) $crumb['title']) ?></span>
                    <?php } else { ?>
                        <a href="<?= Html::e($href) ?>"><?= Html::e((string) $crumb['title']) ?></a>
                    <?php } ?>
                </li>
            <?php } ?>
        </ol>
    </nav>
<?php } ?>
