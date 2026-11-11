<?php

declare(strict_types=1);

use App\Support\Html;
use App\Support\Validator;

/** @var list<array<string,mixed>> $items */
/** @var string $descriptionMode */
/** @var bool $landingIntroVisible */
?>
<?php if ($landingIntroVisible ?? true) { ?>
    <section class="intro">
        <h1 class="intro__title"><?= Html::e($siteTitle ?? 'Intranet') ?></h1>
        <?php if (($siteSubtitle ?? '') !== '') { ?>
            <p class="intro__text"><?= Html::e($siteSubtitle) ?></p>
        <?php } ?>
    </section>
<?php } ?>

<?php require dirname(__DIR__) . '/partials/tiles.php'; ?>

<?php if (($importantLinks ?? []) !== []) { ?>
    <details class="important-links">
        <summary class="important-links__summary">
            <span class="important-links__title">Wichtige Links</span>
            <span class="important-links__toggle-icon" aria-hidden="true"></span>
        </summary>
        <ul class="important-links__list">
            <?php foreach ($importantLinks as $link) {
                $url = trim((string) $link['url']);
                $mailAddress = Validator::mailtoAddress($url);
                $linkIsMailto = $mailAddress !== null && Validator::isSafeMailto($url);
                $linkIsExternal = !$linkIsMailto && !str_starts_with($url, '/');
                $iconFile = trim((string) ($link['icon_file'] ?? ''));
                ?>
                <li class="important-links__item">
                    <a class="important-links__link"
                       href="<?= Html::linkUrl($url) ?>"
                       <?= $linkIsExternal ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                        <span class="important-links__icon-wrap" aria-hidden="true">
                            <?php if ($iconFile !== '') { ?>
                                <img class="important-links__icon" src="/wichtige-links/icon?id=<?= (int) $link['id'] ?>" alt="" width="16" height="16" loading="lazy">
                            <?php } else {
                                $icon = $linkIsMailto ? 'mail' : 'link';
                                require dirname(__DIR__) . '/partials/icon.php';
                            } ?>
                        </span>
                        <span class="important-links__label"><?= Html::e((string) $link['title']) ?></span>
                        <?php if ($linkIsExternal) { ?>
                            <span class="visually-hidden">(öffnet in einem neuen Tab)</span>
                        <?php } ?>
                    </a>
                    <?php if ($linkIsMailto) { ?>
                        <button type="button" class="important-links__copy"
                                data-copy-text="<?= Html::e($mailAddress) ?>"
                                title="E-Mail-Adresse kopieren"
                                aria-label="E-Mail-Adresse kopieren: <?= Html::e($mailAddress) ?>"
                                hidden>
                            <span class="important-links__copy-icon" aria-hidden="true">
                                <?php $icon = 'copy'; require dirname(__DIR__) . '/partials/icon.php'; ?>
                            </span>
                            <span class="important-links__copy-done" aria-hidden="true">
                                <?php $icon = 'check'; require dirname(__DIR__) . '/partials/icon.php'; ?>
                            </span>
                        </button>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
    </details>
<?php } ?>
