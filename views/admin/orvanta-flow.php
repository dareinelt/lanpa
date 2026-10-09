<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Orvanta\OrvantaFlowCharts;
use App\Services\Orvanta\OrvantaFlowCloud;
use App\Services\Orvanta\OrvantaFlowService;
use App\Support\Html;

/**
 * Nachrichtenfluss-Dashboard: Gesamtstatus, Kennzahlen, zwei Spuren (Proxy-Pfad,
 * Exchange-Pfad), Speicher und KI-Endpunkt.
 *
 * Sprungmarken der Kennzahlenleiste: `flow-users`, `flow-proxy` (Proxy-Knoten),
 * `flow-exchange` (Spur Exchange), `flow-storage`, `flow-cache`, `flow-ai`.
 * Knoten erhalten `id="flow-<Knotenschlüssel>"`, die Spuren `flow-proxy-lane`
 * und `flow-exchange`.
 *
 * Konzept und Datenquellen: docs/orvanta-nachrichtenfluss.md
 *
 * @var array<string,mixed> $flow Ergebnis von OrvantaFlowService::overview()
 * @var string $base Basispfad der Seite
 * @var bool $orvantaEnabled Orvanta ist aktiviert
 * @var bool $orvantaDemo Demo-Modus (kein echter Exchange)
 * @var int $refreshInterval Sekunden bis zur nächsten Aktualisierung
 * @var int $checkLimit Höchstzahl der Quellen für einen Sammeltest
 * @var list<array{id:int,label:string}> $checkableSources einzeln prüfbare Quellen
 */

$nodes = (array) ($flow['nodes'] ?? []);
$overall = (array) ($flow['overall'] ?? []);
$overallState = (string) ($overall['state'] ?? 'off');
$kpis = (array) ($flow['kpis'] ?? []);
$incidents = (array) ($flow['incidents'] ?? []);
$edges = (array) ($flow['edges'] ?? []);

$badge = static function (string $state): string {
    return match ($state) {
        'ok' => 'badge--ok',
        'warn' => 'badge--warn',
        'error' => 'badge--error',
        default => 'badge--muted',
    };
};
$stateLabel = static function (string $state): string {
    return OrvantaFlowService::STATE_LABELS[$state] ?? $state;
};

$cloud = new OrvantaFlowCloud();
$charts = new OrvantaFlowCharts();

/** Spuren: Sprungmarke je Schlüssel (die Kennzahlen zeigen darauf). */
$laneAnchors = ['proxy' => 'flow-proxy-lane', 'exchange' => 'flow-exchange'];

/**
 * Verlaufsgrafik als Overlay im Nutzerknoten.
 */
$renderChart = static function (array $history) use ($charts): string {
    $series = (array) ($history['series'] ?? []);
    if ($series === []) {
        return '';
    }

    $html = '<details class="flow-overlay">'
        . '<summary class="flow-overlay__toggle">Verlauf öffnen: aktive Nutzer 365/180/90/30/14 Tage</summary>'
        . '<div class="flow-overlay__body">'
        . '<figure class="flow-chart"><figcaption>Aktive Orvanta-Nutzer je Tag (Tagesmaximum) – fünf Zeiträume im Vergleich</figcaption>'
        . $charts->overlay($history) . '</figure>';

    $html .= '<ul class="flow-legend">';
    foreach ($charts->series() as $days => $meta) {
        $data = (array) ($series[$days] ?? []);
        if ($data === []) {
            continue;
        }
        $html .= '<li class="flow-legend__item">'
            . '<span class="flow-legend__swatch flow-legend__swatch--d' . (int) $days . '" aria-hidden="true"></span>'
            . Html::e((string) $meta['label']) . ': Höchstwert ' . (int) ($data['max'] ?? 0)
            . ', Ø ' . number_format((float) ($data['avg'] ?? 0), 1, ',', '.')
            . ', ' . (int) ($data['samples'] ?? 0) . ' Messwerte</li>';
    }
    $html .= '</ul>';

    $rows = $charts->tableRows($history);
    if ($rows !== []) {
        $html .= '<details class="flow-values"><summary>Werte als Tabelle (kürzester Zeitraum)</summary>'
            . '<table class="table"><caption class="visually-hidden">Tageshöchstwerte aktiver Orvanta-Nutzer</caption>'
            . '<thead><tr><th scope="col">Tag</th><th scope="col">Aktive Nutzer</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $day = (string) ($row['day'] ?? '');
            $label = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $match) === 1
                ? $match[3] . '.' . $match[2] . '.' . $match[1]
                : $day;
            $html .= '<tr><th scope="row">' . Html::e($label) . '</th><td>'
                . ($row['value'] === null ? '–' : (string) (int) $row['value']) . '</td></tr>';
        }
        $html .= '</tbody></table></details>';
    }

    return $html . '</div></details>';
};

/**
 * Ein Knoten des Flusses: Kopf, Zustand, Meldung, Werte, Wolke, Erweiterungen,
 * Verlauf und Verweis. Alle Ausgaben sind maskiert; die Wolke liefert bereits
 * fertiges HTML.
 */
$renderNode = static function (array $node) use ($badge, $stateLabel, $cloud, $renderChart): string {
    $key = (string) ($node['key'] ?? '');
    $state = (string) ($node['state'] ?? 'off');
    $muted = (bool) ($node['muted'] ?? false);
    $classes = 'flow-node flow-node--' . $state . ($muted ? ' flow-node--muted' : '');

    $html = '<article class="' . $classes . '" id="flow-' . Html::e($key) . '"'
        . ' data-flow-node="' . Html::e($key) . '" data-flow-node-state="' . Html::e($state) . '">';

    $html .= '<header class="flow-node__head"><h4 class="flow-node__title">' . Html::e((string) ($node['title'] ?? '')) . '</h4>'
        . '<span class="badge ' . $badge($state) . '" data-flow-node-label>' . Html::e($stateLabel($state)) . '</span></header>';

    $subtitle = trim((string) ($node['subtitle'] ?? ''));
    if ($subtitle !== '') {
        $html .= '<p class="flow-node__subtitle"><code>' . Html::e($subtitle) . '</code></p>';
    }
    if (!empty($node['primary'])) {
        $html .= '<p class="flow-node__badges"><span class="badge">Primär</span></p>';
    }
    if (!empty($node['alert'])) {
        $html .= '<p class="flow-node__alert"><span class="flow-alert" aria-hidden="true">!</span>'
            . '<span class="visually-hidden">Störung</span></p>';
    }

    $message = trim((string) ($node['message'] ?? ''));
    $html .= '<p class="flow-node__message" data-flow-node-message' . ($message === '' ? ' hidden' : '') . '>'
        . Html::e($message) . '</p>';

    if ($muted) {
        $reason = trim((string) ($node['muted_reason'] ?? ''));
        $html .= '<p class="flow-node__muted">Werte ausgegraut (' . Html::e($reason !== '' ? $reason : 'nicht erreichbar') . ')</p>';
    }

    $facts = (array) ($node['facts'] ?? []);
    if ($facts !== []) {
        $html .= '<dl class="flow-facts">';
        foreach ($facts as $fact) {
            $factState = (string) ($fact['state'] ?? '');
            $html .= '<div class="flow-fact' . ($factState !== '' ? ' flow-fact--' . Html::e($factState) : '') . '">'
                . '<dt>' . Html::e((string) ($fact['label'] ?? '')) . '</dt>'
                . '<dd>' . Html::e((string) ($fact['value'] ?? '')) . '</dd></div>';
        }
        $html .= '</dl>';
    }

    $entries = (array) ($node['cloud'] ?? []);
    $cloudTitle = trim((string) ($node['cloud_title'] ?? ''));
    if ($cloudTitle !== '') {
        $html .= '<div class="flow-cloud-block">'
            . '<h5 class="flow-cloud-block__title">' . Html::e($cloudTitle) . '</h5>'
            . '<div class="flow-cloud' . (!empty($node['cloud_muted']) ? ' flow-cloud--muted' : '') . '">'
            . $cloud->render($entries, (string) ($node['cloud_empty'] ?? 'Keine Einträge.'))
            . '</div>';
        $more = trim((string) ($node['cloud_more'] ?? ''));
        if ($more !== '') {
            $html .= '<p class="flow-cloud-block__more">' . Html::e($more) . '</p>';
        }
        $html .= $cloud->table(
            $entries,
            'Werte als Tabelle',
            'Element',
            (string) ($node['kind'] ?? '') === 'cache' ? 'Belegung' : 'Anzahl'
        );
        $html .= '</div>';
    }

    $members = (array) ($node['members'] ?? []);
    if ($members !== []) {
        $html .= '<h5 class="flow-cloud-block__title">Erweiterungen</h5><ul class="flow-members">';
        foreach ($members as $member) {
            $memberState = (string) ($member['state'] ?? '');
            $html .= '<li class="flow-member flow-member--' . Html::e($memberState) . '" title="' . Html::e((string) ($member['title'] ?? '')) . '">'
                . '<span class="flow-member__label">' . Html::e((string) ($member['label'] ?? '')) . '</span>'
                . '<span class="badge ' . $badge($memberState) . '">' . Html::e((string) ($member['value'] ?? '')) . '</span></li>';
        }
        $html .= '</ul>';
    }

    $chart = (array) ($node['chart'] ?? []);
    if ($chart !== []) {
        $html .= $renderChart($chart);
    }

    $link = $node['link'] ?? null;
    if (is_array($link) && trim((string) ($link['url'] ?? '')) !== '') {
        $html .= '<p class="flow-node__link"><a href="' . Html::url((string) $link['url']) . '">'
            . Html::e((string) ($link['label'] ?? 'Details')) . '</a></p>';
    }

    return $html . '</article>';
};

/** Beschriftung einer Kante aus den Knotentiteln. */
$edgeLabel = static function (array $edge) use ($nodes): string {
    $from = (string) ($nodes[(string) ($edge['from'] ?? '')]['title'] ?? ($edge['from'] ?? ''));
    $to = (string) ($nodes[(string) ($edge['to'] ?? '')]['title'] ?? ($edge['to'] ?? ''));

    return $from . ' → ' . $to;
};
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/orvanta">Orvanta – Mail &amp; Kalender</a>
    <a class="button button--ghost" href="/admin/office/orvanta/hosts">Exchange-DAG-Hosts</a>
    <a class="button button--ghost" href="/admin/office/mail-proxy">SMTP-/IMAP-Proxy</a>
    <a class="button button--ghost" href="/admin/speicher-ha">Speicher (HA)</a>
</div>

<p class="card__hint">
    Das Dashboard zeigt alle Bausteine, die den Nachrichtenfluss von Orvanta tragen: die
    <strong>Identitätsquellen</strong> mit ihren Postfächern, den <strong>IMAP-/SMTP-Proxy</strong>,
    die <strong>Exchange-Hosts</strong> einer DAG mit ihren verbundenen Clients sowie die
    <strong>Speicher</strong> und den <strong>KI-Endpunkt</strong>. Die Seite ist lesend;
    jede Karte verlinkt auf die zuständige Verwaltungsseite.
</p>

<?php if (!$orvantaEnabled) { ?>
    <p class="flash flash--error">Orvanta ist nicht aktiviert. Bitte zuerst unter <a href="/admin/office/orvanta">Office → Orvanta</a> die Exchange-Anbindung einrichten und testen.</p>
<?php } elseif ($orvantaDemo) { ?>
    <p class="flash flash--error">Der Demo-Modus (Exchange-Server „demo“) verwendet keine echten Hosts. Die Werte des Exchange-Pfads sind daher nicht aussagekräftig.</p>
<?php } ?>

<div data-orvanta-flow
     data-refresh-url="<?= Html::e($base . '/daten') ?>"
     data-refresh-interval="<?= (int) $refreshInterval ?>">

    <section class="card flow-head flow-head--<?= Html::e($overallState) ?>" aria-labelledby="flow-head-title">
        <div class="card__head">
            <h2 class="card__title" id="flow-head-title">Gesamtstatus</h2>
            <span class="badge <?= $badge($overallState) ?>" data-flow-field="overall-label"><?= Html::e((string) ($overall['label'] ?? '')) ?></span>
        </div>
        <p class="flow-head__message" data-flow-field="overall-message"><?= Html::e((string) ($overall['message'] ?? '')) ?></p>
        <p class="card__hint">
            Stand: <span data-flow-field="generated"><?= Html::e((string) ($flow['generated_at'] ?? '')) ?></span> ·
            Kennzahlen und Zustände aktualisieren sich automatisch alle <?= (int) $refreshInterval ?> Sekunden ·
            Störungen: <span data-flow-field="overall-errors"><?= (int) ($overall['errors'] ?? 0) ?></span>,
            Einschränkungen: <span data-flow-field="overall-warnings"><?= (int) ($overall['warnings'] ?? 0) ?></span>
        </p>
    </section>

    <?php if ($incidents !== []) { ?>
        <section class="card flow-incidents" aria-labelledby="flow-incidents-title">
            <h2 class="card__title" id="flow-incidents-title">Offene Störungen</h2>
            <ul class="flow-incident-list">
                <?php foreach ($incidents as $incident) { ?>
                    <li class="flow-incident">
                        <span class="flow-alert" aria-hidden="true">!</span>
                        <span class="visually-hidden">Störung</span>
                        <strong><?= Html::e((string) ($incident['title'] ?? '')) ?></strong>
                        <?php if (trim((string) ($incident['message'] ?? '')) !== '') { ?>
                            <span class="flow-incident__message"><?= Html::e((string) $incident['message']) ?></span>
                        <?php } ?>
                        <?php if (trim((string) ($incident['url'] ?? '')) !== '') { ?>
                            <a href="<?= Html::url((string) $incident['url']) ?>">Beheben</a>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
        </section>
    <?php } ?>

    <section class="card" aria-labelledby="flow-kpis-title">
        <h2 class="card__title" id="flow-kpis-title">Kennzahlen</h2>
        <ul class="flow-kpis">
            <?php foreach ($kpis as $kpi) {
                $kpiState = (string) ($kpi['state'] ?? 'off');
                $kpiKey = (string) ($kpi['key'] ?? ''); ?>
                <li class="flow-kpi flow-kpi--<?= Html::e($kpiState) ?>" data-flow-kpi-item="<?= Html::e($kpiKey) ?>">
                    <a class="flow-kpi__link" href="#<?= Html::e((string) ($kpi['anchor'] ?? '')) ?>">
                        <span class="flow-kpi__label"><?= Html::e((string) ($kpi['label'] ?? '')) ?></span>
                        <span class="flow-kpi__value" data-flow-kpi="<?= Html::e($kpiKey) ?>"><?= Html::e((string) ($kpi['value'] ?? '')) ?></span>
                        <span class="flow-kpi__hint" data-flow-kpi-hint="<?= Html::e($kpiKey) ?>"><?= Html::e((string) ($kpi['hint'] ?? '')) ?></span>
                    </a>
                </li>
            <?php } ?>
        </ul>
    </section>

    <?php foreach ((array) ($flow['lanes'] ?? []) as $lane) {
        $laneKey = (string) ($lane['key'] ?? '');
        $laneState = (string) ($lane['state'] ?? 'off');
        $laneNodes = (array) ($lane['nodes'] ?? []);
        $laneAnchor = $laneAnchors[$laneKey] ?? ('flow-lane-' . $laneKey);
        $laneTarget = $laneKey === 'proxy' ? 'proxy' : 'users';
        $laneEdges = array_values(array_filter(
            $edges,
            static fn (array $edge): bool => (string) ($edge['to'] ?? '') === $laneTarget
        )); ?>
        <section class="card flow-lane" id="<?= Html::e($laneAnchor) ?>" data-flow-lane="<?= Html::e($laneKey) ?>"
                 aria-labelledby="flow-lane-<?= Html::e($laneKey) ?>-title">
            <div class="card__head">
                <h2 class="card__title" id="flow-lane-<?= Html::e($laneKey) ?>-title"><?= Html::e((string) ($lane['title'] ?? '')) ?></h2>
                <span class="badge <?= $badge($laneState) ?>"><?= Html::e($stateLabel($laneState)) ?></span>
            </div>

            <ul class="flow-edges" aria-label="Verbindungen dieser Spur">
                <?php if ($laneEdges === []) { ?>
                    <li class="flow-edge flow-edge--none">Keine Verbindungen erfasst.</li>
                <?php } else {
                    foreach ($laneEdges as $edge) { ?>
                        <li class="flow-edge flow-edge--<?= Html::e((string) ($edge['state'] ?? 'ok')) ?>">
                            <span class="flow-edge__label"><?= Html::e($edgeLabel($edge)) ?></span>
                        </li>
                    <?php }
                } ?>
            </ul>

            <?php if ($laneNodes === []) { ?>
                <p class="card__hint">Für diese Spur ist kein Baustein eingerichtet.</p>
            <?php } else { ?>
                <div class="flow-grid">
                    <?php foreach ($laneNodes as $laneNodeKey) {
                        $laneNode = (array) ($nodes[$laneNodeKey] ?? []);
                        if ($laneNode !== []) {
                            echo $renderNode($laneNode);
                        }
                    } ?>
                </div>
            <?php } ?>
        </section>
    <?php } ?>

    <section class="card flow-block" id="flow-storage" aria-labelledby="flow-storage-title">
        <div class="card__head">
            <h2 class="card__title" id="flow-storage-title">Speicher-Tiers</h2>
            <a class="button button--ghost" href="/admin/speicher-ha">Speicher (HA) verwalten</a>
        </div>
        <p class="card__hint">
            Die Tiers begrenzen den Nachrichtenfluss: Ein nicht erreichbarer oder voller Tier stört Orvanta sichtbar,
            weil Anhänge nicht mehr abgelegt werden können.
        </p>
        <?php if ((array) ($flow['tiers'] ?? []) === []) { ?>
            <p class="card__hint">Es ist kein Speicher-Tier eingerichtet.</p>
        <?php } else { ?>
            <div class="flow-grid">
                <?php foreach ((array) $flow['tiers'] as $tierKey) {
                    $tierNode = (array) ($nodes[$tierKey] ?? []);
                    if ($tierNode !== []) {
                        echo $renderNode($tierNode);
                    }
                } ?>
            </div>
        <?php } ?>
    </section>

    <section class="card flow-block" aria-labelledby="flow-services-title">
        <h2 class="card__title" id="flow-services-title">Zwischenspeicher und KI-Endpunkt</h2>
        <div class="flow-grid">
            <?php foreach (['cache', 'ai'] as $serviceKey) {
                $serviceNode = (array) ($nodes[$serviceKey] ?? []);
                if ($serviceNode !== []) {
                    echo $renderNode($serviceNode);
                }
            } ?>
        </div>
    </section>

    <section class="card flow-block" id="flow-check" aria-labelledby="flow-check-title">
        <h2 class="card__title" id="flow-check-title">Identitätsquellen prüfen</h2>
        <p class="card__hint">
            Der Verbindungstest öffnet den Posteingang des ersten aktiven Postfachs einer Quelle über den
            IMAP-/SMTP-Proxy. Das Ergebnis erscheint sofort im Zustand der Quelle. Ein einzelner Test ist
            schneller als „Alle aktiven Quellen“; bei mehr als <?= (int) $checkLimit ?> aktiven Quellen ist
            nur der Einzeltest möglich.
        </p>
        <form method="post" action="<?= Html::e($base) ?>/quellen/pruefen" class="form">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="flow_check_source">Identitätsquelle</label>
                <select id="flow_check_source" name="source">
                    <option value="0">Alle aktiven Quellen (nacheinander)</option>
                    <?php foreach ($checkableSources as $source) { ?>
                        <option value="<?= (int) $source['id'] ?>"><?= Html::e($source['label']) ?></option>
                    <?php } ?>
                </select>
                <p class="field__hint">
                    Geprüft wird je Quelle das erste aktive Postfach. Eine Quelle ohne aktives Postfach kann nicht
                    geprüft werden und meldet das im Klartext.
                </p>
            </div>
            <div class="form__actions">
                <button class="button button--primary"<?= $checkableSources === [] ? ' disabled' : '' ?>>Verbindung testen</button>
            </div>
        </form>
    </section>
</div>
