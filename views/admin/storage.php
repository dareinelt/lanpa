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
$fillbar = static function (array $fill, string $id, string $name): string {
    $percent = $fill['percent'] ?? null;

    return '<progress class="fillbar fillbar--' . Html::e((string) $fill['state']) . '"' . ($id !== '' ? ' data-fill="' . Html::e($id) . '"' : '')
        . ' max="100" value="' . Html::e((string) min(100, max(0, (float) ($percent ?? 0)))) . '" aria-label="'
        . Html::e($name) . '" aria-valuetext="' . ($percent === null ? 'Keine Messwerte' : Html::e(number_format((float) $percent, 1, ',', '.')) . ' %') . '">'
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
<div class="storage-page" id="storage-live" data-url="/admin/speicher-ha/status">
<div class="storage-heading">
    <p class="card__hint">Lokaler Speicher und externe Kopien für Nextcloud und Euro-Office – Verfügbarkeit, Auslastung und Synchronisation auf einen Blick.</p>
    <nav class="storage-links" aria-label="Speicherbereiche">
        <a href="#ziele">Speicherziele</a>
        <a href="#einstellungen">Einstellungen</a>
        <a href="#ereignisse">Ereignisse</a>
    </nav>
</div>
<?php if (!$overview['office_enabled']) { ?>
    <p class="flash flash--info">Office ist nicht aktiviert (<code>OFFICE_ENABLED</code>). Ohne Nextcloud und Euro-Office gibt es keine Daten zu synchronisieren.</p>
<?php } ?>
<?php if ((int) ($overview['incidents_open'] ?? 0) > 0) {
    $frozenLabels = array_map(static fn (array $t): string => $t['label'], array_filter($targets, static fn (array $t): bool => !empty($t['frozen']))); ?>
    <div class="incident-alert" role="alert">
        <h2 class="incident-alert__title">Sicherheitsvorfall – Cold-Tier teilweise eingefroren</h2>
        <p>
            Es <?= (int) $overview['incidents_open'] === 1 ? 'ist ein Sicherheitsvorfall' : 'sind ' . (int) $overview['incidents_open'] . ' Sicherheitsvorfälle' ?> offen.
            <?php if ($frozenLabels !== []) { ?>
                Das Speicherziel „<?= Html::e(implode('“, „', $frozenLabels)) ?>“ ist schreibgeschützt eingebunden und wird nicht synchronisiert, damit der Datenbestand von vor dem Vorfall unverändert erhalten bleibt.
            <?php } ?>
        </p>
        <p><a class="button button--danger" href="/admin/vorfaelle">Zu den Vorfällen</a></p>
    </div>
<?php } ?>
<div class="flash flash--<?= ($alert['level'] ?? '') === 'error' ? 'error' : 'info' ?>" data-storage-alert role="alert" <?= $alert === null ? 'hidden' : '' ?>>
    <strong data-live="alert-title"><?= Html::e($alert['title'] ?? '') ?></strong>
    <span data-live="alert-message"><?= Html::e($alert['message'] ?? '') ?></span>
    <p class="storage-alert__forecast" data-live="alert-forecast" <?= ($alert['forecast'] ?? '') === '' ? 'hidden' : '' ?>><?= Html::e($alert['forecast'] ?? '') ?></p>
</div>

<div class="storage-summary">
    <section class="card storage-status" data-state="<?= Html::e($health['ha']['state']) ?>" data-status-card="ha">
        <p class="storage-eyebrow">Verfügbarkeit</p>
        <h2 class="card__title">HA-Status</h2>
        <p class="metric"><span class="badge <?= $badge($health['ha']['state']) ?>" data-live="ha-state"><?= Html::e($label($health['ha']['state'])) ?></span></p>
        <p class="card__hint" data-live="ha-message"><?= Html::e($health['ha']['message']) ?></p>
        <p class="card__hint"><strong data-live="online"><?= (int) $health['online'] ?></strong> von <span data-live="active"><?= (int) $health['active'] ?></span> Speicherzielen erreichbar</p>
    </section>
    <section class="card storage-status" data-state="<?= Html::e($health['sync']['state']) ?>" data-status-card="sync">
        <p class="storage-eyebrow">Datenabgleich</p>
        <h2 class="card__title">Synchronisation</h2>
        <p class="metric"><span class="badge <?= $badge($health['sync']['state']) ?>" data-live="sync-state"><?= Html::e($label($health['sync']['state'])) ?></span></p>
        <p class="card__hint" data-live="sync-message"><?= Html::e($health['sync']['message']) ?></p>
        <p class="card__hint">Letzter Abgleich: <span data-live="last-sync"><?= Html::e(Dates::formatDateTime((string) ($status['last_sync_at'] ?? ''))) ?: '–' ?></span></p>
            <form method="post" action="/admin/speicher-ha/auftrag" class="inline-form" data-confirm-deletes <?= $health['sync']['state'] !== 'blocked' ? 'hidden' : '' ?>>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="confirm_deletes">
                <button type="submit" class="button button--danger"
                        data-confirm="Sind die Dateien wirklich gelöscht worden (und nicht etwa ein Volume falsch eingebunden)? Die Löschungen werden dann auch auf allen Speicherzielen des Cold-Tiers ausgeführt.">Löschungen übernehmen</button>
            </form>
    </section>
    <section class="card">
        <p class="storage-eyebrow">Nextcloud &amp; Euro-Office</p>
        <h2 class="card__title">Datenbestand</h2>
        <p class="metric" data-live="inventory-bytes"><?= Html::e(StorageHealth::formatBytes((int) ($status['bytes_total'] ?? 0))) ?></p>
        <p class="card__hint">
            <span data-live="inventory-files"><?= number_format((int) ($status['files_total'] ?? 0), 0, ',', '.') ?></span> Dateien ·
            Hot-Tier <span data-live="inventory-local"><?= Html::e(StorageHealth::formatBytes((int) ($status['bytes_local'] ?? 0))) ?></span> ·
            nur Cold-Tier <span data-live="inventory-evicted"><?= Html::e(StorageHealth::formatBytes((int) ($status['bytes_evicted'] ?? 0))) ?></span>
            (<span data-live="inventory-evicted_files"><?= number_format((int) ($status['files_evicted'] ?? 0), 0, ',', '.') ?></span> Dateien)
        </p>
        <p class="card__hint">
            Ausstehend: <span data-live="pending"><?= (int) ($status['pending_files'] ?? 0) ?></span> ·
            Rückholungen aktiv: <span data-live="recalls"><?= (int) ($status['recalls_active'] ?? 0) ?></span>
            (gesamt <span data-live="inventory-recalls_total"><?= (int) ($status['recalls_total'] ?? 0) ?></span>, fehlgeschlagen <span data-live="inventory-recalls_failed"><?= (int) ($status['recalls_failed'] ?? 0) ?></span>)
        </p>
    </section>
</div>

<p class="storage-live-state" data-live="updated" aria-live="polite">Werte werden alle 5 Sekunden aktualisiert.</p>
<noscript><p class="flash flash--info">JavaScript ist deaktiviert. Für aktuelle Messwerte bitte die Seite neu laden.</p></noscript>

<div class="storage-tiers">
<section class="card storage-hot" aria-labelledby="hot-title">
    <div class="storage-section-head">
        <div>
            <p class="storage-eyebrow">01 · Lokal auf der VM</p>
            <h2 class="card__title" id="hot-title">Hot-Tier (lokales Storage)</h2>
        </div>
        <span class="badge <?= $badge($overview['mode']) ?>" data-live="mode"><?= Html::e($label($overview['mode'])) ?></span>
    </div>
    <p class="card__hint">Häufig genutzte und kürzlich geänderte Dateien bleiben lokal verfügbar.</p>
    <p class="metric" data-live="local-percent"><?= $local['fill']['percent'] === null ? '–' : Html::e(number_format((float) $local['fill']['percent'], 1, ',', '.')) . ' %' ?></p>
    <?= $fillbar($local['fill'], 'local', 'Füllstand Hot-Tier') ?>
    <p class="card__hint">
        <span data-live="local-used"><?= Html::e(StorageHealth::formatBytes($local['used_bytes'])) ?></span> von
        <span data-live="local-total"><?= Html::e(StorageHealth::formatBytes($local['total_bytes'])) ?></span> belegt
    </p>
    <dl class="storage-metrics storage-metrics--rates">
        <div><dt>Lesen</dt><dd data-live="local-read"><?= Html::e(StorageHealth::formatRate($local['read_bps'])) ?></dd></div>
        <div><dt>Schreiben</dt><dd data-live="local-write"><?= Html::e(StorageHealth::formatRate($local['write_bps'])) ?></dd></div>
        <div><dt>IOPS</dt><dd data-live="local-iops"><?= Html::e(number_format($local['read_iops'] + $local['write_iops'], 1, ',', '.')) ?></dd></div>
    </dl>
</section>
<section class="card storage-forecast" aria-labelledby="forecast-title">
    <p class="storage-eyebrow">Kapazitätsplanung</p>
    <h2 class="card__title" id="forecast-title">Reserve bei Ausfall des Cold-Tiers</h2>
    <p class="storage-forecast__value" data-live="forecast"><?= Html::e((string) $overview['forecast_text']) ?></p>
    <p class="card__hint">
        Grundlage ist das Wachstum des gesamten Datenbestands (Hot- und Cold-Tier) der letzten 7 Tage. Sie zeigt, wie lange
        der Hot-Tier reicht, falls der Cold-Tier (SMB-Tier) nicht erreichbar ist und alle neuen Daten auf der VM bleiben müssen.
        Limit des Hot-Tiers: <?= $local['limit_auto'] ? 'automatisch (nach freiem Platz)' : Html::e(StorageHealth::formatBytes($settings->localLimitBytes())) ?>.
    </p>
</section>
</div>

<div class="storage-flow" aria-label="Datenfluss zwischen den Speicherstufen">
    <span><span aria-hidden="true">↓</span> Synchronisieren &amp; auslagern</span>
    <code>storage-sync</code>
    <span><span aria-hidden="true">↑</span> Bei Bedarf zurückholen</span>
</div>

<section class="card" id="ziele" aria-labelledby="targets-title">
    <div class="storage-section-head">
        <div>
            <p class="storage-eyebrow">02 · Externe Kopien</p>
            <h2 class="card__title" id="targets-title">Cold-Tier (SMB-Tier)</h2>
        </div>
        <a class="button button--primary" href="/admin/speicher-ha/ziel">Speicherziel hinzufügen</a>
    </div>
    <p class="card__hint">Jedes aktive SMB-Ziel erhält eine vollständige Kopie. Mindestens zwei Ziele ermöglichen Redundanz außerhalb der VM.</p>
    <?php if ($targets === []) { ?>
        <div class="storage-empty">
            <h3>Noch kein Speicherziel eingerichtet</h3>
            <p>Fügen Sie eine SMB-Freigabe per UNC-Pfad hinzu und aktivieren Sie anschließend die Synchronisation in den <a href="#einstellungen">Einstellungen</a>.</p>
        </div>
    <?php } else { ?>
        <div class="storage-targets">
                <?php foreach ($targets as $target) { ?>
                    <article class="storage-target" data-target="<?= (int) $target['id'] ?>" data-state="<?= Html::e($target['state']) ?>" aria-labelledby="target-<?= (int) $target['id'] ?>-title">
                        <div class="storage-section-head">
                            <h3 id="target-<?= (int) $target['id'] ?>-title"><?= Html::e($target['label']) ?></h3>
                            <span class="badge <?= $badge($target['state']) ?>" data-live="state"><?= Html::e($label($target['state'])) ?></span>
                        </div>
                        <div>
                            <?php if ($target['is_primary']) { ?><span class="badge badge--active">primär</span><?php } ?>
                            <?php if (!empty($target['frozen'])) { ?><span class="badge badge--error" title="Wegen eines Sicherheitsvorfalls schreibgeschützt eingebunden – keine Synchronisation bis zur Erledigung">schreibgeschützt (Vorfall)</span><?php } ?>
                            <?php if (!$target['active']) { ?><span class="badge badge--muted">deaktiviert</span><?php } ?>
                            <p class="storage-target__path"><code><?= Html::e($target['unc_path']) ?></code></p>
                            <div class="table__hint">
                                <?= $target['username'] !== '' ? Html::e(($target['domain'] !== '' ? $target['domain'] . '\\' : '') . $target['username']) : 'Gastzugriff' ?>
                                · <?= Html::e($target['smb_version'] === 'auto' ? 'SMB automatisch' : 'SMB ' . $target['smb_version']) ?>
                            </div>
                        </div>
                        <p class="storage-target__message" data-live="message" <?= $target['message'] === '' ? 'hidden' : '' ?>><?= Html::e($target['message']) ?></p>
                        <div>
                            <div class="storage-section-head">
                                <span class="card__hint">Füllstand</span>
                                <strong data-live="fill-text"><?= $target['fill']['percent'] === null ? '–' : Html::e(number_format((float) $target['fill']['percent'], 1, ',', '.')) . ' %' ?></strong>
                            </div>
                            <?= $fillbar($target['fill'], 'target-' . $target['id'], 'Füllstand ' . $target['label']) ?>
                            <p class="card__hint">Frei <span data-live="free"><?= Html::e(StorageHealth::formatBytes($target['free_bytes'])) ?></span> von <span data-live="total"><?= Html::e(StorageHealth::formatBytes($target['total_bytes'])) ?></span></p>
                        </div>
                        <dl class="storage-metrics storage-metrics--rates">
                            <div><dt>Lesen</dt><dd data-live="read"><?= Html::e(StorageHealth::formatRate($target['read_bps'])) ?></dd></div>
                            <div><dt>Schreiben</dt><dd data-live="write"><?= Html::e(StorageHealth::formatRate($target['write_bps'])) ?></dd></div>
                            <div><dt>IOPS</dt><dd data-live="iops"><?= Html::e(number_format($target['read_iops'] + $target['write_iops'], 1, ',', '.')) ?></dd></div>
                        </dl>
                        <div class="storage-target__sync">
                            <div class="storage-section-head"><strong>Synchronisation</strong>
                                <span class="badge <?= !$target['active'] ? 'badge--muted' : ($target['in_sync'] ? 'badge--ok' : 'badge--warn') ?>" data-live="sync"><?= !$target['active'] ? 'inaktiv' : ($target['in_sync'] ? 'synchron' : 'ausstehend') ?></span>
                            </div>
                            <p class="card__hint"><span data-live="synced-files"><?= number_format($target['synced_files'], 0, ',', '.') ?></span> Dateien · <span data-live="synced-bytes"><?= Html::e(StorageHealth::formatBytes($target['synced_bytes'])) ?></span></p>
                            <p class="card__hint" data-target-pending <?= !$target['active'] || $target['pending_files'] === 0 ? 'hidden' : '' ?>>
                                Ausstehend: <span data-live="pending"><?= (int) $target['pending_files'] ?></span> (<span data-live="pending-bytes"><?= Html::e(StorageHealth::formatBytes($target['pending_bytes'])) ?></span>) ·
                                Rückstand <span data-live="lag"><?= Html::e(StorageHealth::formatDuration($target['lag_seconds'])) ?></span>
                            </p>
                        </div>
                        <div class="storage-target__actions">
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
                        </div>
                    </article>
                <?php } ?>
        </div>
    <?php } ?>
    <div class="form__actions">
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
    <form method="post" action="/admin/speicher-ha/einstellungen" class="form storage-settings">
        <?= Csrf::field() ?>
        <?php if (isset($errors['storage_enabled'])) { ?>
            <p class="field__error"><?= Html::e($errors['storage_enabled']) ?></p>
        <?php } ?>
        <?= $check('storage_enabled', 'Daten im Cold-Tier (SMB-Tier) ablegen (Synchronisation aktiv)', 'Ohne Haken wird der Cold-Tier nicht mehr beschrieben. Ausgelagerte Dateien müssen vorher in den Hot-Tier zurückgeholt sein.') ?>
        <?= $check('storage_eviction_enabled', 'Speicher-Tiering: selten genutzte Dateien aus dem Hot-Tier auslagern', 'Ohne Haken bleiben alle Dateien zusätzlich im Hot-Tier; der Cold-Tier ist dann eine reine Kopie.') ?>
        <fieldset class="storage-fieldset">
        <legend>Hot-Tier (lokales Storage)</legend>
        <div class="storage-fields">
        <?= $field('storage_local_days', 'Dateien der letzten X Tage im Hot-Tier vorhalten', 'Dateien, die in diesem Zeitraum geändert oder geöffnet wurden, bleiben im Hot-Tier.', 'Tage') ?>
        <?= $field('storage_hot_access_days', 'Häufig genutzt ab', 'Dateien, die innerhalb von 30 Tagen an mindestens so vielen Tagen geöffnet wurden, bleiben im Hot-Tier – auch wenn sie älter sind.', 'Zugriffstage') ?>
        <?= $field('storage_local_max_file_mb', 'Maximale Dateigröße im Hot-Tier', 'Größere Dateien werden nach der Synchronisation nur im Cold-Tier vorgehalten. 0 = keine Begrenzung.', 'MB') ?>
        <?= $field('storage_local_limit_mb', 'Limit des Hot-Tiers gesamt', 'Belegen die Daten im Hot-Tier mehr, werden selten genutzte Dateien ausgelagert und neue Daten nur noch im Cold-Tier vorgehalten, bis wieder Platz ist. 0 = automatisch nach freiem Platz (unter 10 % frei).', 'MB') ?>
        </div>
        </fieldset>
        <fieldset class="storage-fieldset">
        <legend>Synchronisation und Überwachung</legend>
        <div class="storage-fields">
        <?= $field('storage_full_scan_minutes', 'Vollständiger Abgleich alle', 'Änderungen werden sofort erkannt (inotify); der vollständige Abgleich ist die Absicherung.', 'Minuten') ?>
        <?= $field('storage_db_dump_minutes', 'Datenbank-Abzug von Nextcloud alle', 'Ein Abzug der Nextcloud-Datenbank liegt mit im Cold-Tier.', 'Minuten') ?>
        <?= $field('storage_lag_warn_minutes', 'Warnung bei Rückstand ab', 'Nicht übertragene Änderungen älter als dieser Wert gelten als Rückstand (SNMP: Warnung).', 'Minuten') ?>
        <?= $field('storage_fill_warn_percent', 'Füllstand Warnung ab', 'Gilt für Hot-Tier und alle Speicherziele des Cold-Tiers.', '%') ?>
        <?= $field('storage_fill_crit_percent', 'Füllstand kritisch ab', 'Gilt für Hot-Tier und alle Speicherziele des Cold-Tiers.', '%') ?>
        <?= $field('storage_recall_timeout', 'Maximale Wartezeit beim Zurückholen', 'So lange wartet Nextcloud auf eine ausgelagerte Datei, bevor das Öffnen abbricht.', 'Sekunden') ?>
        </div>
        </fieldset>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<details class="card storage-details">
    <summary>Überwachung per SNMP &amp; Funktionsweise</summary>
    <p class="card__hint">Der Hot-Tier hält häufig genutzte Dateien lokal. Ältere Dateien werden nach dem Abgleich als Sparse-Platzhalter vorgehalten und bei Bedarf automatisch zurückgeholt. Nextcloud zeigt dabei einen Fortschrittsbalken. Details: <code>docs/storage.md</code>.</p>
    <p class="card__hint">
        Der SNMP-Dienst liefert in der <code>extTable</code> (<code>.1.3.6.1.4.1.2021.8.1</code>) die Einträge
        13 <code>storage_ha</code>, 14 <code>storage_sync</code>, 15 <code>storage_hot_fill</code> (Hot-Tier) und
        16 <code>storage_cold_fill</code> (Cold-Tier) (Exit-Code 0 = OK, 1 = Warnung, 2 = kritisch, 3 = inaktiv). Alle Kennzahlen
        (Füllstand, MB/s, IOPS, Rückstand, Rückholungen) stehen zusätzlich über <code>NET-SNMP-EXTEND-MIB</code> unter
        <code>storage_metrics</code> und <code>storage_targets</code> bereit – siehe <a href="/admin/snmp">SNMP</a>.
    </p>
</details>

<section class="card" id="ereignisse" aria-labelledby="events-title">
    <h2 class="card__title" id="events-title">Ereignisse</h2>
    <p class="card__hint">Stand beim Laden der Seite. <a href="/admin/speicher-ha#ereignisse">Ereignisse neu laden</a></p>
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
</div>
