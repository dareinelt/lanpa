<?php
declare(strict_types=1);
use App\Support\Html;
$publication = $definition['publication'] ?? null;
?>
<?php if ($publication !== null) { ?>
    <p class="ep-publication">
        Autor<?= count($publication['authors']) === 1 ? '' : 'en' ?>: <?= Html::e(implode(', ', $publication['authors'])) ?>
        · Freigegeben durch: <?= Html::e($publication['approved_by']) ?>
        · Version <?= (int) $publication['revision'] ?>
        · <?= Html::e($publication['approved_at']) ?> UTC
    </p>
<?php } else { ?>
    <p class="ep-publication">Historische Planversion ohne Vier-Augen-Freigabenachweis.</p>
<?php } ?>
