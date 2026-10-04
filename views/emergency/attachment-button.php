<?php
declare(strict_types=1);
use App\Support\Html;

/** Büroklammer-Schaltfläche für Anhänge eines Schritts; erwartet $node. Öffnet das Anhang-Overlay (emergency-plan.js). */
$attachmentList = array_values(array_filter($node['attachments'] ?? [], static fn ($item) => is_array($item) && isset($item['id'], $item['name'], $item['mime'])));
if ($attachmentList !== []) {
    $attachmentLabel = (count($attachmentList) === 1 ? 'Anhang' : count($attachmentList) . ' Anhänge') . ' anzeigen: ' . implode(', ', array_column($attachmentList, 'name'));
    ?><button type="button" class="ep-clip" data-ep-attachments="<?= Html::e(json_encode(array_map(static fn (array $item) => ['id' => $item['id'], 'name' => $item['name'], 'mime' => $item['mime']], $attachmentList), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?>" aria-label="<?= Html::e($attachmentLabel) ?>" title="<?= Html::e($attachmentLabel) ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M14.5 6.5 7.6 13.4a2 2 0 0 0 2.8 2.8l7.3-7.3a3.6 3.6 0 0 0-5.1-5.1L5.1 11.3a5.2 5.2 0 0 0 7.4 7.4l6.4-6.4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><?php if (count($attachmentList) > 1) { ?><span class="ep-clip__count"><?= count($attachmentList) ?></span><?php } ?></button><?php
}
