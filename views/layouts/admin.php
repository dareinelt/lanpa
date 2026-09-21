<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var string $content */
/** @var string $themeCss */
$pageTitle = $pageTitle ?? 'Administration';
$activeNav = $activeNav ?? '';
$flashes = $flashes ?? [];
$adminUser = $adminUser ?? null;
$adminRole = $adminRole ?? 'admin';
$documentationEnabled = $documentationEnabled ?? false;
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');

$isAdmin = $adminRole === 'admin';

$navItems = $isAdmin ? [
    'dashboard' => ['/admin', 'Dashboard'],
    'navigation' => ['/admin/navigation', 'Navigation'],
    'important_links' => ['/admin/wichtige-links', 'Wichtige Links'],
    'emergency' => ['/admin/notfallnummern', 'Notfallnummern'],
    'phonebook' => ['/admin/telefonliste', 'Telefonliste'],
    'announcements' => ['/admin/mitteilungen', 'Mitteilungen'],
    'descriptions' => ['/admin/beschreibungen', 'Beschreibungen'],
    'design' => ['/admin/design', 'Design'],
    'ldap' => ['/admin/ad', 'Active Directory'],
    'alarm' => ['/admin/alarmierung', 'Alarmierung'],
    'activation' => ['/admin/aktivierungs-rufnummern', 'Aktivierungs-Rufnummern'],
    'snmp' => ['/admin/snmp', 'SNMP'],
    'statistics' => ['/admin/statistik', 'Statistik'],
    'users' => ['/admin/benutzer', 'Benutzer'],
    'backup' => ['/admin/sicherung', 'Sicherung'],
] : [
    'important_links' => ['/admin/wichtige-links', 'Wichtige Links'],
];
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Html::e($pageTitle) ?> – Administration</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?></style>
</head>
<body class="admin">
<a class="skip-link" href="#inhalt">Zum Inhalt springen</a>

<header class="admin-header">
    <div class="admin-header__inner">
        <a class="brand brand--compact" href="/admin">
            <span class="brand__mark" aria-hidden="true">AD</span>
            <span class="brand__title">Administration</span>
        </a>

        <div class="admin-header__actions">
            <button type="button" class="theme-toggle" data-theme-toggle>
                <span class="theme-toggle__icon" aria-hidden="true"></span>
                <span class="theme-toggle__label">Design wechseln</span>
            </button>
            <a class="button button--ghost" href="/">Zur Landingpage</a>
            <?php if ($adminUser !== null) { ?>
                <form method="post" action="/admin/logout" class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="button button--ghost">Abmelden (<?= Html::e($adminUser) ?>)</button>
                </form>
            <?php } ?>
        </div>
    </div>
</header>

<div class="admin-layout">
    <nav class="admin-nav" aria-label="Administrationsnavigation">
        <ul>
            <?php foreach ($navItems as $key => [$href, $label]) { ?>
                <li>
                    <a href="<?= Html::e($href) ?>" class="admin-nav__link<?= $activeNav === $key ? ' is-active' : '' ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?>>
                        <?= Html::e($label) ?>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </nav>

    <main id="inhalt" class="admin-main">
        <h1 class="admin-title"><?= Html::e($pageTitle) ?></h1>
        <?php require __DIR__ . '/../partials/flash.php'; ?>
        <?= $content ?>
    </main>
</div>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <span><?= Html::e($appName) ?></span>
        <nav class="site-footer__links" aria-label="Fußnavigation">
            <?php if ($documentationEnabled) { ?>
                <a href="/manuals/administratorhandbuch.pdf" target="_blank" rel="noopener">Administratorhandbuch</a>
            <?php } ?>
            <a href="/">Zur Landingpage</a>
        </nav>
    </div>
</footer>

<script src="/assets/js/app.js?v=<?= Html::e($assetVersion ?? '1') ?>" defer></script>
<?php if (($pageScript ?? '') !== '') { ?>
    <script src="/assets/js/<?= Html::e($pageScript) ?>?v=<?= Html::e($assetVersion ?? '1') ?>" defer></script>
<?php } ?>
</body>
</html>
