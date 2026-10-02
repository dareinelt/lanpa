<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Storage\StorageHealth;
use App\Services\Storage\StorageSettings;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,mixed> $overview */
/** @var array{level:string,title:string,message:string,forecast:string}|null $alert */
/** @var list<array<string,mixed>> $events */
/** @var array<string,string> $errors */
/** @var array<string,string> $values */

/** @var StorageSettings $settings */
$settings = $overview['settings'];
$health = $overview['health'];
$local = $overview['local'];
$status = $overview['status'];
$targets = $overview['targets'];
$current = $values + $settings->all();

$badge = static function (string $state): string {
    return match ($state) {
        'ok', 'in_sync', 'online', 'normal' => 'badge--ok',
        'degraded', 'lagging', 'syncing', 'remote_only', 'mounting', 'paused' => 'badge--warn',
        'critical', 'error', 'offline', 'invalid', 'blocked' => 'badge--error',
        default => 'badge--muted',
    };
};
$label = static function (string $state): string {
    return [
        'ok' => 'OK', 'degraded' => 'eingeschränkt', 'critical' => 'kritisch', 'disabled' => 'inaktiv',
        'in_sync' => 'synchron', 'syncing' => 'überträgt', 'lagging' => 'Rückstand', 'error' => 'Fehler',
        'online' => 'erreichbar', 'offline' => 'nicht erreichbar', 'mounting' => 'wird eingebunden',
        'invalid' => 'ungültig', 'unknown' => 'unbekannt', 'normal' => 'normal', 'remote_only' => 'nur Cold-Tier',
        'blocked' => 'angehalten', 'paused' => 'pausiert',
    ][$state] ?? $state;
};
$fillbar = static function (array $fill, string $id = ''): string {
    $percent = $fill['percent'] ?? null;

    return '<progress class="fillbar fillbar--' . Html::e((string) $fill['state']) . '"' . ($id !== '' ? ' data-fill="' . Html::e($id) . '"' : '')
        . ' max="100" value="' . Html::e((string) min(100, (float) ($percent ?? 0))) . '" aria-label="Füllstand">'
        . ($percent === null ? '–' : Html::e(number_format((float) $percent, 1, ',', '.')) . ' %') . '</progress>';
};
$field = static function (string $key, string $text, string $hint, string $unit = '') use ($current, $errors): string {
    $meta = StorageSettings::NUMERIC[$key];
    $html = '<div class="field"><label for="' . $key . '">' . Html::e($text) . ($unit !== '' ? ' (' . Html::e($unit) . ')' : '') . '</label>'
        . '<input type="number" id="' . $key . '" name="' . $key . '" min="' . $meta['min'] . '" max="' . $meta['max'] . '" value="'
        . Html::e((string) ($current[$key] ?? $meta['default'])) . '"'
        . (isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $key . '-error"' : ' aria-describedby="' . $key . '-hint"') . '>'
        . '<p class="field__hint" id="' . $key . '-hint">' . Html::e($hint) . '</p>';
    if (isset($errors[$key])) {
        $html .= '<p class="field__error" id="' . $key . '-error">' . Html::e($errors[$key]) . '</p>';
    }

    return $html . '</div>';
};
$check = static function (string $key, string $text, string $hint) use ($current): string {
    return '<div class="field field--check"><input type="hidden" name="' . $key . '" value="0">'
        . '<input type="checkbox" id="' . $key . '" name="' . $key . '" value="1"' . (($current[$key] ?? '0') === '1' ? ' checked' : '') . '>'
        . '<label for="' . $key . '">' . Html::e($text) . '</label><p class="field__hint">' . Html::e($hint) . '</p></div>';
};
?>
<p class="card__hint">
    Die Daten von Nextcloud und Euro-Office werden in zwei Stufen gespeichert (<strong>Speicher-Tiering</strong>):
    Der <strong>Hot-Tier</strong> ist das lokale Storage der VM und hält nur häufig genutzte und kürzlich geänderte Dateien.
    Der <strong>Cold-Tier</strong> (SMB-Tier) besteht aus SMB-Freigaben (UNC) außerhalb der Container; jedes Speicherziel
    darin enthält eine identische, vollständige Kopie aller Daten. Ältere Dateien werden aus dem Hot-Tier ausgelagert und
    bei Bedarf automatisch aus dem Cold-Tier zurückgeholt – Benutzer sehen dabei einen Fortschrittsbalken. Der Dienst
    <code>storage-sync</code> übernimmt Einbindung, Synchronisation, Auslagerung und Rückholung. Details: <code>docs/storage.md</code>.
</p>
<?php if (!$overview['office_enabled']) { ?>
    <p class="flash flash--info">Office ist nicht aktiviert (<code>OFFICE_ENABLED</code>). Ohne Nextcloud und Euro-Office gibt es keine Daten zu synchronisieren.</p>
<?php } ?>
<?php if ($alert !== null) { ?>
    <div class="flash flash--<?= $alert['level'] === 'error' ? 'error' : 'info' ?>" role="alert">
        <strong><?= Html::e($alert['title']) ?>:</strong> <?= Html::e($alert['message']) ?>
        <?php if ($alert['forecast'] !== '') { ?>
            <p class="storage-alert__forecast"><?= Html::e($alert['forecast']) ?></p>
        <?php } ?>
    </div>
<?php } ?>

<div class="cards" id="storage-live" data-url="/admin/speicher-ha/status">
    <section class="card">
        <h2 class="card__title">HA-Status</h2>
        <p class="metric"><span class="badge <?= $badge($health['ha']['state']) ?>" data-live="ha-state"><?= Html::e($label($health['ha']['state'])) ?></span></p>
        <p class="card__hint" data-live="ha-message"><?= Html::e($health['ha']['message']) ?></p>
        <p class="card__hint"><?= (int) $health['online'] ?> von <?= (int) $health['active'] ?> Speicherzielen im Cold-Tier erreichbar</p>
    </section>
    <section class="card">
        <h2 class="card__title">Synchronisation</h2>
        <p class="metric"><span class="badge <?= $badge($health['sync']['state']) ?>" data-live="sync-state"><?= Html::e($label($health['sync']['state'])) ?></span></p>
        <p class="card__hint" data-live="sync-message"><?= Html::e($health['sync']['message']) ?></p>
        <p class="card__hint">Letzter Abgleich: <?= Html::e(Dates::formatDateTime((string) ($status['last_sync_at'] ?? ''))) ?: '–' ?></p>
        <?php if ($health['sync']['state'] === 'blocked') { ?>
            <form method="post" action="/admin/speicher-ha/auftrag" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="confirm_deletes">
                <button type="submit" class="button button--danger"
                        data-confirm="Sind die Dateien wirklich gelöscht worden (und nicht etwa ein Volume falsch eingebunden)? Die Löschungen werden dann auch auf allen Speicherzielen des Cold-Tiers ausgeführt.">Löschungen übernehmen</button>
            </form>
        <?php } ?>
    </section>
    <section class="card">
        <h2 class="card__title">Hot-Tier (lokales Storage)</h2>
        <p class="metric" data-live="local-percent"><?= $local['fill']['percent'] === null ? '–' : Html::e(number_format((float) $local['fill']['percent'], 1, ',', '.')) . ' %' ?></p>
        <?= $fillbar($local['fill'], 'local') ?>
        <p class="card__hint">
            <span data-live="local-used"><?= Html::e(StorageHealth::formatBytes($local['used_bytes'])) ?></span> von
            <span data-live="local-total"><?= Html::e(StorageHealth::formatBytes($local['total_bytes'])) ?></span> belegt ·
            Modus: <span class="badge <?= $badge($overview['mode']) ?>" data-live="mode"><?= Html::e($label($overview['mode'])) ?></span>
        </p>
        <dl class="storage-metrics">
            <dt>Lesen</dt><dd data-live="local-read"><?= Html::e(StorageHealth::formatRate($local['read_bps'])) ?></dd>
            <dt>Schreiben</dt><dd data-live="local-write"><?= Html::e(StorageHealth::formatRate($local['write_bps'])) ?></dd>
            <dt>IOPS</dt><dd data-live="local-iops"><?= Html::e(number_format($local['read_iops'] + $local['write_iops'], 1, ',', '.')) ?></dd>
        </dl>
    </section>
    <section class="card">
        <h2 class="card__title">Datenbestand</h2>
        <p class="metric"><?= Html::e(StorageHealth::formatBytes((int) ($status['bytes_total'] ?? 0))) ?></p>
        <p class="card__hint">
            <?= number_format((int) ($status['files_total'] ?? 0), 0, ',', '.') ?> Dateien ·
            Hot-Tier <?= Html::e(StorageHealth::formatBytes((int) ($status['bytes_local'] ?? 0))) ?> ·
            nur Cold-Tier <?= Html::e(StorageHealth::formatBytes((int) ($status['bytes_evicted'] ?? 0))) ?>
            (<?= number_format((int) ($status['files_evicted'] ?? 0), 0, ',', '.') ?> Dateien)
        </p>
        <p class="card__hint">
            Ausstehend: <span data-live="pending"><?= (int) ($status['pending_files'] ?? 0) ?></span> ·
            Rückholungen aktiv: <span data-live="recalls"><?= (int) ($status['recalls_active'] ?? 0) ?></span>
            (gesamt <?= (int) ($status['recalls_total'] ?? 0) ?>, fehlgeschlagen <?= (int) ($status['recalls_failed'] ?? 0) ?>)
        </p>
    </section>
</div>

<p class="storage-live-state" data-live="updated" aria-live="polite">Werte werden alle 5 Sekunden aktualisiert.</p>

<section class="card" aria-labelledby="forecast-title">
    <h2 class="card__title" id="forecast-title">Hochrechnung Hot-Tier (lokales Storage)</h2>
    <p><?= Html::e((string) $overview['forecast_text']) ?></p>
    <p class="card__hint">
        Grundlage ist das Wachstum des gesamten Datenbestands (Hot- und Cold-Tier) der letzten 7 Tage. Sie zeigt, wie lange
        der Hot-Tier reicht, falls der Cold-Tier (SMB-Tier) nicht erreichbar ist und alle neuen Daten auf der VM bleiben müssen.
        Limit des Hot-Tiers: <?= $local['limit_auto'] ? 'automatisch (nach freiem Platz)' : Html::e(StorageHealth::formatBytes($settings->localLimitBytes())) ?>.
    </p>
</section>

<section class="card" id="ziele" aria-labelledby="targets-title">
    <h2 class="card__title" id="targets-title">Cold-Tier (SMB-Tier): Speicherziele per UNC</h2>
    <?php if ($targets === []) { ?>
        <p class="empty-state">Im Cold-Tier ist noch kein Speicherziel eingerichtet.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Speicherziele mit Zustand und Kennzahlen</caption>
                <thead>
                <tr>
                    <th scope="col">Ziel</th>
                    <th scope="col">Zustand</th>
                    <th scope="col">Füllstand</th>
                    <th scope="col">Datenrate / IOPS</th>
                    <th scope="col">Synchronisation</th>
                    <th scope="col">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($targets as $target) { ?>
                    <tr data-target="<?= (int) $target['id'] ?>">
                        <td>
                            <strong><?= Html::e($target['label']) ?></strong>
                            <?php if ($target['is_primary']) { ?><span class="badge badge--active">primär</span><?php } ?>
                            <?php if (!$target['active']) { ?><span class="badge badge--muted">deaktiviert</span><?php } ?>
                            <div class="table__hint"><code><?= Html::e($target['unc_path']) ?></code></div>
                            <div class="table__hint">
                                <?= $target['username'] !== '' ? Html::e(($target['domain'] !== '' ? $target['domain'] . '\\' : '') . $target['username']) : 'Gastzugriff' ?>
                                · <?= Html::e($target['smb_version'] === 'auto' ? 'SMB automatisch' : 'SMB ' . $target['smb_version']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= $badge($target['state']) ?>" data-live="state"><?= Html::e($label($target['state'])) ?></span>
                            <?php if ($target['message'] !== '') { ?><div class="table__hint"><?= Html::e($target['message']) ?></div><?php } ?>
                        </td>
                        <td>
                            <?= $fillbar($target['fill'], 'target-' . $target['id']) ?>
                            <span class="table__hint">
                                <span data-live="fill-text"><?= $target['fill']['percent'] === null ? '–' : Html::e(number_format((float) $target['fill']['percent'], 1, ',', '.')) . ' %' ?></span>
                                · frei <?= Html::e(StorageHealth::formatBytes($target['free_bytes'])) ?> von <?= Html::e(StorageHealth::formatBytes($target['total_bytes'])) ?>
                            </span>
                        </td>
                        <td class="table__hint">
                            ↓ <span data-live="read"><?= Html::e(StorageHealth::formatRate($target['read_bps'])) ?></span><br>
                            ↑ <span data-live="write"><?= Html::e(StorageHealth::formatRate($target['write_bps'])) ?></span><br>
                            <span data-live="iops"><?= Html::e(number_format($target['read_iops'] + $target['write_iops'], 1, ',', '.')) ?></span> IOPS
                        </td>
                        <td>
                            <?php if ($target['active']) { ?>
                                <span class="badge <?= $target['in_sync'] ? 'badge--ok' : 'badge--warn' ?>" data-live="sync"><?= $target['in_sync'] ? 'synchron' : 'ausstehend' ?></span>
                                <div class="table__hint">
                                    <?= number_format($target['synced_files'], 0, ',', '.') ?> Dateien, <?= Html::e(StorageHealth::formatBytes($target['synced_bytes'])) ?>
                                    <?php if ($target['pending_files'] > 0) { ?>
                                        <br>ausstehend: <?= (int) $target['pending_files'] ?> (<?= Html::e(StorageHealth::formatBytes($target['pending_bytes'])) ?>),
                                        Rückstand <?= Html::e(StorageHealth::formatDuration($target['lag_seconds'])) ?>
                                    <?php } ?>
                                </div>
                            <?php } else { ?>
                                <span class="table__hint">–</span>
                            <?php } ?>
                        </td>
                        <td>
                            <a class="button button--ghost" href="/admin/speicher-ha/ziel?id=<?= (int) $target['id'] ?>">Bearbeiten</a>
                            <form method="post" action="/admin/speicher-ha/auftrag" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="remount">
                                <input type="hidden" name="target_id" value="<?= (int) $target['id'] ?>">
                                <button type="submit" class="button button--ghost">Neu einbinden</button>
                            </form>
                            <form method="post" action="/admin/speicher-ha/ziel/loeschen" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $target['id'] ?>">
                                <button type="submit" class="button button--danger"
                                        data-confirm="Speicherziel „<?= Html::e($target['label']) ?>“ entfernen? Die Daten auf der Freigabe bleiben erhalten, werden aber nicht mehr aktualisiert.">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    <div class="form__actions">
        <a class="button button--primary" href="/admin/speicher-ha/ziel">Speicherziel hinzufügen</a>
        <form method="post" action="/admin/speicher-ha/auftrag" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="sync_now">
            <button type="submit" class="button button--ghost">Jetzt synchronisieren</button>
        </form>
        <form method="post" action="/admin/speicher-ha/auftrag" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="full_scan">
            <button type="submit" class="button button--ghost">Vollständigen Abgleich starten</button>
        </form>
    </div>
</section>

<section class="card" id="einstellungen" aria-labelledby="settings-title">
    <h2 class="card__title" id="settings-title">Einstellungen</h2>
    <form method="post" action="/admin/speicher-ha/einstellungen" class="form">
        <?= Csrf::field() ?>
        <?php if (isset($errors['storage_enabled'])) { ?>
            <p class="field__error"><?= Html::e($errors['storage_enabled']) ?></p>
        <?php } ?>
        <?= $check('storage_enabled', 'Daten im Cold-Tier (SMB-Tier) ablegen (Synchronisation aktiv)', 'Ohne Haken wird der Cold-Tier nicht mehr beschrieben. Ausgelagerte Dateien müssen vorher in den Hot-Tier zurückgeholt sein.') ?>
        <?= $check('storage_eviction_enabled', 'Speicher-Tiering: selten genutzte Dateien aus dem Hot-Tier auslagern', 'Ohne Haken bleiben alle Dateien zusätzlich im Hot-Tier; der Cold-Tier ist dann eine reine Kopie.') ?>
        <h3>Hot-Tier (lokales Storage)</h3>
        <?= $field('storage_local_days', 'Dateien der letzten X Tage im Hot-Tier vorhalten', 'Dateien, die in diesem Zeitraum geändert oder geöffnet wurden, bleiben im Hot-Tier.', 'Tage') ?>
        <?= $field('storage_hot_access_days', 'Häufig genutzt ab', 'Dateien, die innerhalb von 30 Tagen an mindestens so vielen Tagen geöffnet wurden, bleiben im Hot-Tier – auch wenn sie älter sind.', 'Zugriffstage') ?>
        <?= $field('storage_local_max_file_mb', 'Maximale Dateigröße im Hot-Tier', 'Größere Dateien werden nach der Synchronisation nur im Cold-Tier vorgehalten. 0 = keine Begrenzung.', 'MB') ?>
        <?= $field('storage_local_limit_mb', 'Limit des Hot-Tiers gesamt', 'Belegen die Daten im Hot-Tier mehr, werden selten genutzte Dateien ausgelagert und neue Daten nur noch im Cold-Tier vorgehalten, bis wieder Platz ist. 0 = automatisch nach freiem Platz (unter 10 % frei).', 'MB') ?>
        <h3>Synchronisation und Überwachung</h3>
        <?= $field('storage_full_scan_minutes', 'Vollständiger Abgleich alle', 'Änderungen werden sofort erkannt (inotify); der vollständige Abgleich ist die Absicherung.', 'Minuten') ?>
        <?= $field('storage_db_dump_minutes', 'Datenbank-Abzug von Nextcloud alle', 'Ein Abzug der Nextcloud-Datenbank liegt mit im Cold-Tier.', 'Minuten') ?>
        <?= $field('storage_lag_warn_minutes', 'Warnung bei Rückstand ab', 'Nicht übertragene Änderungen älter als dieser Wert gelten als Rückstand (SNMP: Warnung).', 'Minuten') ?>
        <?= $field('storage_fill_warn_percent', 'Füllstand Warnung ab', 'Gilt für Hot-Tier und alle Speicherziele des Cold-Tiers.', '%') ?>
        <?= $field('storage_fill_crit_percent', 'Füllstand kritisch ab', 'Gilt für Hot-Tier und alle Speicherziele des Cold-Tiers.', '%') ?>
        <?= $field('storage_recall_timeout', 'Maximale Wartezeit beim Zurückholen', 'So lange wartet Nextcloud auf eine ausgelagerte Datei, bevor das Öffnen abbricht.', 'Sekunden') ?>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<section class="card" aria-labelledby="snmp-title">
    <h2 class="card__title" id="snmp-title">Überwachung per SNMP</h2>
    <p class="card__hint">
        Der SNMP-Dienst liefert in der <code>extTable</code> (<code>.1.3.6.1.4.1.2021.8.1</code>) die Einträge
        13 <code>storage_ha</code>, 14 <code>storage_sync</code>, 15 <code>storage_hot_fill</code> (Hot-Tier) und
        16 <code>storage_cold_fill</code> (Cold-Tier) (Exit-Code 0 = OK, 1 = Warnung, 2 = kritisch, 3 = inaktiv). Alle Kennzahlen
        (Füllstand, MB/s, IOPS, Rückstand, Rückholungen) stehen zusätzlich über <code>NET-SNMP-EXTEND-MIB</code> unter
        <code>storage_metrics</code> und <code>storage_targets</code> bereit – siehe <a href="/admin/snmp">SNMP</a>.
    </p>
</section>

<section class="card" aria-labelledby="events-title">
    <h2 class="card__title" id="events-title">Ereignisse</h2>
    <?php if ($events === []) { ?>
        <p class="empty-state">Noch keine Ereignisse.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Letzte Ereignisse des Speicher-Tierings</caption>
                <thead><tr><th scope="col">Zeit</th><th scope="col">Stufe</th><th scope="col">Meldung</th></tr></thead>
                <tbody>
                <?php foreach ($events as $event) { ?>
                    <tr>
                        <td class="table__hint"><?= Html::e(Dates::formatDateTime((string) $event['created_at'])) ?></td>
                        <td><span class="badge <?= $event['level'] === 'error' ? 'badge--error' : ($event['level'] === 'warning' ? 'badge--warn' : 'badge--muted') ?>"><?= Html::e(['error' => 'Fehler', 'warning' => 'Warnung'][$event['level']] ?? 'Info') ?></span></td>
                        <td>
                            <?= Html::e((string) $event['message']) ?>
                            <?php if (($event['target_label'] ?? '') !== '') { ?><div class="table__hint"><?= Html::e((string) $event['target_label']) ?></div><?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
