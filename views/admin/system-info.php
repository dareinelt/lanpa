<?php

declare(strict_types=1);

use App\Services\SystemInfoService;
use App\Support\Bytes;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,mixed> $report */
/** @var bool $secure */
$php = $report['php'];
$database = $report['database'];
$migrations = $report['migrations'];
$system = $report['system'];
$disk = $report['disk'];

$text = static fn (mixed $value): string => is_scalar($value) && (string) $value !== '' ? Html::e((string) $value) : '–';

$diskPercent = null;
if (is_int($disk['total']) && $disk['total'] > 0 && is_int($disk['used'])) {
    $diskPercent = (int) round($disk['used'] / $disk['total'] * 100);
}
?>
<p class="card__hint">
    Momentaufnahme beim Aufruf der Seite. Die Werte werden bei jedem Neuladen neu erhoben.
</p>

<div class="cards">
    <section class="card">
        <h2 class="card__title">PHP</h2>
        <ul class="status-list">
            <li><span>Version</span><span><?= $text($php['version']) ?></span></li>
            <li><span>Betriebsart (SAPI)</span><span><?= $text($php['sapi']) ?></span></li>
            <li><span>Architektur</span><span><?= $text($php['architecture']) ?></span></li>
            <li><span>Betriebssystem</span><span><?= $text($php['operating_system']) ?></span></li>
            <li><span>Zeitzone</span><span><?= $text($php['timezone']) ?></span></li>
            <li><span>Speicherlimit</span><span><?= $text($php['memory_limit']) ?></span></li>
            <li><span>Aktueller Speicherbedarf</span><span><?= Html::e(Bytes::format((int) $php['memory_usage'])) ?></span></li>
            <li><span>Spitzenspeicher</span><span><?= Html::e(Bytes::format((int) $php['memory_peak'])) ?></span></li>
            <li>
                <span>Maximale Ausführungszeit</span>
                <span><?= (int) $php['max_execution_time'] === 0 ? 'unbegrenzt' : (int) $php['max_execution_time'] . ' s' ?></span>
            </li>
            <li><span>Geladene Erweiterungen</span><span><?= (int) $php['extensions'] ?></span></li>
            <li><span>OPcache</span><span class="badge <?= $php['opcache'] ? 'badge--ok' : 'badge--muted' ?>"><?= $php['opcache'] ? 'verfügbar' : 'nicht verfügbar' ?></span></li>
        </ul>
    </section>

    <section class="card">
        <h2 class="card__title">Datenbank</h2>
        <ul class="status-list">
            <li>
                <span>Verbindung</span>
                <span class="badge <?= $database['connected'] ? 'badge--ok' : 'badge--error' ?>">
                    <?= $database['connected'] ? 'verbunden' : 'nicht erreichbar' ?>
                </span>
            </li>
            <li><span>Version (MySQL/MariaDB)</span><span><?= $text($database['version']) ?></span></li>
            <li><span>Datenbank</span><span><?= $text($database['name']) ?></span></li>
            <li><span>Zeichensatz</span><span><?= $text($database['charset']) ?></span></li>
            <li><span>Tabellen</span><span><?= $database['tables'] === null ? '–' : (int) $database['tables'] ?></span></li>
            <li><span>Größe</span><span><?= Html::e(Bytes::format(is_int($database['size_bytes']) ? $database['size_bytes'] : null)) ?></span></li>
            <li><span>Laufzeit</span><span><?= $text(SystemInfoService::formatUptime($database['uptime_seconds'])) ?></span></li>
        </ul>
    </section>

    <section class="card">
        <h2 class="card__title">Migrationsstand</h2>
        <?php if ($migrations['error'] !== null) { ?>
            <p class="flash flash--error"><?= Html::e($migrations['error']) ?></p>
        <?php } ?>
        <ul class="status-list">
            <li>
                <span>Status</span>
                <span class="badge <?= $migrations['complete'] ? 'badge--ok' : 'badge--warn' ?>">
                    <?= $migrations['complete'] ? 'vollständig' : 'offene Migrationen' ?>
                </span>
            </li>
            <li><span>Angewendet</span><span><?= (int) $migrations['applied'] ?> von <?= (int) $migrations['available'] ?> Dateien</span></li>
            <?php if (is_array($migrations['last'])) { ?>
                <li>
                    <span>Zuletzt angewendet</span>
                    <span>
                        <code><?= Html::e((string) $migrations['last']['migration']) ?></code>
                        <?= Html::e(Dates::formatDateTime($migrations['last']['applied_at'])) ?>
                    </span>
                </li>
            <?php } ?>
        </ul>

        <?php if ($migrations['pending'] !== []) { ?>
            <h3 class="card__subtitle">Noch nicht angewendet</h3>
            <ul>
                <?php foreach ($migrations['pending'] as $migration) { ?>
                    <li><code><?= Html::e($migration) ?></code></li>
                <?php } ?>
            </ul>
            <p class="card__hint">Migrationen einspielen mit <code>php scripts/migrate.php</code>.</p>
        <?php } ?>
    </section>

    <section class="card">
        <h2 class="card__title">Server</h2>
        <ul class="status-list">
            <li><span>Hostname</span><span><?= $text($system['hostname']) ?></span></li>
            <li><span>Systemlaufzeit</span><span><?= $text(SystemInfoService::formatUptime($system['uptime_seconds'])) ?></span></li>
            <?php if (is_array($system['load'])) { ?>
                <li>
                    <span>Last (1/5/15 min)</span>
                    <span>
                        <?= Html::e(number_format((float) $system['load'][0], 2, ',', '.')) ?>
                        / <?= Html::e(number_format((float) $system['load'][1], 2, ',', '.')) ?>
                        / <?= Html::e(number_format((float) $system['load'][2], 2, ',', '.')) ?>
                    </span>
                </li>
            <?php } ?>
            <li><span>Serverzeit</span><span><?= $text($system['time']) ?></span></li>
            <li>
                <span>HTTPS</span>
                <span class="badge <?= $secure ? 'badge--ok' : 'badge--warn' ?>"><?= $secure ? 'aktiv' : 'nicht aktiv' ?></span>
            </li>
        </ul>
    </section>

    <section class="card">
        <h2 class="card__title">Speicherplatz</h2>
        <ul class="status-list">
            <li><span>Belegt</span><span><?= Html::e(Bytes::format(is_int($disk['used']) ? $disk['used'] : null)) ?></span></li>
            <li><span>Frei</span><span><?= Html::e(Bytes::format(is_int($disk['free']) ? $disk['free'] : null)) ?></span></li>
            <li><span>Gesamt</span><span><?= Html::e(Bytes::format(is_int($disk['total']) ? $disk['total'] : null)) ?></span></li>
            <?php if ($diskPercent !== null) { ?>
                <li><span>Auslastung</span><span><?= $diskPercent ?> %</span></li>
            <?php } ?>
        </ul>
        <p class="card__hint">Bezieht sich auf das Dateisystem des Anwendungsverzeichnisses.</p>
    </section>
</div>
