<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Wiederverwendbare Kachel-Liste (Landingpage und Unterseiten).
 *
 * @var list<array<string,mixed>> $items
 * @var string $descriptionMode
 */
?>
<?php if ($items === []) { ?>
    <p class="empty-state">Es sind derzeit keine Anwendungen freigeschaltet. Bitte wenden Sie sich an die Administration.</p>
<?php } else { ?>
    <ul class="tiles" data-description-mode="<?= Html::e($descriptionMode) ?>">
        <?php foreach ($items as $item) {
            $id = (int) $item['id'];
            $type = (string) $item['type'];
            $isExternal = $type === 'external';
            $description = trim((string) ($item['description'] ?? ''));
            $shortDescription = trim((string) ($item['short_description'] ?? ''));
            $detailsId = 'tile-details-' . $id;
            $icon = (string) ($item['icon'] ?? '');

            if ($type === 'subpage') {
                $href = '/unterseite?id=' . $id;
            } elseif ($type === 'page') {
                $href = '/seite?id=' . $id;
            } else {
                $href = Html::url((string) $item['url']);
            }
            ?>
            <li class="tile">
                <a class="tile__link"
                   href="<?= Html::e($href) ?>"
                   data-nav-id="<?= $id ?>"
                   <?= $isExternal ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                   <?= $description !== '' ? 'aria-describedby="' . Html::e($detailsId) . '"' : '' ?>>
                    <span class="tile__icon-wrap" aria-hidden="true">
                        <?php require __DIR__ . '/icon.php'; ?>
                    </span>
                    <span class="tile__body">
                        <span class="tile__title"><?= Html::e((string) $item['title']) ?></span>
                        <?php if ($shortDescription !== '') { ?>
                            <span class="tile__short"><?= Html::e($shortDescription) ?></span>
                        <?php } ?>
                    </span>
                    <?php if ($isExternal) { ?>
                        <span class="visually-hidden">(öffnet in einem neuen Tab)</span>
                    <?php } ?>
                </a>

                <?php if ($description !== '') { ?>
                    <button type="button"
                            class="tile__toggle"
                            aria-expanded="false"
                            aria-controls="<?= Html::e($detailsId) ?>">
                        <span class="tile__toggle-label">Details</span>
                        <span class="tile__toggle-icon" aria-hidden="true"></span>
                    </button>
                    <div class="tile__details" id="<?= Html::e($detailsId) ?>">
                        <p><?= nl2br(Html::e($description)) ?></p>
                    </div>
                <?php } ?>
            </li>
        <?php } ?>
    </ul>
<?php } ?>
