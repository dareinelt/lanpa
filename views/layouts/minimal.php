<?php

declare(strict_types=1);

use App\Support\Html;

/** @var string $content */
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
?>
<!doctype html>
<html lang="de" data-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Hinweis – <?= Html::e($appName ?? 'Intranet') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <?php if (($themeCss ?? '') !== '') { ?>
        <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?></style>
    <?php } ?>
</head>
<body>
<main class="container main" id="inhalt">
    <?= $content ?>
</main>
</body>
</html>
