<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Vollbild-Layout für den Notfallplan-Editor (eigener Browser-Tab, ohne
 * Admin-Seitenmenü). Kopfzeile, Ribbon und Statusleiste liefert die View.
 */
/** @var string $content */
/** @var string $themeCss */
/** @var string $titleSuffix Anwendungsname hinter dem Seitentitel (leer = keiner) */
/** @var list<string> $extraStyles Zusaetzliche Stylesheets aus /assets/css/ */
$pageTitle = $pageTitle ?? 'Notfallplan-Editor';
$titleSuffix = $titleSuffix ?? 'Notfallplan-Editor';
$flashes = $flashes ?? [];
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Html::e($pageTitle) ?><?= $titleSuffix !== '' ? ' – ' . Html::e($titleSuffix) : '' ?></title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= Html::e($assetVersion ?? '1') ?>">
<?php foreach (($extraStyles ?? []) as $style) { ?>
    <link rel="stylesheet" href="/assets/css/<?= Html::e($style) ?>?v=<?= Html::e($assetVersion ?? '1') ?>">
<?php } ?>
    <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?></style>
</head>
<body class="admin ep-shell">
<a class="skip-link" href="#inhalt">Zum Inhalt springen</a>
<main id="inhalt" class="ep-shell__main">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="/assets/js/app.js?v=<?= Html::e($assetVersion ?? '1') ?>" defer></script>
<?php if (($pageScript ?? '') !== '') { ?>
    <script src="/assets/js/<?= Html::e($pageScript) ?>?v=<?= Html::e($assetVersion ?? '1') ?>" defer></script>
<?php } ?>
</body>
</html>
