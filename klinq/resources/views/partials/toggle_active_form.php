<?php /** Erwartet: $toggleUrl, $isActive; optional $small */ ?>
<form method="post" action="<?= e($toggleUrl) ?>" class="inline-form" data-confirm="<?= (int) $isActive === 1 ? 'Wirklich deaktivieren?' : 'Wirklich aktivieren?' ?>">
    <?= csrf_field() ?>
    <button type="submit" class="btn <?= (int) $isActive === 1 ? 'btn-ghost' : 'btn-secondary' ?> <?= !empty($small) ? 'btn-sm' : '' ?>" title="<?= (int) $isActive === 1 ? 'Deaktivieren' : 'Aktivieren' ?>">
        <?= (int) $isActive === 1 ? icon('x') . ' Deaktivieren' : icon('check') . ' Aktivieren' ?>
    </button>
</form>
