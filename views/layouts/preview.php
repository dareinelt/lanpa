<?php

declare(strict_types=1);

use App\Support\Html;

/** @var string $content */
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
$hasBackground = $hasBackground ?? false;
?>
<!doctype html>
<html lang="de" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Vorschau – <?= Html::e($appName ?? 'Intranet') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= Html::e($assetVersion ?? '1') ?>">
    <style nonce="<?= Html::e($nonce) ?>"><?= $themeCss ?? '' ?>html,body{overflow:hidden}body{min-height:0;margin:0;padding:1rem;background:var(--color-background)}.tiles{margin:0}</style>
</head>
<body class="tile-preview">
<main id="inhalt">
    <?= $content ?>
</main>
</body>
</html>
