<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Vollbild-Layout für den Notfallplan-Editor (eigener Browser-Tab, ohne
 * Admin-Seitenmenü). Kopfzeile, Ribbon und Statusleiste liefert die View.
 */
/** @var string $content */
/** @var string $themeCss */
$pageTitle = $pageTitle ?? 'Notfallplan-Editor';
$flashes = $flashes ?? [];
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Html::e($pageTitle) ?> – Notfallplan-Editor</title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= Html::e($assetVersion ?? '1') ?>">
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
