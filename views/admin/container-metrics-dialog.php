<?php

declare(strict_types=1);

use App\Services\Auth\AuthMetricsCharts;
use App\Services\Monitoring\ContainerMetricsService;
use App\Support\Bytes;
use App\Support\Html;

/**
 * Detail-Overlay einer Container-Kachel auf dem Dashboard (CPU- und
 * Arbeitsspeicher-Auslastung mit Verlauf), analog zum Overlay der Karte
 * "Reverse-Proxy". Wird je Container aus views/admin/dashboard.php eingebunden.
 *
 * @var array<string,mixed> $containerCard Kachel (ContainerMetricsService::buildCard())
 * @var array<string,mixed> $containerHistory Verlauf des Containers (ContainerMetricsService::buildHistory())
 * @var string $containerDialogId Kennung des Overlays (die Kachel verweist darauf)
 */
$card = is_array($containerCard ?? null) ? $containerCard : [];
$history = is_array($containerHistory ?? null) ? $containerHistory : [];
$dialogId = (string) ($containerDialogId ?? '');
$title = (string) ($card['title'] ?? '');
$cpu = is_array($card['cpu'] ?? null) ? $card['cpu'] : null;
$ram = is_array($card['ram'] ?? null) ? $card['ram'] : null;
$historyCpu = is_array($history['cpu'] ?? null) ? $history['cpu'] : [];
$historyRam = is_array($history['ram'] ?? null) ? $history['ram'] : [];
$ramUsed = $ram !== null && is_int($ram['used'] ?? null) ? $ram['used'] : null;
$ramTotal = $ram !== null && is_int($ram['total'] ?? null) ? $ram['total'] : null;
$percent = static fn (float $value): string => number_format($value, 1, ',', '.') . ' %';
$range = static function (string $value): string {
    $at = strtotime($value);

    return $at === false ? $value : date('d.m.Y H:i', $at);
};
$charts = new AuthMetricsCharts();
?>
<?php if ($cpu !== null && $dialogId !== '') { ?>
    <dialog id="<?= Html::e($dialogId) ?>" class="auth-metrics-dialog" aria-labelledby="<?= Html::e($dialogId) ?>-title">
        <div class="auth-metrics-dialog__head">
            <h2 id="<?= Html::e($dialogId) ?>-title" class="auth-metrics-dialog__title">
                <?= Html::e($title) ?> – Details und Verlauf
            </h2>
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
                        <p class="auth-metrics__reference"><?= Html::e(ContainerMetricsService::cpuReference($cpu)) ?></p>
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
                            <li>
                                <span>Proben</span>
                                <span><?= (int) ($card['samples'] ?? 0) ?></span>
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
                                    <span class="card__hint">Der Container meldet noch keinen Arbeitsspeicher.</span>
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
                </div>
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
                    <?= $charts->cpu($history, 'CPU-Auslastung im Container „' . $title . '“') ?>
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
                    <?= $charts->ram($history, 'Arbeitsspeicher-Auslastung im Container „' . $title . '“') ?>
                    <p class="card__hint">
                        Belegt / gesamt <?= Html::e(Bytes::formatPair($ramUsed, $ramTotal)) ?>,
                        Spitze <?= Html::e($percent((float) ($historyRam['peak'] ?? 0))) ?><?php
                        if (($historyRam['peak_at'] ?? null) !== null) {
                            ?>, <?= Html::e((string) $historyRam['peak_at']) ?><?php
                        }
                        ?>, Mittel <?= Html::e($percent((float) ($historyRam['avg'] ?? 0))) ?>.
                    </p>
                </figure>
            </section>
        </div>
    </dialog>
<?php } ?>
