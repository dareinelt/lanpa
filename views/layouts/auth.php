<?php

declare(strict_types=1);

use App\Support\Html;

/** @var string $content */
/** @var string $themeCss */
$flashes = $flashes ?? [];
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
?>
<!doctype html>
<html lang="de" data-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Anmeldung – <?= Html::e($appName ?? 'Intranet') ?></title>
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?></style>
</head>
<body class="auth-body">
<main class="auth-wrapper" id="inhalt">
    <?php require __DIR__ . '/../partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="/assets/js/app.js?v=<?= Html::e($assetVersion ?? '1') ?>" defer></script>
</body>
</html>
