<?php

declare(strict_types=1);

use App\Support\Html;

/** @var list<array{type:string,message:string}> $flashes */
$flashes = $flashes ?? [];

if ($flashes !== []) { ?>
    <div class="flash-stack" role="status" aria-live="polite">
        <?php foreach ($flashes as $flash) {
            $type = in_array($flash['type'] ?? '', ['success', 'error', 'info'], true) ? $flash['type'] : 'info'; ?>
            <p class="flash flash--<?= Html::e($type) ?>"><?= Html::e((string) $flash['message']) ?></p>
        <?php } ?>
    </div>
<?php }
