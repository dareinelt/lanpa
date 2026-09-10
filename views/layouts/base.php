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
$siteSubtitleVisible = $siteSubtitleVisible ?? true;
$flashes = $flashes ?? [];
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
?>
<!doctype html>
<html lang="de" data-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Html::e($pageTitle) ?> – <?= Html::e($siteTitle ?? $appName) ?></title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion) ?>">
    <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?></style>
</head>
<body>
<a class="skip-link" href="#inhalt">Zum Inhalt springen</a>

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
        <span><?= Html::e($siteTitle ?? $appName) ?></span>
        <a href="/admin">Administration</a>
    </div>
</footer>

<script src="/assets/js/app.js?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php if (($pageScript ?? '') !== '') { ?>
    <script src="/assets/js/<?= Html::e($pageScript) ?>?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php } ?>
</body>
</html>
