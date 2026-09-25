<?php

declare(strict_types=1);

use App\Services\Office\OfficeAppService;
use App\Support\Html;

/**
 * Uebersicht der Office-Apps (Ziel der Office-Kachel).
 *
 * @var array<string,mixed>|null $tile
 * @var list<array<string,mixed>> $apps
 * @var list<array<string,mixed>> $breadcrumb
 * @var string $descriptionMode
 */
$title = $tile !== null ? (string) $tile['title'] : 'Office';
$intro = $tile !== null ? trim((string) ($tile['short_description'] ?? '')) : '';
?>
<?php require dirname(__DIR__) . '/partials/breadcrumb.php'; ?>

<section class="intro">
    <h1 class="intro__title"><?= Html::e($title) ?></h1>
    <?php if ($intro !== '') { ?>
        <p class="intro__text"><?= Html::e($intro) ?></p>
    <?php } ?>
</section>

<?php if ($apps === []) { ?>
    <p class="empty-state">Für Ihr Konto sind derzeit keine Office-Apps freigegeben. Bitte wenden Sie sich an die Administration.</p>
<?php } else { ?>
    <ul class="tiles tiles--office-apps" data-description-mode="<?= Html::e($descriptionMode) ?>">
        <?php foreach ($apps as $app) {
            $icon = (string) $app['icon'];
            $href = OfficeAppService::LAUNCH_PATH . '?app=' . rawurlencode((string) $app['key']);
            $isExternal = !empty($app['external']);
            ?>
            <li class="tile" data-office-app="<?= Html::e((string) $app['key']) ?>">
                <a class="tile__link"
                   href="<?= Html::e($href) ?>"
                   <?= $isExternal ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <span class="tile__icon-wrap" aria-hidden="true">
                        <?php require dirname(__DIR__) . '/partials/icon.php'; ?>
                    </span>
                    <span class="tile__body">
                        <span class="tile__title"><?= Html::e((string) $app['title']) ?></span>
                        <span class="tile__short"><?= Html::e((string) $app['short_description']) ?></span>
                    </span>
                    <?php if ($isExternal) { ?>
                        <span class="visually-hidden">(öffnet in einem neuen Tab)</span>
                    <?php } ?>
                </a>
            </li>
        <?php } ?>
    </ul>
<?php } ?>
