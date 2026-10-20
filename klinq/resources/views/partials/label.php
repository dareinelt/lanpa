<?php
/** Ein Etikett. Erwartet: $label (LabelService::labelFor), $layout */
require_once __DIR__ . '/helpers.php';
?>
<div class="label label-pos-<?= e($layout['qr_position']) ?>" data-asset-id="<?= (int) $label['id'] ?>">
    <div class="label-qr"><?= $label['qr_svg'] ?></div>
    <div class="label-text">
        <?php if (!empty($label['logo_url'])): ?><img class="label-logo" src="<?= e($label['logo_url']) ?>" alt=""><?php endif; ?>
        <div class="label-company"><?= e($label['company']) ?></div>
        <div class="label-inventory"><?= e($label['inventory_number']) ?></div>
        <?php if ($label['extra'] !== null): ?><div class="label-extra"><?= e($label['extra']) ?></div><?php endif; ?>
    </div>
</div>
