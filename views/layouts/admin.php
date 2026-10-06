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
$openIncidents = (int) ($openIncidents ?? 0);
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');

$isAdmin = $adminRole === 'admin';

$navItems = $isAdmin ? [
    'dashboard' => ['/admin', 'Dashboard'],
    'navigation' => ['/admin/navigation', 'Navigation'],
    'important_links' => ['/admin/wichtige-links', 'Wichtige Links'],
    'emergency' => ['/admin/notfallnummern', 'Notfallnummern'],
    'emergency_plan' => ['/admin/notfallplan', 'Notfallplan / KAEP'],
    'phonebook' => ['/admin/telefonliste', 'Telefonliste'],
    'announcements' => ['/admin/mitteilungen', 'Mitteilungen'],
    'descriptions' => ['/admin/beschreibungen', 'Beschreibungen'],
    'design' => ['/admin/design', 'Design'],
    'ldap' => ['/admin/ad', 'Active Directory'],
    'alarm' => ['/admin/alarmierung', 'Alarmierung'],
    'activation' => ['/admin/aktivierungs-rufnummern', 'Aktivierungs-Rufnummern'],
    'office' => ['/admin/office', 'Office', [
        'office' => ['/admin/office', 'Status & Diagnose'],
        'office_footer' => ['/admin/office/fusszeile', 'Fußzeile und Einstieg'],
        'office_ai' => ['/admin/office/ki', 'Lokale KI'],
        'office_appstore' => ['/admin/office/app-store', 'Nextcloud-App-Store'],
        'office_apps' => ['/admin/office/apps', 'Office-Apps und Berechtigungen'],
        'office_orvanta' => ['/admin/office/orvanta', 'Orvanta – Mail & Kalender'],
        'office_signatures' => ['/admin/office/signaturen', 'Signaturvorlagen'],
        'office_tile' => ['/admin/office/kachel', 'Kachel im Intranet'],
        'office_backup' => ['/admin/office/sicherung', 'Sicherung'],
    ]],
    'quota' => ['/admin/speicherplatz', 'Speicherplatz (Quota)'],
    'drives' => ['/admin/netzlaufwerke', 'Netzlaufwerke'],
    'storage' => ['/admin/speicher-ha', 'Speicher (HA)'],
    'incidents' => ['/admin/vorfaelle', 'Vorfälle'],
    'statistics' => ['/admin/statistik', 'Statistik'],
    'system' => ['/admin/zertifikate', 'System', [
        'certificates' => ['/admin/zertifikate', 'Zertifikate (HTTPS)'],
        'snmp' => ['/admin/snmp', 'SNMP'],
        'users' => ['/admin/benutzer', 'Benutzer'],
        'backup' => ['/admin/sicherung', 'Sicherung'],
        'smtp' => ['/admin/smtp', 'E-Mail (SMTP)'],
    ]],
] : ($adminRole === 'kaep' ? [
    'emergency_plan' => ['/admin/notfallplan', 'Notfallplan / KAEP'],
] : [
    'important_links' => ['/admin/wichtige-links', 'Wichtige Links'],
]);
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
            <?php foreach ($navItems as $key => $item) { ?>
                <?php [$href, $label] = $item; $children = $item[2] ?? null; ?>
                <li>
                    <?php if (is_array($children)) { ?>
                        <?php $groupActive = $key === $activeNav || array_key_exists($activeNav, $children); ?>
                        <details class="admin-nav__group"<?= $groupActive ? ' open' : '' ?>>
                            <summary class="admin-nav__link admin-nav__summary<?= $groupActive ? ' is-active' : '' ?>">
                                <?= Html::e($label) ?>
                            </summary>
                            <ul class="admin-nav__sub">
                                <?php foreach ($children as $childKey => [$childHref, $childLabel]) { ?>
                                    <li>
                                        <a href="<?= Html::e($childHref) ?>" class="admin-nav__link admin-nav__link--sub<?= $activeNav === $childKey ? ' is-active' : '' ?>"<?= $activeNav === $childKey ? ' aria-current="page"' : '' ?>>
                                            <?= Html::e($childLabel) ?>
                                        </a>
                                    </li>
                                <?php } ?>
                            </ul>
                        </details>
                    <?php } else { ?>
                        <a href="<?= Html::e($href) ?>" class="admin-nav__link<?= $activeNav === $key ? ' is-active' : '' ?>"<?= $activeNav === $key ? ' aria-current="page"' : '' ?>>
                            <?= Html::e($label) ?>
                            <?php if ($key === 'incidents' && $openIncidents > 0) { ?>
                                <span class="admin-nav__badge" title="Offene Sicherheitsvorfälle"><?= $openIncidents ?><span class="visually-hidden"> offen</span></span>
                            <?php } ?>
                        </a>
                    <?php } ?>
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
<?php foreach ((array) ($extraScripts ?? []) as $extraScript) { ?>
    <script src="/assets/js/<?= Html::e((string) $extraScript) ?>?v=<?= Html::e($assetVersion ?? '1') ?>" defer></script>
<?php } ?>
</body>
</html>
