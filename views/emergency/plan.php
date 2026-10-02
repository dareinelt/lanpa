<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<a href="<?= $base ?>">Zurück zur Planauswahl</a>
<h1><?= Html::e($plan['title']) ?></h1>
<?php $definition = $plan['definition']; require __DIR__ . '/publication.php'; ?>
<p class="ep-warning">Noch kein Ereignis gestartet. Plan und angemeldetes Konto prüfen. Bei unmittelbarer Gefahr zuerst den örtlich festgelegten Notruf nutzen.</p>
<p class="ep-pre"><?= Html::e($plan['definition']['description']) ?></p>
<details class="card" open>
    <summary>Ablauf prüfen (Version <?= (int) $plan['revision'] ?>)</summary>
    <div class="ep-diagram" data-ep-static-diagram></div>
    <textarea hidden data-ep-definition><?= Html::e(json_encode($plan['definition'], JSON_THROW_ON_ERROR)) ?></textarea>
    <ol>
        <?php foreach ($plan['definition']['nodes'] as $node) { ?>
            <li><strong><?= Html::e($node['title']) ?></strong> – <?= Html::e($node['text']) ?><?php if ($node['owner'] !== '') { ?> (<?= Html::e($node['owner']) ?>)<?php } ?></li>
        <?php } ?>
    </ol>
</details>
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
