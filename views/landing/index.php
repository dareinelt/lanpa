<?php

declare(strict_types=1);

use App\Support\Html;

/** @var list<array<string,mixed>> $items */
/** @var string $descriptionMode */
/** @var bool $landingIntroVisible */
/** @var array<string,mixed>|null $announcement */
?>
<?php if (($announcement ?? null) !== null) {
    $announcementId = (int) $announcement['id'];
    $announcementUpdatedAt = (string) ($announcement['updated_at'] ?? $announcement['created_at'] ?? '');
    ?>
    <div class="announcement-overlay"
         data-announcement
         data-announcement-id="<?= $announcementId ?>"
         data-announcement-version="<?= Html::e($announcementUpdatedAt) ?>"
         role="dialog"
         aria-modal="true"
         aria-labelledby="announcement-title"
         hidden>
        <div class="announcement-overlay__box">
            <h2 class="announcement-overlay__title" id="announcement-title"><?= Html::e((string) $announcement['title']) ?></h2>
            <p class="announcement-overlay__text"><?= nl2br(Html::e((string) $announcement['message'])) ?></p>
            <div class="announcement-overlay__actions">
                <button type="button" class="button button--primary" data-announcement-dismiss>Verstanden</button>
            </div>
        </div>
    </div>
<?php } ?>
<?php if ($landingIntroVisible ?? true) { ?>
    <section class="intro">
        <h1 class="intro__title"><?= Html::e($siteTitle ?? 'Intranet') ?></h1>
        <?php if (($siteSubtitle ?? '') !== '') { ?>
            <p class="intro__text"><?= Html::e($siteSubtitle) ?></p>
        <?php } ?>
    </section>
<?php } ?>

<?php if ($items === []) { ?>
    <p class="empty-state">Es sind derzeit keine Anwendungen freigeschaltet. Bitte wenden Sie sich an die Administration.</p>
<?php } else { ?>
    <ul class="tiles" data-description-mode="<?= Html::e($descriptionMode) ?>">
        <?php foreach ($items as $item) {
            $id = (int) $item['id'];
            $isExternal = (string) $item['type'] === 'external';
            $description = trim((string) ($item['description'] ?? ''));
            $shortDescription = trim((string) ($item['short_description'] ?? ''));
            $detailsId = 'tile-details-' . $id;
            $icon = (string) ($item['icon'] ?? '');
            ?>
            <li class="tile">
                <a class="tile__link"
                   href="<?= Html::url((string) $item['url']) ?>"
                   data-nav-id="<?= $id ?>"
                   <?= $isExternal ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                   <?= $description !== '' ? 'aria-describedby="' . Html::e($detailsId) . '"' : '' ?>>
                    <span class="tile__icon-wrap" aria-hidden="true">
                        <?php require dirname(__DIR__) . '/partials/icon.php'; ?>
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

<?php if (($importantLinks ?? []) !== []) { ?>
    <details class="important-links">
        <summary class="important-links__summary">
            <span class="important-links__title">Wichtige Links</span>
            <span class="important-links__toggle-icon" aria-hidden="true"></span>
        </summary>
        <ul class="important-links__list">
            <?php foreach ($importantLinks as $link) {
                $linkIsExternal = !str_starts_with((string) $link['url'], '/');
                $iconFile = trim((string) ($link['icon_file'] ?? ''));
                ?>
                <li class="important-links__item">
                    <a class="important-links__link"
                       href="<?= Html::url((string) $link['url']) ?>"
                       <?= $linkIsExternal ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <span class="important-links__icon-wrap" aria-hidden="true">
                            <?php if ($iconFile !== '') { ?>
                                <img class="important-links__icon" src="/wichtige-links/icon?id=<?= (int) $link['id'] ?>" alt="" width="16" height="16" loading="lazy">
                            <?php } else {
                                $icon = 'link';
                                require dirname(__DIR__) . '/partials/icon.php';
                            } ?>
                        </span>
                        <span class="important-links__label"><?= Html::e((string) $link['title']) ?></span>
                        <?php if ($linkIsExternal) { ?>
                            <span class="visually-hidden">(öffnet in einem neuen Tab)</span>
                        <?php } ?>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </details>
<?php } ?>
