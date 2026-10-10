<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Auth\AuthMetricsService;
use App\Support\Bytes;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,mixed>|null $lastSync */
/** @var array<string,mixed>|null $lastSuccessfulSync */
/** @var array{count:int,users:list<string>,target:string,freeze:bool,title:string,message:string}|null $incidentAlert */
/** @var array<string,mixed> $authMetrics Kennzahlen des auth-Containers (siehe AuthMetricsService::card()) */
$incidentAlert = $incidentAlert ?? null;
?>
<?php if ($incidentAlert !== null) { ?>
    <section class="incident-alert" role="alert">
        <h2 class="incident-alert__title"><?= Html::e($incidentAlert['title']) ?></h2>
        <p><?= Html::e($incidentAlert['message']) ?></p>
        <p><a class="button button--danger" href="/admin/vorfaelle">Vorfälle prüfen</a></p>
    </section>
<?php } ?>
<div class="cards">
    <section class="card">
        <h2 class="card__title">Systemstatus</h2>
        <ul class="status-list">
            <li>
                <span>Datenbank</span>
                <span class="badge badge--ok">verbunden</span>
            </li>
            <li>
                <span>LDAP-Erweiterung</span>
                <span class="badge <?= $ldapExtensionAvailable ? 'badge--ok' : 'badge--warn' ?>">
                    <?= $ldapExtensionAvailable ? 'verfügbar' : 'nicht installiert' ?>
                </span>
            </li>
            <li>
                <span>AD-Konfiguration</span>
                <span class="badge <?= $ldapConfigured ? 'badge--ok' : 'badge--warn' ?>">
                    <?= $ldapConfigured ? 'konfiguriert' : 'unvollständig' ?>
                </span>
            </li>
        </ul>
    </section>

    <section class="card">
        <h2 class="card__title">Telefonbuch</h2>
        <p class="metric"><?= (int) $phonebookCount ?></p>
        <p class="card__hint">aktive Einträge</p>
        <p class="metric"><?= (int) $phonebookVisible ?></p>
        <p class="card__hint">eingeblendete Einträge</p>
        <p class="card__hint">
            Letzte erfolgreiche Synchronisation:
            <?= $lastSuccessfulSync === null ? 'noch keine' : Html::e(Dates::formatDateTime((string) $lastSuccessfulSync['finished_at'])) ?>
        </p>
        <?php if ($lastSync !== null && (string) $lastSync['status'] === 'error') { ?>
            <p class="flash flash--error">Der letzte Synchronisationslauf ist fehlgeschlagen. Der vorherige Datenbestand bleibt aktiv.</p>
        <?php } ?>
        <form method="post" action="/admin/ad/sync" class="inline-form" data-ad-sync>
            <?= Csrf::field() ?>
            <button type="submit" class="button button--primary">Jetzt synchronisieren</button>
        </form>
        <?php require __DIR__ . '/ldap/sync-dialog.php'; ?>
    </section>

    <section class="card">
        <h2 class="card__title">Navigation</h2>
        <p class="metric"><?= (int) $navigationCount ?></p>
        <p class="card__hint">Navigationselemente</p>
        <p><a class="button button--ghost" href="/admin/navigation">Navigation verwalten</a></p>
    </section>

    <section class="card">
        <h2 class="card__title">Klickstatistik</h2>
        <p class="metric"><?= (int) $clicks7 ?></p>
        <p class="card__hint">Klicks in den letzten 7 Tagen</p>
        <ul class="status-list">
            <li><span>Letzte 30 Tage</span><span><?= (int) $clicks30 ?></span></li>
            <li><span>Gesamt</span><span><?= (int) $clicksTotal ?></span></li>
        </ul>
        <p><a class="button button--ghost" href="/admin/statistik">Zur Statistik</a></p>
    </section>

    <?php
    /** @var array<string,mixed> $authMetrics */
    /** @var array<string,mixed> $authMetricsHistory Verlauf (AuthMetricsService::history()) */
    $cpu = is_array($authMetrics['cpu'] ?? null) ? $authMetrics['cpu'] : null;
    $ram = is_array($authMetrics['ram'] ?? null) ? $authMetrics['ram'] : null;
    $tcp = is_array($authMetrics['tcp'] ?? null) ? $authMetrics['tcp'] : null;
    $sources = is_array($authMetrics['sources'] ?? null) ? $authMetrics['sources'] : [];
    $cpuLevel = (string) ($cpu['level'] ?? 'ok');
    $ramLevel = (string) ($ram['level'] ?? 'ok');
    $ramUsed = $ram !== null && is_int($ram['used'] ?? null) ? $ram['used'] : null;
    $ramTotal = $ram !== null && is_int($ram['total'] ?? null) ? $ram['total'] : null;
    $hasMetrics = $cpu !== null && $tcp !== null;
    $percent = static fn (float $value): string => number_format($value, 1, ',', '.') . ' %';
    ?>
    <section
        class="card auth-metrics auth-metrics--wide"
        data-state="<?= Html::e($cpuLevel) ?>"<?= $hasMetrics ? ' data-auth-metrics-card tabindex="0" aria-haspopup="dialog"' : '' ?>
    >
        <h2 class="card__title">Reverse-Proxy</h2>
        <?php if (!$hasMetrics) { ?>
            <p class="card__hint">
                Noch keine Messwerte empfangen. Der auth-Container misst die Werte in seinem Inneren und meldet sie
                im Minutentakt.
            </p>
        <?php } else { ?>
            <?php /* Wert, Bezugsgroesse, Beschriftung und Kennzahlen der beiden Bereiche
                     stehen mit Subgrid auf einer Hoehe (siehe public/assets/css/admin.css). */ ?>
            <div class="auth-metrics__gauges">
                <div class="auth-metrics__gauge">
                    <p class="metric auth-metrics__value auth-metrics__value--<?= Html::e($cpuLevel) ?>">
                        <?= Html::e($percent((float) $cpu['current'])) ?>
                    </p>
                    <p class="auth-metrics__reference">
                        Bezugsgröße <?= Html::e(number_format((float) $cpu['limit'], 2, ',', '.')) ?> Kerne
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
                <div class="auth-metrics__gauge">
                    <p class="metric auth-metrics__value auth-metrics__value--<?= Html::e($ramLevel) ?>">
                        <?= $ram === null ? '–' : Html::e($percent((float) $ram['current'])) ?>
                    </p>
                    <p class="auth-metrics__reference"><?= Html::e(Bytes::formatPair($ramUsed, $ramTotal)) ?></p>
                    <p class="card__hint">Arbeitsspeicher im Container (aktuell)</p>
                    <?php if ($ram === null) { ?>
                        <p class="card__hint">Der auth-Container meldet noch keinen Arbeitsspeicher.</p>
                    <?php } else { ?>
                        <ul class="status-list">
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
                        </ul>
                    <?php } ?>
                </div>
            </div>
            <hr class="auth-metrics__divider">
            <div class="auth-metrics__traffic">
                <details class="auth-metrics__details auth-metrics__details--traffic">
                    <summary class="auth-metrics__subtitle">
                        <span>Verbindungen (seit der letzten Messung)</span>
                        <span class="auth-metrics__summary-value"><?= (int) $tcp['connections'] ?></span>
                    </summary>
                    <ul class="status-list">
                        <li><span>Spitze (<?= (int) $tcp['window'] ?> h)</span><span><?= (int) $tcp['peak'] ?></span></li>
                        <li>
                            <span>Mittel (<?= (int) $tcp['window'] ?> h)</span>
                            <span><?= Html::e(number_format((float) $tcp['avg'], 1, ',', '.')) ?></span>
                        </li>
                    </ul>
                </details>
                <details class="auth-metrics__details auth-metrics__details--traffic">
                    <summary class="auth-metrics__subtitle">
                        <span>Anfragen nach Quellnetz</span>
                        <span class="auth-metrics__summary-value">
                            <?= count($sources) ?> <?= count($sources) === 1 ? 'Netz' : 'Netze' ?>
                        </span>
                    </summary>
                    <?php if ($sources === []) { ?>
                        <p class="card__hint">Zur letzten Messung sind keine Anfragen aus Quellnetzen eingegangen.</p>
                    <?php } else { ?>
                        <ul class="auth-metrics__sources">
                            <?php foreach ($sources as $source) { ?>
                                <?php $share = (float) ($source['share'] ?? 0); ?>
                                <li>
                                    <span class="auth-metrics__network"><?= Html::e((string) $source['network']) ?></span>
                                    <span class="auth-metrics__count"><?= (int) $source['count'] ?></span>
                                    <progress
                                        class="fillbar"
                                        max="100"
                                        value="<?= Html::e(number_format($share, 1, '.', '')) ?>"
                                        aria-label="<?= Html::e('Anteil ' . (string) $source['network']) ?>"
                                        aria-valuetext="<?= Html::e($percent($share)) ?>"
                                    ><?= Html::e($percent($share)) ?></progress>
                                </li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                </details>
            </div>
            <?php if (!empty($authMetrics['stale'])) { ?>
                <p class="flash flash--error">
                    Seit <?= Html::e((string) $authMetrics['recorded_at']) ?> sind keine neuen Messwerte eingegangen –
                    der auth-Container meldet derzeit nicht.
                </p>
            <?php } ?>
        <?php } ?>
    </section>
</div>
<?php require __DIR__ . '/reverse-proxy-dialog.php'; ?>
