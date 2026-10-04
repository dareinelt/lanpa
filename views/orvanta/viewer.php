<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Orvanta: Anzeige eines E-Mail-Anhangs im Euro-Office-DocumentServer (Lesemodus).
 * Der DocumentServer laedt die Datei ueber den signierten Link aus dem Intranet.
 */
/** @var array{api_url:string,config:array<string,mixed>} $viewer */
/** @var array{uid:string,impersonate:string,attachment_id:string,name:string} $attachment */
/** @var string $token */
/** @var string $assetVersion */
$downloadUrl = '/office/orvanta/anhang/datei?' . http_build_query(['token' => $token]);
?>
<div class="ov-office ov-viewer" data-orvanta-viewer
     data-api="<?= Html::e($viewer['api_url']) ?>"
     data-config="<?= Html::e((string) json_encode($viewer['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
     data-download="<?= Html::e($downloadUrl) ?>">
    <header class="ov-titlebar">
        <a class="ov-titlebar__brand" href="/office/orvanta" title="Zurück zu Orvanta">
            <img src="/assets/images/orvanta-logo.png?v=<?= Html::e($assetVersion) ?>" alt="" class="ov-logo">
            <span class="ov-titlebar__name">Orvanta</span>
        </a>
        <span class="ov-titlebar__title" title="<?= Html::e($attachment['name']) ?>"><?= Html::e($attachment['name']) ?></span>
        <div class="ov-titlebar__actions">
            <a class="ov-qb" href="<?= Html::e($downloadUrl) ?>" download>Herunterladen</a>
        </div>
    </header>
    <div class="ov-viewer__frame">
        <div id="ov-viewer"></div>
        <p class="ov-viewer__fallback" data-ov-viewer-fallback hidden>
            Der Euro-Office-DocumentServer ist derzeit nicht erreichbar.
            <a href="<?= Html::e($downloadUrl) ?>" download>Anhang herunterladen</a>
        </p>
    </div>
</div>
