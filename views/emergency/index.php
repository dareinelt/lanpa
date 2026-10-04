<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<?php if (!$manager) { ?><h1>Notfallplan</h1><?php } ?>
<div class="toolbar">
    <?php if ($manager) { ?><a class="button button--primary" href="<?= $base ?>/bearbeiten" target="_blank" rel="noopener">Neuen Notfallplan entwerfen (neuer Tab)</a><?php } ?>
    <a class="button button--ghost" href="<?= $base ?>/anleitung">Kurzanleitung</a>
    <?php if ($manager) { ?><a class="button button--ghost" href="/kaep-dashboard" target="_blank" rel="noopener">KAEP-Dashboard</a><?php } ?>
</div>
<p class="ep-warning">Bei unmittelbarer Gefahr zuerst den örtlich festgelegten Notruf und Meldeweg nutzen. Diese Anwendung ersetzt weder Notruf noch Einsatzleitung.</p>
<?php if ($manager) { ?>
    <details class="card">
        <summary>Freigabe und Sichtbarkeit: <?= $enabled && ($group !== '' || $triggerGroup !== '') ? 'aktiv' : 'gesperrt' ?></summary>
        <form action="<?= $base ?>/einstellungen" method="post" class="form">
            <?= Csrf::field() ?>
            <label><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Notfallplan-Button aktivieren</label>
            <div class="field group-suggest">
                <label for="ep-group">AD-Gruppe für Ansicht und Auslösung</label>
                <input id="ep-group" name="group" maxlength="190" value="<?= Html::e($group) ?>" data-group-suggest="<?= $base ?>/gruppen" autocomplete="off">
            </div>
            <div class="field group-suggest">
                <label for="ep-trigger-group">Weitere AD-Gruppe nur für Auslösung und Abarbeitung (optional)</label>
                <input id="ep-trigger-group" name="trigger_group" maxlength="190" value="<?= Html::e($triggerGroup) ?>" data-group-suggest="<?= $base ?>/gruppen" autocomplete="off">
            </div>
            <p>Mitglieder der weiteren Gruppe sehen den Button, können veröffentlichte Pläne auslösen und ihre eigenen laufenden Ereignisse abarbeiten. Laufende Ereignisse, die ein Mitglied dieser Gruppe ausgelöst hat, sehen und bearbeiten alle Mitglieder gemeinsam. Sie erhalten keinen Zugriff auf das KAEP-Dashboard und keine abgeschlossenen bzw. vergangenen Ereignisse. Bei Mitgliedschaft in beiden Gruppen gelten die Rechte der ersten Gruppe; gemeinsame Ereignisse der Auslösegruppe bleiben zusätzlich sichtbar.</p>
            <p>Keine Gruppe = kein Button und kein Zugriff, auch nicht für Administratoren in der Benutzeransicht. KAEP-Verwaltung und Ereignisübersicht bleiben erreichbar. Entfernen der Freigabe sperrt auch den Benutzerzugriff auf laufende Ereignisse.</p>
            <button class="button button--primary">Freigabe speichern</button>
        </form>
    </details>
    <?php if (!$smtpEnabled) { ?><p class="flash flash--error">SMTP ist deaktiviert. Automatische KAEP-E-Mails können nicht versendet werden. Ein Administrator muss E-Mail (SMTP) konfigurieren.</p><?php } ?>
    <?php if ($canTransfer) { ?>
        <details class="card">
            <summary>Export und Import</summary>
            <form class="form" data-ep-export-form>
                <?= Csrf::field() ?>
                <fieldset>
                    <legend>Notfallpläne exportieren</legend>
                    <?php foreach ($plans as $plan) { ?>
                        <label><input type="checkbox" name="plans[]" value="<?= (int) $plan['id'] ?>" checked> <?= Html::e($plan['title']) ?> (Entwurf <?= (int) $plan['revision'] ?>)</label>
                    <?php } ?>
                    <?php if ($plans === []) { ?><p>Keine Notfallpläne vorhanden.</p><?php } ?>
                </fieldset>
                <p>Exportiert wird jeweils der aktuelle Entwurf einschließlich der Anhänge (Bilder/PDF) – ohne Freigabehistorie und Ereignisse – als Export-Satz aus JSON-Dateien von je höchstens <?= Html::e(\App\Services\EmergencyPlanTransfer::PART_MAX_LABEL) ?>. Wird die Grenze erreicht, entsteht eine anfolgende Datei („Teil 2 von 3“ …). Die Dateien enthalten Ansprechpartner und Telefonnummern; bitte vertraulich behandeln.</p>
                <div class="toolbar">
                    <button type="button" class="button button--primary" data-ep-export="download" <?= $plans === [] ? 'disabled' : '' ?>>Herunterladen</button>
                    <button type="button" class="button button--ghost" data-ep-export="nextcloud" <?= $plans === [] ? 'disabled' : '' ?>>In meinen Nextcloud-Dateien speichern</button>
                </div>
                <div class="ep-transfer" data-ep-transfer-status aria-live="polite"></div>
            </form>
            <form class="form" data-ep-import-form>
                <?= Csrf::field() ?>
                <div class="field">
                    <label for="ep-import">Notfallpläne importieren (alle Dateien eines Export-Satzes, je höchstens <?= Html::e(\App\Services\EmergencyPlanService::IMPORT_MAX_LABEL) ?>)</label>
                    <input id="ep-import" type="file" name="file" accept=".json,application/json" multiple data-ep-import-files>
                </div>
                <p>Mehrere Dateien gleichzeitig auswählen oder nacheinander hinzufügen. Vor dem Import wird geprüft, ob der Export-Satz vollständig und unverändert ist (alle Teile, Pläne, Anhänge und Prüfsummen); fehlt etwas, wird nichts importiert. Jeder Plan wird als neuer, unveröffentlichter Entwurf angelegt; vorhandene Pläne bleiben unverändert. SMS-Elemente werden über den Titel den aktiven Alarmierungen dieses Systems zugeordnet – fehlt eine Vorlage, wird nichts importiert. Die Veröffentlichung benötigt wie immer eine Vier-Augen-Freigabe.</p>
                <div class="ep-transfer" data-ep-import-status aria-live="polite"></div>
            </form>
        </details>
    <?php } ?>
    <script src="/assets/js/admin-group-autocomplete.js?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php } ?>
<h2><?= $manager ? 'Planbibliothek' : 'Notfallplan auswählen' ?></h2>
<div class="ep-plan-grid">
    <?php foreach ($plans as $plan) { ?>
        <article class="card">
            <h3><?= Html::e($plan['title']) ?></h3>
            <p><?= (int) $plan['published'] === 1 ? 'Veröffentlicht: Version ' . (int) $plan['published_revision'] : 'Nicht veröffentlicht' ?><?php if ($manager) { ?> · Entwurf <?= (int) $plan['revision'] ?>: <?= Html::e(['draft' => 'in Bearbeitung', 'pending' => 'Freigabe ausstehend', 'rejected' => 'abgelehnt', 'approved' => 'freigegeben'][$plan['review_state']]) ?><?php } ?></p>
            <a class="button <?= $manager ? 'button--ghost' : 'button--danger' ?>" href="<?= $base ?>/<?= $manager ? 'bearbeiten' : 'plan' ?>?id=<?= (int) $plan['id'] ?>"<?= $manager ? ' target="_blank" rel="noopener"' : '' ?>><?= $manager ? 'Im Editor öffnen (neuer Tab)' : 'Plan öffnen' ?></a>
        </article>
    <?php } ?>
    <?php if ($plans === []) { ?><p>Keine <?= $manager ? '' : 'veröffentlichten ' ?>Notfallpläne vorhanden.</p><?php } ?>
</div>
<?php if (!empty($restricted)) { ?>
<h2>Laufende Ereignisse</h2>
<p>Hier erscheinen noch laufende Ereignisse, die Sie oder ein anderes Mitglied Ihrer Auslösegruppe ausgelöst haben. Alle Mitglieder können sie gemeinsam abarbeiten. Abgeschlossene Ereignisse wertet das KAEP-Team aus.</p>
<?php } else { ?>
<h2><?= $manager ? 'Einsatzübersicht und historische Auswertung' : 'Meine Ereignisse' ?></h2>
<form method="get" class="ep-filters">
    <label>Status <select name="status"><option value="">Alle</option><option value="active" <?= $filter['status'] === 'active' ? 'selected' : '' ?>>Laufend</option><option value="closed" <?= $filter['status'] === 'closed' ? 'selected' : '' ?>>Abgeschlossen</option></select></label>
    <label>Von (UTC) <input type="date" name="from" value="<?= Html::e($filter['from']) ?>"></label>
    <label>Bis (UTC) <input type="date" name="to" value="<?= Html::e($filter['to']) ?>"></label>
    <button class="button button--ghost">Filtern</button>
</form>
<p><?= (int) $events['total'] ?> <?= (int) $events['total'] === 1 ? 'Ereignis' : 'Ereignisse' ?> im gewählten Zeitraum. Je Ereignis: Maßnahmenstand, Zeitverlauf und <?= $manager ? 'CSV-Auswertung.' : 'Kommentare.' ?></p>
<?php } ?>
<div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Ereignis</th><th>Ausgelöst durch</th><th>Start (UTC)</th><th>Status / Dauer</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($events['items'] as $event) { ?>
            <tr>
                <td>#<?= (int) $event['id'] ?> <?= Html::e($event['title']) ?></td>
                <td><?= Html::e($event['actor']) ?></td>
                <td><?= Html::e($event['started_at']) ?></td>
                <td><?php if ($event['status'] === 'active') { ?>Laufend<?php } else {
                    $minutes = max(0, (int) round((strtotime($event['closed_at'] . ' UTC') - strtotime($event['started_at'] . ' UTC')) / 60));
                    echo 'Abgeschlossen · ', $minutes >= 1440 ? intdiv($minutes, 1440) . ' T ' . intdiv($minutes % 1440, 60) . ' Std.' : ($minutes >= 60 ? intdiv($minutes, 60) . ' Std. ' . ($minutes % 60) . ' Min.' : $minutes . ' Min.');
                } ?></td>
                <td><a href="<?= $base ?>/ereignis?id=<?= (int) $event['id'] ?>">Stand öffnen</a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<nav class="toolbar" aria-label="Ereignisseiten">
    <?php if ($filter['page'] > 1) { ?><a href="?<?= Html::e(http_build_query(array_merge($filter, ['page' => $filter['page'] - 1]))) ?>">Vorherige Seite</a><?php } ?>
    <?php if ($filter['page'] * 30 < $events['total']) { ?><a href="?<?= Html::e(http_build_query(array_merge($filter, ['page' => $filter['page'] + 1]))) ?>">Nächste Seite</a><?php } ?>
</nav>
