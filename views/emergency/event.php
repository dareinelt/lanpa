<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
$preview = $preview ?? false;
$updateUrl = $preview ? '/admin/notfallplan/vorschau' : $base . '/massnahme';
$labels = ['open' => 'Offen', 'in_progress' => 'In Arbeit', 'blocked' => 'Blockiert', 'done' => 'Erledigt'];
$types = ['action' => 'Maßnahme', 'contact' => 'Kontakt', 'decision' => 'Entscheidung', 'checklist' => 'Checkliste', 'note' => 'Hinweis', 'sms' => 'SMS-Alarmierung'];
$closed = $event['status'] === 'closed';
$done = count(array_filter($event['state'], static fn ($item) => $item['status'] === 'done'));
$skipped = count(array_filter($ready, static fn ($value) => $value === 'skipped'));
$titles = array_column($event['snapshot']['nodes'], 'title', 'id');
$mailLabels = ['queued' => 'Wartet auf Versand', 'sending' => 'Versand läuft', 'sent' => 'SMTP-Annahme bestätigt', 'failed' => 'Fehlgeschlagen'];
$hiddenFields = static function () use ($event): void { ?>
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
    <input type="hidden" name="revision" value="<?= (int) $event['revision'] ?>">
<?php };
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<section <?= $preview ? 'data-ep-simulation' : 'data-ep-event' ?> data-revision="<?= (int) $event['revision'] ?>"<?php if (!$preview) { ?> data-status-url="<?= $base ?>/stand?id=<?= (int) $event['id'] ?>"<?php } ?>>
    <div class="toolbar">
        <?php if (!$preview) { ?>
        <a class="button button--ghost" href="<?= $base ?>">Ereignisübersicht</a>
        <a class="button button--ghost" href="<?= $base ?>/anleitung">Kurzanleitung</a>
        <?php } ?>
        <button class="button button--ghost" type="button" data-ep-print>Drucken</button>
        <?php if ($manager) { ?><a class="button button--ghost" href="<?= $base ?>/export?id=<?= (int) $event['id'] ?>">Protokoll als CSV</a><?php } ?>
    </div>
    <?php if (!$manager) { ?><h1><?= $preview ? 'Simulation' : '#' . (int) $event['id'] ?> <?= Html::e($event['title']) ?></h1><?php } ?>
    <?php if (!$preview) { $definition = $event['snapshot']; require __DIR__ . '/publication.php'; } ?>
    <div class="ep-summary">
        <strong><?= $closed ? 'Abgeschlossen' : 'Laufendes Ereignis' ?></strong>
        <span><?= $done ?> / <?= count($titles) - $skipped ?> Maßnahmen erledigt · <?= $skipped ?> entfallen</span>
        <span>Start: <?= Html::e($event['started_at']) ?> UTC · <?= Html::e($event['actor']) ?></span>
        <?php if ($closed) { ?><span>Ende: <?= Html::e($event['closed_at']) ?> UTC</span><?php } ?>
    </div>
    <?php if (!$preview) { ?>
    <div class="ep-live" role="status" aria-live="polite" data-ep-live>Stand geladen. Aktualitätsprüfung alle 10 Sekunden.</div>
    <button type="button" class="button button--primary" data-ep-refresh hidden>Aktuellen Stand laden</button>
    <?php } ?>
    <p class="ep-warning">Status und Kommentare sind Einsatzdokumentation. „SMS angenommen“ bestätigt weder Zustellung noch Reaktion. Bei Störungen Ersatzmeldeweg nutzen.</p>
    <details class="card">
        <summary>KAEP-E-Mail-Benachrichtigungen (<?= count($notifications) ?>)</summary>
        <?php if ($preview) { ?><p>SIMULATION: Keine E-Mail eingeplant oder versendet.</p>
        <?php } elseif ($notifications === []) { ?><p class="flash flash--error">Keine E-Mail-Empfänger hinterlegt. KAEP-Team anderweitig informieren.</p><?php } ?>
        <ul><?php foreach ($notifications as $mail) { ?><li><?= Html::e($mail['recipient']) ?>: <strong><?= Html::e($mailLabels[$mail['status']] ?? $mail['status']) ?></strong> (<?= (int) $mail['attempts'] ?>/3) – <?= Html::e($mail['message']) ?></li><?php } ?></ul>
    </details>
    <details class="card" open>
        <summary>Interaktives Ablaufdiagramm</summary>
        <div class="ep-diagram" data-ep-static-diagram></div>
        <textarea hidden data-ep-definition><?= Html::e(json_encode($event['snapshot'], JSON_THROW_ON_ERROR)) ?></textarea>
        <textarea hidden data-ep-state><?= Html::e(json_encode(['state' => $event['state'], 'ready' => $ready], JSON_THROW_ON_ERROR)) ?></textarea>
    </details>
    <div class="ep-measures">
    <?php foreach ($event['snapshot']['nodes'] as $node) {
        $id = $node['id'];
        $state = $event['state'][$id] ?? ['status' => 'open', 'answer' => '', 'checks' => []];
        $availability = $ready[$id];
        $editable = !$closed && $availability === 'ready' && $state['status'] !== 'done';
        $sms = $event['sms'][$id] ?? null;
        $due = $node['minutes'] > 0 ? strtotime($event['started_at'] . ' UTC') + $node['minutes'] * 60 : null;
        ?>
        <article class="ep-measure ep-measure--<?= Html::e($state['status']) ?><?= $availability === 'skipped' ? ' ep-measure--skipped' : '' ?>" id="node-<?= Html::e($id) ?>">
            <header><span><?= Html::e($types[$node['type']]) ?></span><strong><?= $availability === 'skipped' ? 'Entfällt (anderer Zweig)' : Html::e($labels[$state['status']]) ?></strong></header>
            <h2><?= Html::e($node['title']) ?></h2>
            <p class="ep-pre"><?= Html::e($node['text']) ?></p>
            <?php if ($node['owner'] !== '') { ?><p><strong>Zuständig:</strong> <?= Html::e($node['owner']) ?></p><?php } ?>
            <?php if ($node['phone'] !== '') { ?><p><strong>Telefon:</strong> <?= Html::e($node['phone']) ?></p><?php } ?>
            <?php if ($node['link'] !== '') { ?><p><a href="<?= Html::e($node['link']) ?>" target="_blank" rel="noopener noreferrer">Weiterführende Information öffnen</a></p><?php } ?>
            <?php if ($due !== null) { ?><p class="<?= !$closed && $state['status'] !== 'done' && $availability !== 'skipped' && $due < time() ? 'ep-overdue' : '' ?>">Zielzeit: <?= gmdate('H:i', $due) ?> UTC (<?= (int) $node['minutes'] ?> Min. ab Start)</p><?php } ?>
            <?php if ($node['dependencies'] !== []) { ?>
                <p>Voraussetzungen (<?= $node['join'] === 'any' ? 'mindestens eine' : 'alle' ?>):
                    <?php foreach ($node['dependencies'] as $edge) { ?><a href="#node-<?= Html::e($edge['id']) ?>"><?= Html::e($titles[$edge['id']]) ?><?= $edge['when'] === 'always' ? '' : ' = ' . ($edge['when'] === 'yes' ? 'Ja' : 'Nein') ?></a>; <?php } ?>
                </p>
            <?php } ?>
            <?php if ($availability === 'waiting') { ?><p>Wartet auf vorherige Maßnahmen / Entscheidungen.</p><?php } ?>
            <?php if ($state['answer'] !== '') { ?><p><strong>Entscheidung: <?= $state['answer'] === 'yes' ? 'Ja' : 'Nein' ?></strong></p><?php } ?>
            <?php if ($node['type'] === 'sms') { ?>
                <div class="ep-sms">
                    <p><strong>SMS an <?= Html::e($node['alarm']['alarm_group_description'] ?: $node['alarm']['alarm_group_number']) ?></strong> (<?= Html::e($node['alarm']['alarm_group_number']) ?>)</p>
                    <blockquote><?= Html::e($node['alarm']['alarm_text']) ?></blockquote>
                    <?php if ($sms !== null) { ?><p class="<?= $sms['status'] !== 'success' ? 'ep-overdue' : '' ?>"><?= Html::e($sms['message']) ?> <?= $preview ? 'Kein Gateway kontaktiert.' : ($sms['status'] === 'success' ? 'Gateway-Annahme bestätigt; keine Zustellbestätigung.' : 'Nicht erneut auslösen. Gateway prüfen und Ersatzmeldeweg dokumentieren.') ?></p><?php } ?>
                    <?php if ($editable && $sms === null) { ?>
                        <form action="<?= $updateUrl ?>" method="post" data-ep-update data-ep-confirm="<?= $preview ? 'SMS-Versand nur simulieren? Es wird keine Nachricht versendet.' : 'Diese SMS jetzt wirklich versenden?' ?>">
                            <?php $hiddenFields(); ?><input type="hidden" name="node" value="<?= Html::e($id) ?>"><input type="hidden" name="action" value="sms">
                            <button class="button button--danger"><?= $preview ? 'SMS-Versand simulieren' : 'SMS jetzt separat bestätigen und senden' ?></button>
                            <p data-ep-result role="alert"></p>
                        </form>
                    <?php } ?>
                </div>
            <?php } ?>
            <form action="<?= $updateUrl ?>" method="post" data-ep-update>
                <?php $hiddenFields(); ?><input type="hidden" name="node" value="<?= Html::e($id) ?>">
                <?php if ($node['type'] === 'checklist') { ?>
                    <fieldset <?= !$editable ? 'disabled' : '' ?>><legend>Prüfpunkte</legend>
                        <?php foreach ($node['checks'] as $index => $check) { ?><label class="ep-check"><input type="checkbox" name="checks[]" value="<?= $index ?>" <?= in_array($index, $state['checks'], true) ? 'checked' : '' ?>> <?= Html::e($check) ?></label><?php } ?>
                        <p><?= count($state['checks']) ?> / <?= count($node['checks']) ?> Prüfpunkte bestätigt.<?= $editable ? ' Änderungen mit „Status und Kommentar speichern“ übernehmen.' : '' ?></p>
                    </fieldset>
                <?php } ?>
                <?php if ($editable) { ?>
                    <label>Status <select name="status"><?php foreach ($labels as $value => $label) { ?><option value="<?= $value ?>" <?= $value === $state['status'] ? 'selected' : '' ?>><?= $label ?></option><?php } ?></select></label>
                    <?php if ($node['type'] === 'decision') { ?><label>Entscheidung <select name="answer"><option value="">Bitte wählen</option><option value="yes">Ja</option><option value="no">Nein</option></select></label><?php } ?>
                <?php } ?>
                <?php if (!$closed) { ?>
                    <label>Kommentar / Rückmeldung <textarea name="comment" rows="2" maxlength="2000" placeholder="z. B. GF erreicht, Einbahnstraßenprinzip beauftragt"></textarea></label>
                    <?php if ($editable) { ?><button class="button button--primary" name="action" value="status">Status und Kommentar speichern</button><?php } ?>
                    <button class="button button--ghost" name="action" value="comment">Nur Kommentar ergänzen</button>
                    <p data-ep-result role="alert"></p>
                <?php } ?>
            </form>
        </article>
    <?php } ?>
    </div>
    <?php if (!$closed) { ?>
        <details class="card">
            <summary>Ereignis abschließen</summary>
            <form action="<?= $updateUrl ?>" method="post" data-ep-update data-ep-confirm="Ereignis unwiderruflich abschließen? Danach sind keine Änderungen mehr möglich.">
                <?php $hiddenFields(); ?><input type="hidden" name="action" value="close">
                <label>Abschlussbegründung (bei offenen Maßnahmen erforderlich)<textarea name="comment" maxlength="2000" rows="3"></textarea></label>
                <button class="button button--danger">Ereignis abschließen</button><p data-ep-result role="alert"></p>
            </form>
        </details>
    <?php } ?>
    <h2>Checklisten-Auswertung</h2>
    <p>Einzeln bestätigte Prüfpunkte mit Person und Zeitpunkt. Rücknahmen und erneute Bestätigungen bleiben im Protokoll und CSV nachvollziehbar.</p>
    <div class="table-wrapper"><table class="table"><thead><tr><th>Maßnahme</th><th>Prüfpunkt</th><th>Stand</th><th>Bestätigt durch</th><th>Zeit (UTC)</th></tr></thead><tbody>
    <?php foreach ($event['snapshot']['nodes'] as $checkNode) {
        if ($checkNode['type'] !== 'checklist') { continue; }
        foreach ($checkNode['checks'] as $index => $label) {
            $checked = in_array($index, $event['state'][$checkNode['id']]['checks'] ?? [], true);
            $detail = $event['state'][$checkNode['id']]['check_details'][$index] ?? [];
            ?>
            <tr><td><?= Html::e($checkNode['title']) ?></td><td><?= $index + 1 ?>. <?= Html::e($label) ?></td><td><?= $ready[$checkNode['id']] === 'skipped' ? 'Entfällt' : ($checked ? 'Bestätigt' : 'Offen') ?></td><td><?= Html::e($detail['actor'] ?? '–') ?></td><td><?= Html::e($detail['at'] ?? '–') ?></td></tr>
        <?php }
    } ?>
    </tbody></table></div>
    <h2>Chronologisches Ereignisprotokoll</h2>
    <p>Alle Zeiten in UTC. Abgeschlossene Ereignisse bleiben unveränderlich lesbar.</p>
    <ol class="ep-log">
        <?php foreach ($logs as $log) { ?><li><time><?= Html::e($log['created_at']) ?></time> · <strong><?= Html::e($log['actor']) ?></strong><?php if (isset($titles[$log['node_id']])) { ?> · <?= Html::e($titles[$log['node_id']]) ?><?php } ?><p class="ep-pre"><?= Html::e($log['message']) ?></p></li><?php } ?>
    </ol>
</section>
<noscript><p class="flash flash--error">JavaScript ist für Statusänderungen und Live-Prüfung erforderlich. Aktuellen Stand durch Neuladen prüfen; bei Ausfall Ersatzdokumentation nutzen.</p></noscript>
