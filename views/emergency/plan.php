<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
$preview = $preview ?? false;
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<?php if (!$preview) { ?><a href="<?= $base ?>">Zurück zur Planauswahl</a><?php } ?>
<h1><?= Html::e($plan['title']) ?></h1>
<?php if (!$preview) { $definition = $plan['definition']; require __DIR__ . '/publication.php'; } ?>
<p class="ep-warning">Noch kein Ereignis gestartet. Plan und angemeldetes Konto prüfen. Bei unmittelbarer Gefahr zuerst den örtlich festgelegten Notruf nutzen.</p>
<p class="ep-pre"><?= Html::e($plan['definition']['description']) ?></p>
<details class="card" open>
    <summary>Ablauf prüfen (<?= $preview ? 'ungespeicherte Vorschau' : 'Version ' . (int) $plan['revision'] ?>)</summary>
    <div class="ep-diagram" data-ep-static-diagram></div>
    <textarea hidden data-ep-definition><?= Html::e(json_encode($plan['definition'], JSON_THROW_ON_ERROR)) ?></textarea>
    <ol>
        <?php foreach ($plan['definition']['nodes'] as $node) { ?>
            <li><strong><?= Html::e($node['title']) ?></strong> – <?= Html::e($node['text']) ?><?php if ($node['owner'] !== '') { ?> (<?= Html::e($node['owner']) ?>)<?php } ?> <?php require __DIR__ . '/attachment-button.php'; ?></li>
        <?php } ?>
    </ol>
</details>
<?php if ($preview) { ?>
<div class="card form ep-start">
    <h2>Notfallereignis simulieren</h2>
    <p>In der echten Anwendung folgt hier die AD-Kennwortbestätigung und das KAEP-Team erhält eine E-Mail.
        Die Vorschau überspringt beides. Bitte kein Kennwort eingeben.</p>
    <button type="button" class="button button--danger" data-ep-preview-start>Ablauf jetzt simulieren</button>
</div>
<?php } else { ?>
<form method="post" action="<?= $base ?>/start" class="card form ep-start">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $plan['id'] ?>">
    <input type="hidden" name="revision" value="<?= (int) $plan['revision'] ?>">
    <input type="hidden" name="request_key" value="<?= Html::e($requestKey) ?>">
    <h2>Notfallereignis verbindlich auslösen</h2>
    <p>Angemeldet: <strong><?= Html::e($user['display_name']) ?></strong> (<?= Html::e($user['office_uid']) ?>). Das Kennwort wird direkt im zugehörigen AD geprüft und nicht gespeichert.</p>
    <p>Das KAEP-Team erhält automatisch eine E-Mail-Benachrichtigung. SMS werden <strong>nicht automatisch</strong> versendet.</p>
    <?php if (!$secure || !empty($user['fake'])) { ?>
        <p class="flash flash--error"><?= !$secure ? 'Bitte diese Seite über HTTPS öffnen.' : 'Simulierte Anmeldung: Auslösen ist gesperrt.' ?></p>
    <?php } ?>
    <label for="ep-password">AD-Kennwort des oben angezeigten Kontos</label>
    <input id="ep-password" type="password" name="password" required maxlength="4096" autocomplete="current-password" <?= !$secure || !empty($user['fake']) ? 'disabled' : '' ?>>
    <button class="button button--danger" <?= !$secure || !empty($user['fake']) ? 'disabled' : '' ?>>Jetzt Notfallereignis auslösen</button>
</form>
<?php } ?>
