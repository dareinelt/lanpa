<?php

declare(strict_types=1);

use App\Services\Auth\AuthMetricsCharts;
use App\Support\Bytes;
use App\Support\Html;

/** @var array<string,mixed> $authMetrics Kennzahlen (AuthMetricsService::card()) */
/** @var array<string,mixed> $authMetricsHistory Verlauf (AuthMetricsService::history()) */
$cpu = is_array($authMetrics['cpu'] ?? null) ? $authMetrics['cpu'] : null;
$ram = is_array($authMetrics['ram'] ?? null) ? $authMetrics['ram'] : null;
$tcp = is_array($authMetrics['tcp'] ?? null) ? $authMetrics['tcp'] : null;
$sources = is_array($authMetrics['sources'] ?? null) ? $authMetrics['sources'] : [];
$history = is_array($authMetricsHistory ?? null) ? $authMetricsHistory : [];
$historyCpu = is_array($history['cpu'] ?? null) ? $history['cpu'] : [];
$historyRam = is_array($history['ram'] ?? null) ? $history['ram'] : [];
$historyTcp = is_array($history['tcp'] ?? null) ? $history['tcp'] : [];
$historySources = is_array($history['sources'] ?? null) ? $history['sources'] : [];
$ramUsed = $ram !== null && is_int($ram['used'] ?? null) ? $ram['used'] : null;
$ramTotal = $ram !== null && is_int($ram['total'] ?? null) ? $ram['total'] : null;
$percent = static fn (float $value): string => number_format($value, 1, ',', '.') . ' %';
$number = static fn (float $value): string => number_format($value, 1, ',', '.');
/** Spitze eines Quellnetzes im Verlauf, sofern es dort vorkommt. */
$historyPeak = static function (string $network) use ($historySources): ?int {
    foreach ($historySources as $source) {
        if ((string) ($source['network'] ?? '') === $network) {
            return (int) ($source['peak'] ?? 0);
        }
    }

    return null;
};
$range = static function (string $value): string {
    $at = strtotime($value);

    return $at === false ? $value : date('d.m.Y H:i', $at);
};
$historyHours = (int) round(
    ((int) ($history['buckets'] ?? 0) * (int) ($history['bucket_minutes'] ?? 0)) / 60
);
$charts = new AuthMetricsCharts();
?>
<?php if ($cpu !== null && $tcp !== null) { ?>
    <dialog id="reverse-proxy-dialog" class="auth-metrics-dialog" aria-labelledby="reverse-proxy-dialog-title">
        <div class="auth-metrics-dialog__head">
            <h2 id="reverse-proxy-dialog-title" class="auth-metrics-dialog__title">Reverse-Proxy – Details und Verlauf</h2>
            <button type="button" class="auth-metrics-dialog__close" data-auth-metrics-close aria-label="Schließen">
                <span aria-hidden="true">×</span>
            </button>
        </div>
        <div class="auth-metrics-dialog__body">
            <section class="auth-metrics-dialog__section">
                <h3>Kennzahlen</h3>
                <div class="auth-metrics-dialog__grid">
                    <div>
                        <p class="metric auth-metrics__value auth-metrics__value--<?= Html::e((string) $cpu['level']) ?>">
                            <?= Html::e($percent((float) $cpu['current'])) ?>
                        </p>
                        <p class="auth-metrics__reference">
                            Zugewiesen: <?= Html::e(number_format((float) $cpu['limit'], 2, ',', '.')) ?> Kerne
                        </p>
                        <p class="card__hint">CPU-Last im Container (aktuell)</p>
                        <ul class="status-list">
                            <li>
                                <span>Spitze (<?= (int) $cpu['window'] ?> h)</span>
                                <span>
                                    <?= Html::e($percent((float) $cpu['peak'])) ?>
                                    <?php if (($cpu['peak_at'] ?? null) !== null) { ?>
                                        <span class="card__hint"><?= Html::e((string) $cpu['peak_at']) ?></span>
                                    <?php } ?>
                                </span>
                            </li>
                            <li>
                                <span>Mittel (<?= (int) $cpu['window'] ?> h)</span>
                                <span><?= Html::e($percent((float) $cpu['avg'])) ?></span>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <p class="metric auth-metrics__value auth-metrics__value--<?= Html::e((string) ($ram['level'] ?? 'ok')) ?>">
                            <?= $ram === null ? '–' : Html::e($percent((float) $ram['current'])) ?>
                        </p>
                        <p class="auth-metrics__reference"><?= Html::e(Bytes::formatPair($ramUsed, $ramTotal)) ?></p>
                        <p class="card__hint">Arbeitsspeicher im Container (aktuell)</p>
                        <ul class="status-list">
                            <?php if ($ram === null) { ?>
                                <li>
                                    <span>Messung</span>
                                    <span class="card__hint">Der auth-Container meldet noch keinen Arbeitsspeicher.</span>
                                </li>
                            <?php } else { ?>
                                <li>
                                    <span>Spitze (<?= (int) $ram['window'] ?> h)</span>
                                    <span>
                                        <?= Html::e($percent((float) $ram['peak'])) ?>
                                        <?php if (($ram['peak_at'] ?? null) !== null) { ?>
                                            <span class="card__hint"><?= Html::e((string) $ram['peak_at']) ?></span>
                                        <?php } ?>
                                    </span>
                                </li>
                                <li>
                                    <span>Mittel (<?= (int) $ram['window'] ?> h)</span>
                                    <span><?= Html::e($percent((float) $ram['avg'])) ?></span>
                                </li>
                            <?php } ?>
                        </ul>
                    </div>
                    <div class="auth-metrics-dialog__traffic">
                        <p class="metric"><?= (int) $tcp['connections'] ?></p>
                        <p class="card__hint">Verbindungen seit der letzten Messung</p>
                        <ul class="status-list">
                            <li>
                                <span>Spitze (<?= (int) $tcp['window'] ?> h)</span>
                                <span><?= (int) $tcp['peak'] ?></span>
                            </li>
                            <li>
                                <span>Mittel (<?= (int) $tcp['window'] ?> h)</span>
                                <span><?= Html::e($number((float) $tcp['avg'])) ?></span>
                            </li>
                            <li>
                                <span>Quellnetze (letzte Messung)</span>
                                <span><?= count($sources) ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="auth-metrics-dialog__section">
                <h3>Anfragen nach Quellnetz</h3>
                <?php if ($sources === []) { ?>
                    <p class="card__hint">Zur letzten Messung sind keine Anfragen aus Quellnetzen eingegangen.</p>
                <?php } else { ?>
                    <table class="auth-metrics-dialog__table">
                        <caption class="card__hint">
                            Anfragen der letzten Messung, Anteil daran und Spitze im Verlauf.
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Quellnetz</th>
                                <th scope="col">Anfragen</th>
                                <th scope="col">Anteil</th>
                                <th scope="col">Spitze (<?= $historyHours ?> h)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sources as $source) { ?>
                                <?php $peak = $historyPeak((string) $source['network']); ?>
                                <tr>
                                    <th scope="row"><?= Html::e((string) $source['network']) ?></th>
                                    <td><?= (int) $source['count'] ?></td>
                                    <td><?= Html::e($percent((float) $source['share'])) ?></td>
                                    <td><?= $peak === null ? '–' : (int) $peak ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
            </section>

            <section class="auth-metrics-dialog__section">
                <h3>Verlauf</h3>
                <p class="card__hint">
                    <?= Html::e($range((string) ($history['start'] ?? ''))) ?> bis
                    <?= Html::e($range((string) ($history['end'] ?? ''))) ?>,
                    Mittel je <?= (int) ($history['bucket_minutes'] ?? 0) ?> Minuten.
                </p>

                <figure class="auth-metrics-dialog__figure">
                    <figcaption>CPU-Auslastung</figcaption>
                    <?= $charts->cpu($history) ?>
                    <p class="card__hint">
                        Spitze <?= Html::e($percent((float) ($historyCpu['peak'] ?? 0))) ?><?php
                        if (($historyCpu['peak_at'] ?? null) !== null) {
                            ?>, <?= Html::e((string) $historyCpu['peak_at']) ?><?php
                        }
                        ?>, Mittel <?= Html::e($percent((float) ($historyCpu['avg'] ?? 0))) ?>.
                    </p>
                </figure>

                <figure class="auth-metrics-dialog__figure">
                    <figcaption>Arbeitsspeicher-Auslastung</figcaption>
                    <?= $charts->ram($history) ?>
                    <p class="card__hint">
                        Belegt / gesamt <?= Html::e(Bytes::formatPair($ramUsed, $ramTotal)) ?>,
                        Spitze <?= Html::e($percent((float) ($historyRam['peak'] ?? 0))) ?><?php
                        if (($historyRam['peak_at'] ?? null) !== null) {
                            ?>, <?= Html::e((string) $historyRam['peak_at']) ?><?php
                        }
                        ?>, Mittel <?= Html::e($percent((float) ($historyRam['avg'] ?? 0))) ?>.
                    </p>
                </figure>

                <figure class="auth-metrics-dialog__figure">
                    <figcaption>Verbindungen je Messung</figcaption>
                    <?= $charts->tcp($history) ?>
                    <p class="card__hint">
                        Spitze <?= (int) ($historyTcp['peak'] ?? 0) ?>,
                        Mittel <?= Html::e($number((float) ($historyTcp['avg'] ?? 0))) ?>.
                    </p>
                </figure>

                <figure class="auth-metrics-dialog__figure">
                    <figcaption>Anfragen je Quellnetz</figcaption>
                    <?= $charts->sources($history) ?>
                    <?php if ($historySources !== []) { ?>
                        <ul class="auth-metrics-dialog__legend">
                            <?php foreach ($historySources as $index => $source) { ?>
                                <li>
                                    <?= $charts->sourceSwatch((int) $index) ?>
                                    <span><?= Html::e((string) $source['network']) ?></span>
                                    <span class="card__hint">Spitze <?= (int) ($source['peak'] ?? 0) ?></span>
                                </li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                </figure>
            </section>
        </div>
    </dialog>
<?php } ?>
