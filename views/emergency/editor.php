<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
$reviewLabels = ['draft' => 'Entwurf', 'pending' => 'Wartet auf zweite Freigabe', 'rejected' => 'Freigabe verweigert', 'approved' => 'Freigegeben'];
$canReview = $plan['review_state'] === 'pending' && !in_array($actor, $plan['contributors'], true) && $actor !== $plan['submitted_by'];
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<div data-ep-editor data-save-url="<?= $base ?>/speichern">
    <textarea hidden data-ep-initial><?= Html::e(json_encode($plan, JSON_THROW_ON_ERROR)) ?></textarea>
    <textarea hidden data-ep-alarms><?= Html::e(json_encode($alarms, JSON_THROW_ON_ERROR)) ?></textarea>
    <?= Csrf::field() ?>
    <div class="ep-editor-bar">
        <a class="button button--ghost" href="<?= $base ?>">Zur Übersicht</a>
        <strong data-ep-review-status><?= Html::e($reviewLabels[$plan['review_state']]) ?></strong>
        <button type="button" class="button button--primary" data-ep-save>Entwurf speichern</button>
        <span data-ep-message role="status" aria-live="polite">Noch keine Änderungen.</span>
    </div>
    <div class="ep-plan-meta">
        <label>Plantitel <input data-ep-title maxlength="190" required value="<?= Html::e($plan['definition']['title']) ?>" placeholder="z. B. Brandfall"></label>
        <label>Kurzbeschreibung / erste Hinweise <textarea data-ep-description rows="2" maxlength="4000"><?= Html::e($plan['definition']['description']) ?></textarea></label>
    </div>
    <details class="card">
        <summary>Bedienhilfe und Beispielvorlagen</summary>
        <p>Element hinzufügen, im Diagramm oder in der Liste auswählen, rechts ausfüllen. Verbindungen werden automatisch gezeichnet. Mehrere Elemente ohne Vorgänger laufen parallel. Ja/Nein-Zweige sind über Entscheidungen möglich.</p>
        <p>„Alle Vorgänger“ ist eine UND-Verknüpfung; „Mindestens einer“ führt alternative Zweige wieder zusammen. Verbindungen zeigen immer von oben nach unten. Die Pfeiltasten in der Elementliste ändern die Reihenfolge.</p>
        <p>Vorlagen sind nur Beispiele und müssen fachlich geprüft und angepasst werden. Speichern ist keine SMS-Auslösung.</p>
        <button type="button" class="button button--ghost" data-ep-template="fire">Beispiel Brandfall übernehmen</button>
        <button type="button" class="button button--ghost" data-ep-template="manf">Beispiel MANF übernehmen</button>
    </details>
    <div class="ep-editor">
        <section class="ep-palette" aria-label="Elemente">
            <h2>Bausteine</h2>
            <label for="ep-type">Elementtyp</label>
            <select id="ep-type" data-ep-add-type>
                <option value="action">Maßnahme</option><option value="contact">Kontakt / Anruf</option><option value="decision">Entscheidung (Ja / Nein)</option><option value="checklist">Checkliste</option><option value="note">Hinweis / Warnung</option><option value="sms">SMS-Alarmierung</option>
            </select>
            <button type="button" class="button button--primary" data-ep-add>Element hinzufügen</button>
            <ol data-ep-list class="ep-element-list"></ol>
        </section>
        <section class="ep-workspace">
            <h2>Ablaufdiagramm</h2>
            <p class="field__hint">Automatisches Layout · Elemente anklicken oder per Tastatur auswählen.</p>
            <div class="ep-diagram" data-ep-diagram></div>
        </section>
        <section class="ep-inspector" aria-label="Element bearbeiten">
            <h2>Element bearbeiten</h2>
            <div data-ep-fields><p>Wählen Sie ein Element oder fügen Sie eines hinzu.</p></div>
        </section>
    </div>
</div>
<section class="card ep-review" data-ep-review-panel>
    <h2>Vier-Augen-Freigabe</h2>
    <p>Auch Administratoren dürfen eigene oder mitbearbeitete Entwürfe nicht selbst freigeben. Änderungen nach Antragstellung machen die offene Freigabe ungültig. Die aktuell veröffentlichte Version bleibt bis zur nächsten Freigabe unverändert.</p>
    <p><strong><?= (int) $plan['published'] === 1 ? 'Aktuell veröffentlicht: Version ' . (int) $plan['published_revision'] : 'Nicht veröffentlicht' ?></strong> · Entwurf: Version <?= (int) $plan['revision'] ?></p>
    <?php if ($plan['published_definition'] ?? null) { $definition = $plan['published_definition']; require __DIR__ . '/publication.php'; } ?>
    <?php if ($plan['contributors'] !== []) { ?><p>Am aktuellen Entwurf beteiligt: <?= Html::e(implode(', ', $plan['contributors'])) ?></p><?php } ?>
    <?php if ($plan['id'] > 0 && in_array($plan['review_state'], ['draft', 'rejected'], true)) { ?>
        <form action="<?= $base ?>/freigabe" method="post" data-ep-review-form>
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $plan['revision'] ?>"><input type="hidden" name="action" value="submit">
            <button class="button button--primary">Freigabe anfordern</button>
        </form>
    <?php } elseif ($plan['review_state'] === 'pending') { ?>
        <p>Angefordert von <?= Html::e($plan['submitted_by']) ?> am <?= Html::e($plan['submitted_at']) ?> UTC.</p>
        <?php if ($canReview) { ?>
            <form action="<?= $base ?>/freigabe" method="post" data-ep-review-form>
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $plan['revision'] ?>">
                <label>Prüfkommentar (bei Ablehnung Pflicht)<textarea name="comment" maxlength="2000" rows="3"></textarea></label>
                <button class="button button--primary" name="action" value="approve">Geprüften Entwurf freigeben und veröffentlichen</button>
                <button class="button button--danger" name="action" value="reject">Freigabe verweigern</button>
            </form>
        <?php } else { ?><p>Sie haben an diesem Entwurf mitgewirkt oder ihn eingereicht. Ein anderes, unbeteiligtes KAEP-Mitglied oder ein unbeteiligter Administrator muss prüfen.</p><?php } ?>
    <?php } else { ?><p><?= $plan['id'] > 0 ? 'Der Entwurf wurde bereits freigegeben.' : 'Zuerst einen Entwurf speichern.' ?></p><?php } ?>
    <?php if ((int) $plan['published'] === 1) { ?>
        <form action="<?= $base ?>/freigabe" method="post" data-ep-review-form data-confirm="Veröffentlichten Plan sofort zurückziehen? Erneutes Veröffentlichen benötigt eine neue zweite Freigabe.">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $plan['revision'] ?>"><input type="hidden" name="action" value="withdraw">
            <button class="button button--danger">Veröffentlichung zurückziehen</button>
        </form>
    <?php } ?>
    <h3>Freigabeprotokoll</h3>
    <ol class="ep-log"><?php foreach ($reviews as $review) { ?><li><?= Html::e($review['created_at']) ?> UTC · <?= Html::e($review['actor']) ?> · Version <?= (int) $review['revision'] ?> · <strong><?= Html::e(['saved' => 'Entwurf gespeichert', 'submitted' => 'Freigabe angefordert', 'approved' => 'Freigegeben', 'rejected' => 'Abgelehnt', 'withdrawn' => 'Zurückgezogen'][$review['action']] ?? $review['action']) ?></strong><p><?= Html::e($review['comment']) ?></p></li><?php } ?></ol>
</section>
<noscript><p class="flash flash--error">Der Planeditor benötigt JavaScript. Ohne JavaScript können keine Pläne bearbeitet werden.</p></noscript>
