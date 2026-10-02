<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<?php if (!$manager) { ?><h1>Notfallplan</h1><?php } ?>
<div class="toolbar">
    <?php if ($manager) { ?><a class="button button--primary" href="<?= $base ?>/bearbeiten">Neuen Notfallplan entwerfen</a><?php } ?>
    <a class="button button--ghost" href="<?= $base ?>/anleitung">Kurzanleitung</a>
</div>
<p class="ep-warning">Bei unmittelbarer Gefahr zuerst den örtlich festgelegten Notruf und Meldeweg nutzen. Diese Anwendung ersetzt weder Notruf noch Einsatzleitung.</p>
<?php if ($manager) { ?>
    <details class="card">
        <summary>Freigabe und Sichtbarkeit: <?= $enabled && $group !== '' ? 'aktiv' : 'gesperrt' ?></summary>
        <form action="<?= $base ?>/einstellungen" method="post" class="form">
            <?= Csrf::field() ?>
            <label><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Notfallplan-Button aktivieren</label>
            <div class="field group-suggest">
                <label for="ep-group">AD-Gruppe für Ansicht und Auslösung</label>
                <input id="ep-group" name="group" maxlength="190" value="<?= Html::e($group) ?>" data-group-suggest="<?= $base ?>/gruppen" autocomplete="off">
            </div>
            <p>Keine Gruppe = kein Button und kein Zugriff, auch nicht für Administratoren in der Benutzeransicht. KAEP-Verwaltung und Ereignisübersicht bleiben erreichbar. Entfernen der Freigabe sperrt auch den Benutzerzugriff auf laufende Ereignisse.</p>
            <button class="button button--primary">Freigabe speichern</button>
        </form>
    </details>
    <?php if (!$smtpEnabled) { ?><p class="flash flash--error">SMTP ist deaktiviert. Automatische KAEP-E-Mails können nicht versendet werden. Ein Administrator muss E-Mail (SMTP) konfigurieren.</p><?php } ?>
    <script src="/assets/js/admin-group-autocomplete.js?v=<?= Html::e($assetVersion) ?>" defer></script>
<?php } ?>
<h2><?= $manager ? 'Planbibliothek' : 'Notfallplan auswählen' ?></h2>
<div class="ep-plan-grid">
    <?php foreach ($plans as $plan) { ?>
        <article class="card">
            <h3><?= Html::e($plan['title']) ?></h3>
            <p><?= (int) $plan['published'] === 1 ? 'Veröffentlicht: Version ' . (int) $plan['published_revision'] : 'Nicht veröffentlicht' ?><?php if ($manager) { ?> · Entwurf <?= (int) $plan['revision'] ?>: <?= Html::e(['draft' => 'in Bearbeitung', 'pending' => 'Freigabe ausstehend', 'rejected' => 'abgelehnt', 'approved' => 'freigegeben'][$plan['review_state']]) ?><?php } ?></p>
            <a class="button <?= $manager ? 'button--ghost' : 'button--danger' ?>" href="<?= $base ?>/<?= $manager ? 'bearbeiten' : 'plan' ?>?id=<?= (int) $plan['id'] ?>"><?= $manager ? 'Bearbeiten / Vorschau' : 'Plan öffnen' ?></a>
        </article>
    <?php } ?>
    <?php if ($plans === []) { ?><p>Keine <?= $manager ? '' : 'veröffentlichten ' ?>Notfallpläne vorhanden.</p><?php } ?>
</div>
<h2><?= $manager ? 'Einsatzübersicht und historische Auswertung' : 'Meine Ereignisse' ?></h2>
<form method="get" class="ep-filters">
    <label>Status <select name="status"><option value="">Alle</option><option value="active" <?= $filter['status'] === 'active' ? 'selected' : '' ?>>Laufend</option><option value="closed" <?= $filter['status'] === 'closed' ? 'selected' : '' ?>>Abgeschlossen</option></select></label>
    <label>Von (UTC) <input type="date" name="from" value="<?= Html::e($filter['from']) ?>"></label>
    <label>Bis (UTC) <input type="date" name="to" value="<?= Html::e($filter['to']) ?>"></label>
    <button class="button button--ghost">Filtern</button>
</form>
<p><?= (int) $events['total'] ?> <?= (int) $events['total'] === 1 ? 'Ereignis' : 'Ereignisse' ?> im gewählten Zeitraum. Je Ereignis: Maßnahmenstand, Zeitverlauf und <?= $manager ? 'CSV-Auswertung.' : 'Kommentare.' ?></p>
<div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Ereignis</th><th>Ausgelöst durch</th><th>Start (UTC)</th><th>Status / Dauer</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($events['items'] as $event) { ?>
            <tr>
                <td>#<?= (int) $event['id'] ?> <?= Html::e($event['title']) ?></td>
                <td><?= Html::e($event['actor']) ?></td>
                <td><?= Html::e($event['started_at']) ?></td>
                <td><?= $event['status'] === 'active' ? 'Laufend' : 'Abgeschlossen · ' . max(0, (int) round((strtotime($event['closed_at'] . ' UTC') - strtotime($event['started_at'] . ' UTC')) / 60)) . ' Min.' ?></td>
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
