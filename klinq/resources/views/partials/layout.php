<?php
/**
 * Basis-HTML. Erwartet: $content; optional $moduleTitle, $bodyClass, $scripts, $appName
 *
 * Der Fenstertitel lautet immer "<App-Name> - <Modul>" (z. B. "KLINQ - Assetverwaltung");
 * das aktive Modul liefern die Layouts über $moduleTitle.
 */
require_once __DIR__ . '/helpers.php';
$appName = $appName ?? 'KLINQ';
$documentTitle = $appName . (($moduleTitle ?? '') !== '' ? ' - ' . $moduleTitle : '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f6cbd">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= e($appName) ?>">
    <meta name="csrf-token" content="<?= e($csrf ?? '') ?>">
    <title><?= e($documentTitle) ?></title>
    <link rel="manifest" href="<?= e(asset_url('/manifest.webmanifest')) ?>" crossorigin="use-credentials">
    <link rel="icon" href="<?= e(asset_url('/img/icon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(asset_url('/img/icon-192.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/css/app.css')) ?>">
<?php if (($lanpaDesignCss ?? '') !== ''): ?>
    <link rel="stylesheet" href="<?= e($lanpaDesignCss) ?>" id="style-lanpa-design">
    <link rel="stylesheet" href="<?= e(asset_url('/css/themes/lanpa.css')) ?>" id="style-lanpa-tokens">
<?php endif; ?>
<?php foreach (($extraStyles ?? []) as $style): ?>
    <link rel="stylesheet" href="<?= e($style) ?>" id="<?= e('style-' . preg_replace('/[^a-z0-9]+/i', '-', trim(parse_url($style, PHP_URL_PATH) ?: $style, '/'))) ?>">
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php include __DIR__ . '/icons.php'; ?>
<?= $content ?? '' ?>
<script src="<?= e(asset_url('/js/ui.js')) ?>"></script>
<?php foreach (($scripts ?? []) as $script): ?>
    <script src="<?= e(asset_url($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
