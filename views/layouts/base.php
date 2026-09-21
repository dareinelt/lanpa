<?php

declare(strict_types=1);

use App\Support\Html;

/** @var string $content */
/** @var string $themeCss */
/** @var string $assetVersion */
/** @var string $appName */
$pageTitle = $pageTitle ?? ($siteTitle ?? $appName);
$activeNav = $activeNav ?? '';
$hasLogo = $hasLogo ?? false;
$hasBackground = $hasBackground ?? false;
$siteSubtitleVisible = $siteSubtitleVisible ?? true;
$flashes = $flashes ?? [];
$announcements = $announcements ?? [];
$documentationEnabled = $documentationEnabled ?? false;
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Html::e($pageTitle) ?> – <?= Html::e($siteTitle ?? $appName) ?></title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion) ?>">
    <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?><?php if ($hasBackground) { ?>:root{--watermark-image:url('/hintergrundbild');}<?php } ?></style>
</head>
<body data-page="<?= Html::e($activeNav) ?>">
<a class="skip-link" href="#inhalt">Zum Inhalt springen</a>

<?php if ($hasBackground) { ?>
    <div class="site-watermark" aria-hidden="true"></div>
<?php } ?>

<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="/">
            <?php if ($hasLogo) { ?>
                <img class="brand__logo" src="/logo" alt="<?= Html::e($siteTitle ?? $appName) ?>">
            <?php } else { ?>
                <span class="brand__mark" aria-hidden="true"><?= Html::e(mb_substr((string) ($siteTitle ?? $appName), 0, 2)) ?></span>
            <?php } ?>
            <span class="brand__text">
                <span class="brand__title"><?= Html::e($siteTitle ?? $appName) ?></span>
                <?php if (($siteSubtitleVisible ?? true) && ($siteSubtitle ?? '') !== '') { ?>
                    <span class="brand__subtitle"><?= Html::e($siteSubtitle) ?></span>
                <?php } ?>
            </span>
        </a>

        <nav class="site-nav" aria-label="Hauptnavigation">
            <?php if ($announcements !== []) { ?>
                <details class="site-nav__dropdown" data-announcement-menu>
                    <summary class="site-nav__link site-nav__summary">
                        <span class="site-nav__summary-label">Mitteilungen</span>
                        <span class="site-nav__summary-count" aria-hidden="true"><?= count($announcements) ?></span>
                        <span class="site-nav__chevron" aria-hidden="true"></span>
                    </summary>
                    <ul class="site-nav__dropdown-list">
                        <?php foreach ($announcements as $announcementItem) {
                            $announcementItemId = (int) $announcementItem['id']; ?>
                            <li>
                                <button type="button"
                                        class="site-nav__dropdown-item"
                                        data-announcement-open
                                        data-announcement-id="<?= $announcementItemId ?>">
                                    <?= Html::e((string) $announcementItem['title']) ?>
                                </button>
                            </li>
                        <?php } ?>
                    </ul>
                </details>
            <?php } ?>
            <a class="site-nav__link<?= $activeNav === 'home' ? ' is-active' : '' ?>" href="/"<?= $activeNav === 'home' ? ' aria-current="page"' : '' ?>>Start</a>
            <a class="site-nav__link<?= $activeNav === 'phonebook' ? ' is-active' : '' ?>" href="/telefonliste"<?= $activeNav === 'phonebook' ? ' aria-current="page"' : '' ?>>Telefonliste</a>
            <button type="button" class="theme-toggle" data-theme-toggle aria-live="polite">
                <span class="theme-toggle__icon" aria-hidden="true"></span>
                <span class="theme-toggle__label">Design wechseln</span>
            </button>
        </nav>
    </div>
</header>

<main id="inhalt" class="container main">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <span><?= Html::e(($footerText ?? '') !== '' ? $footerText : ($siteTitle ?? $appName)) ?></span>
        <nav class="site-footer__links" aria-label="Fußnavigation">
            <?php if ($documentationEnabled) { ?>
                <a href="/manuals/anwenderhandbuch.pdf" target="_blank" rel="noopener">Anwenderhandbuch</a>
            <?php } ?>
            <a href="/admin">Administration</a>
        </nav>
    </div>
</footer>

<?php if ($announcements !== []) { ?>
    <?php foreach ($announcements as $announcementItem) {
        $announcementItemId = (int) $announcementItem['id'];
        $announcementItemVersion = (string) ($announcementItem['updated_at'] ?? $announcementItem['created_at'] ?? '');
        ?>
        <template class="announcement-template"
                  data-announcement-id="<?= $announcementItemId ?>"
                  data-announcement-version="<?= Html::e($announcementItemVersion) ?>">
            <h2 class="announcement-overlay__title"><?= Html::e((string) $announcementItem['title']) ?></h2>
            <p class="announcement-overlay__text"><?= nl2br(Html::e((string) $announcementItem['message'])) ?></p>
        </template>
    <?php } ?>
    <div class="announcement-overlay"
         data-announcement-overlay
         role="dialog"
         aria-modal="true"
         aria-labelledby="announcement-overlay-title"
         hidden>
        <div class="announcement-overlay__box">
            <div data-announcement-content></div>
            <div class="announcement-overlay__actions">
                <button type="button" class="button button--primary" data-announcement-dismiss>Verstanden</button>
            </div>
        </div>
    </div>
<?php } ?>

<script src="/assets/js/app.js?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php if (($pageScript ?? '') !== '') { ?>
    <script src="/assets/js/<?= Html::e($pageScript) ?>?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php } ?>
</body>
</html>
