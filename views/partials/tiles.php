<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Wiederverwendbare Kachel-Liste (Landingpage und Unterseiten).
 *
 * @var list<array<string,mixed>> $items
 * @var string $descriptionMode
 */

/**
 * Kachel-Hintergrundfarben werden als CSS-Regeln in einem per Nonce
 * freigegebenen <style>-Block ausgeliefert. Inline-Style-Attribute sind durch
 * die Content-Security-Policy (style-src ohne 'unsafe-inline') gesperrt.
 */
$tileRules = [];
foreach ($items as $item) {
    if (empty($item['override_background'])) {
        continue;
    }
    $bgColor = (string) ($item['background_color'] ?? '');
    $bgOpacity = $item['background_opacity'] ?? null;
    $declarations = '';
    if ($bgColor !== '') {
        $declarations .= '--tile-bg:' . Html::e($bgColor) . ';';
    }
    if ($bgOpacity !== null && $bgOpacity !== '' && is_numeric($bgOpacity)) {
        $declarations .= '--tile-bg-opacity:' . (int) $bgOpacity . '%;';
    }
    if ($declarations === '') {
        continue;
    }
    $tileId = (int) $item['id'];
    $tileRules[] = '.tile[data-tile-id="' . $tileId . '"]{' . $declarations . '}';
}
$nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
?>
<?php if ($items === []) { ?>
    <p class="empty-state">Es sind derzeit keine Anwendungen freigeschaltet. Bitte wenden Sie sich an die Administration.</p>
<?php } else { ?>
    <?php if ($tileRules !== []) { ?>
        <style nonce="<?= Html::e($nonce) ?>"><?= implode('', $tileRules) ?></style>
    <?php } ?>
    <ul class="tiles" data-description-mode="<?= Html::e($descriptionMode) ?>">
        <?php foreach ($items as $item) {
            $id = (int) $item['id'];
            $type = (string) $item['type'];
            $isExternal = $type === 'external';
            $isAlarm = $type === 'alarm';
            $isProtected = !empty($item['protected_access']);
            $description = trim((string) ($item['description'] ?? ''));
            $shortDescription = trim((string) ($item['short_description'] ?? ''));
            $detailsId = 'tile-details-' . $id;
            $icon = (string) ($item['icon'] ?? '');

            if ($type === 'subpage') {
                $href = '/unterseite?id=' . $id;
            } elseif ($type === 'page') {
                $href = '/seite?id=' . $id;
            } else {
                $href = Html::url((string) $item['url']);
            }
            ?>
            <li class="tile" data-tile-id="<?= $id ?>">
                <?php if ($isAlarm) { ?>
                <button type="button"
                        class="tile__link tile__link--button"
                        data-alarm-id="<?= $id ?>"
                        data-alarm-title="<?= Html::e((string) $item['title']) ?>"
                        data-alarm-text="<?= Html::e((string) $item['alarm_text']) ?>"
                        data-alarm-group="<?= Html::e((string) $item['alarm_group_description']) ?>"
                        data-alarm-mode="<?= Html::e((string) $item['alarm_group_type']) ?>"
                        <?php if ($isProtected) { ?>data-protected-id="<?= $id ?>" data-nav-title="<?= Html::e((string) $item['title']) ?>"<?php } ?>
                        <?= $description !== '' ? 'aria-describedby="' . Html::e($detailsId) . '"' : '' ?>>
                    <span class="tile__icon-wrap" aria-hidden="true">
                        <?php require __DIR__ . '/icon.php'; ?>
                    </span>
                    <span class="tile__body">
                        <span class="tile__title"><?= Html::e((string) $item['title']) ?></span>
                        <?php if ($shortDescription !== '') { ?>
                            <span class="tile__short"><?= Html::e($shortDescription) ?></span>
                        <?php } ?>
                    </span>
                    <span class="visually-hidden"><?= $isProtected ? '(erfordert einen Zugangscode)' : '(öffnet eine Bestätigung)' ?></span>
                </button>
                <?php } elseif ($isProtected) { ?>
                <button type="button"
                        class="tile__link tile__link--button"
                        data-protected-id="<?= $id ?>"
                        data-nav-href="<?= Html::e($href) ?>"
                        data-nav-external="<?= $isExternal ? '1' : '0' ?>"
                        data-nav-title="<?= Html::e((string) $item['title']) ?>"
                        <?= $description !== '' ? 'aria-describedby="' . Html::e($detailsId) . '"' : '' ?>>
                    <span class="tile__icon-wrap" aria-hidden="true">
                        <?php require __DIR__ . '/icon.php'; ?>
                    </span>
                    <span class="tile__body">
                        <span class="tile__title"><?= Html::e((string) $item['title']) ?></span>
                        <?php if ($shortDescription !== '') { ?>
                            <span class="tile__short"><?= Html::e($shortDescription) ?></span>
                        <?php } ?>
                    </span>
                    <span class="visually-hidden">(erfordert einen Zugangscode)</span>
                </button>
                <?php } else { ?>
                <a class="tile__link"
                   href="<?= Html::e($href) ?>"
                   data-nav-id="<?= $id ?>"
                   <?= $isExternal ? 'target="_blank" rel="noopener noreferrer"' : '' ?>
                   <?= $description !== '' ? 'aria-describedby="' . Html::e($detailsId) . '"' : '' ?>>
                    <span class="tile__icon-wrap" aria-hidden="true">
                        <?php require __DIR__ . '/icon.php'; ?>
                    </span>
                    <span class="tile__body">
                        <span class="tile__title"><?= Html::e((string) $item['title']) ?></span>
                        <?php if ($shortDescription !== '') { ?>
                            <span class="tile__short"><?= Html::e($shortDescription) ?></span>
                        <?php } ?>
                    </span>
                    <?php if ($isExternal) { ?>
                        <span class="visually-hidden">(öffnet in einem neuen Tab)</span>
                    <?php } ?>
                </a>
                <?php } ?>

                <?php if ($description !== '') { ?>
                    <button type="button"
                            class="tile__toggle"
                            aria-expanded="false"
                            aria-controls="<?= Html::e($detailsId) ?>">
                        <span class="tile__toggle-label">Details</span>
                        <span class="tile__toggle-icon" aria-hidden="true"></span>
                    </button>
                    <div class="tile__details" id="<?= Html::e($detailsId) ?>">
                        <p><?= nl2br(Html::e($description)) ?></p>
                    </div>
                <?php } ?>
            </li>
        <?php } ?>
    </ul>
<?php } ?>
