<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Storage\IncidentSettings;
use App\Services\Storage\StorageHealth;
use App\Support\Dates;
use App\Support\Html;

/** @var list<array<string,mixed>> $incidents */
/** @var array{count:int,users:list<string>,target:string,title:string,message:string}|null $alert */
/** @var array<string,mixed>|null $confirm */
/** @var IncidentSettings $settings */
/** @var array<int,string> $targets */
/** @var bool $tieringEnabled */
/** @var array<string,string> $errors */
/** @var array<string,string> $values */

$current = $values + $settings->all();
$time = static fn (?string $value): string => Dates::formatDateTime($value) ?: '–';
$number = static fn (int $value): string => number_format($value, 0, ',', '.');
$field = static function (string $key, string $text, string $hint, string $unit = '') use ($current, $errors): string {
    $meta = IncidentSettings::NUMERIC[$key];
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
$kindLabel = ['extension' => 'Ransomware-Endung', 'content' => 'verschlüsselt wirkend', 'changed' => 'überschrieben'];
$openCount = count(array_filter($incidents, static fn (array $i): bool => $i['open']));
?>
<div class="incidents-page">
<p class="card__hint">
    storage-sync überwacht die Nextcloud-Dateien auf auffälliges Überschreiben, wie es typisch für Ransomware ist: sehr viele geänderte Dateien
    in kurzer Zeit, Dateien, deren Inhalt plötzlich verschlüsselt wirkt, und Dateien mit bekannten Ransomware-Endungen (z.&nbsp;B. <code>.makop</code>).
</p>

<?php if (!$tieringEnabled) { ?>
    <p class="flash flash--info">
        Die Erkennung läuft im Dienst storage-sync und ist nur aktiv, solange das <a href="/admin/speicher-ha#einstellungen">Speicher-Tiering</a> eingeschaltet ist.
    </p>
<?php } ?>

<?php if ($alert !== null) { ?>
    <section class="incident-alert" role="alert" aria-labelledby="incident-alert-title">
        <h2 class="incident-alert__title" id="incident-alert-title"><?= Html::e($alert['title']) ?></h2>
        <p><?= Html::e($alert['message']) ?></p>
        <div class="incident-measures">
            <div>
                <h3>Was ist jetzt eingeschränkt?</h3>
                <ul>
                    <li><strong>Betroffene Benutzer</strong> (<?= Html::e(implode(', ', $alert['users'])) ?>) können ihre Dateien in Nextcloud nur noch öffnen und herunterladen – nicht ändern, hochladen, umbenennen oder löschen. Sie sehen einen Hinweis, sich beim Support zu melden.</li>
                    <li><strong>Geschütztes Speicherziel</strong>: <?= $alert['target'] !== '' ? '„' . Html::e($alert['target']) . '“ ist schreibgeschützt (read-only) eingebunden und wird nicht synchronisiert. Dort bleibt der Stand von vor dem Vorfall erhalten.' : 'Kein aktives Speicherziel vorhanden – es konnte kein Datenbestand geschützt werden.' ?></li>
                    <li>Alle übrigen Benutzer und Speicherziele arbeiten normal weiter.</li>
                </ul>
            </div>
            <div>
                <h3>Was ist zu tun?</h3>
                <ol>
                    <li>Gerät des Benutzers vom Netz trennen und prüfen (Virenscanner, IT-Sicherheit informieren).</li>
                    <li>Betroffene Dateien in Nextcloud prüfen (siehe „Details“) und ggf. aus Nextcloud-Versionen oder dem geschützten Speicherziel wiederherstellen.</li>
                    <li>Erst danach den Vorfall als „Erledigt“ markieren – die Einschränkung wird aufgehoben und das Speicherziel wieder synchronisiert. <strong>Achtung:</strong> Danach überträgt storage-sync den aktuellen Stand auch auf das geschützte Ziel.</li>
                </ol>
            </div>
        </div>
    </section>
<?php } elseif ($incidents !== []) { ?>
    <p class="flash flash--success">Es ist kein Vorfall offen. Alle Benutzer haben normalen Zugriff, alle Speicherziele werden synchronisiert.</p>
<?php } ?>

<section class="card" id="liste" aria-labelledby="incidents-title">
    <h2 class="card__title" id="incidents-title">Vorfälle<?= $openCount > 0 ? ' (' . $openCount . ' offen)' : '' ?></h2>
    <?php if ($incidents === []) { ?>
        <p class="card__hint">Bisher wurde kein Vorfall erkannt.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table incidents-table">
                <thead>
                    <tr>
                        <th scope="col">Nr.</th>
                        <th scope="col">Erkannt</th>
                        <th scope="col">Status</th>
                        <th scope="col">Wer</th>
                        <th scope="col">Was</th>
                        <th scope="col">Wie viel</th>
                        <th scope="col">Quelle</th>
                        <th scope="col">Maßnahmen</th>
                        <th scope="col">Details</th>
                        <th scope="col"><span class="visually-hidden">Aktion</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($incidents as $incident) {
                        $id = (int) $incident['id']; ?>
                        <tr class="<?= $incident['open'] ? 'incidents-table__open' : '' ?>">
                            <td><?= $id ?></td>
                            <td>
                                <?= Html::e($time((string) $incident['created_at'])) ?>
                                <div class="table__hint">Aktivität: <?= Html::e($time($incident['first_seen'] ?? null)) ?> – <?= Html::e($time($incident['last_seen'] ?? null)) ?></div>
                            </td>
                            <td>
                                <?php if ($incident['open']) { ?>
                                    <span class="badge badge--error">offen</span>
                                <?php } else { ?>
                                    <span class="badge badge--ok">erledigt</span>
                                    <div class="table__hint"><?= Html::e($time($incident['resolved_at'] ?? null)) ?><?= (string) $incident['resolved_by'] !== '' ? ' von ' . Html::e((string) $incident['resolved_by']) : '' ?></div>
                                <?php } ?>
                            </td>
                            <td>
                                <strong><?= Html::e((string) $incident['uid']) ?></strong>
                                <div class="table__hint"><?= $incident['attribution'] === 'session' ? 'angemeldeter Benutzer (Nextcloud-Protokoll)' : 'Besitzer der Dateien' ?></div>
                            </td>
                            <td>
                                <?php foreach ($incident['rule_labels'] as $ruleLabel) { ?>
                                    <span class="badge badge--warn"><?= Html::e($ruleLabel) ?></span>
                                <?php } ?>
                                <div class="table__hint"><?= Html::e((string) $incident['summary']) ?></div>
                            </td>
                            <td>
                                <ul class="incident-facts">
                                    <li><?= $number((int) $incident['files_changed']) ?> überschrieben</li>
                                    <li><?= $number((int) $incident['files_suspicious']) ?> verschlüsselt wirkend</li>
                                    <li><?= $number((int) $incident['files_extension']) ?> mit Ransomware-Endung</li>
                                    <li><?= Html::e(StorageHealth::formatBytes((int) $incident['bytes'])) ?> Datenmenge</li>
                                </ul>
                            </td>
                            <td>
                                Nextcloud-Dateien
                                <?php foreach (array_slice($incident['clients'], 0, 3) as $client) { ?>
                                    <div class="table__hint">
                                        IP <?= Html::e((string) ($client['ip'] ?? '') ?: 'unbekannt') ?> · <?= $number((int) ($client['writes'] ?? 0)) ?> Schreibvorgänge
                                        <?php if ((string) ($client['ua'] ?? '') !== '') { ?><br><span class="incident-ua"><?= Html::e((string) $client['ua']) ?></span><?php } ?>
                                    </div>
                                <?php } ?>
                                <?php if ($incident['clients'] === []) { ?><div class="table__hint">Client unbekannt (z. B. Änderung außerhalb von Nextcloud)</div><?php } ?>
                                <div class="table__hint">erkannt durch storage-sync</div>
                            </td>
                            <td>
                                <ul class="incident-facts">
                                    <li><?= (int) $incident['user_restricted'] === 1 ? ($incident['open'] ? 'Benutzer darf nur lesen' : 'Benutzer war eingeschränkt') : 'keine Benutzer-Einschränkung' ?></li>
                                    <li>
                                        <?php if ((string) $incident['frozen_target_label'] !== '') { ?>
                                            Ziel „<?= Html::e((string) $incident['frozen_target_label']) ?>“ <?= $incident['open'] ? 'schreibgeschützt, keine Synchronisation' : 'war schreibgeschützt' ?>
                                        <?php } else { ?>
                                            kein Speicherziel geschützt
                                        <?php } ?>
                                    </li>
                                </ul>
                            </td>
                            <td>
                                <details class="incident-details">
                                    <summary>anzeigen</summary>
                                    <?php if ($incident['owners'] !== []) { ?>
                                        <p><strong>Betroffene Dateibesitzer:</strong> <?= Html::e(implode(', ', array_map(static fn ($o, $n): string => $o . ' (' . $n . ')', array_keys($incident['owners']), $incident['owners']))) ?></p>
                                    <?php } ?>
                                    <?php if ($incident['patterns'] !== []) { ?>
                                        <p><strong>Erkannte Endungen/Muster:</strong> <?= Html::e(implode(', ', array_map(static fn ($p, $n): string => $p . ' (' . $n . ')', array_keys($incident['patterns']), $incident['patterns']))) ?></p>
                                    <?php } ?>
                                    <?php if ($incident['reasons'] !== []) { ?>
                                        <p><strong>Inhaltsprüfung:</strong> <?= Html::e(implode('; ', array_map(static fn ($r, $n): string => $r . ' (' . $n . ')', array_keys($incident['reasons']), $incident['reasons']))) ?></p>
                                    <?php } ?>
                                    <?php if ($incident['samples'] !== []) { ?>
                                        <p><strong>Beispieldateien:</strong></p>
                                        <ul class="incident-samples">
                                            <?php foreach ($incident['samples'] as $sample) { ?>
                                                <li>
                                                    <code><?= Html::e((string) ($sample['path'] ?? '')) ?></code>
                                                    <span class="table__hint">
                                                        <?= Html::e($kindLabel[(string) ($sample['kind'] ?? '')] ?? (string) ($sample['kind'] ?? '')) ?><?= (string) ($sample['detail'] ?? '') !== '' ? ': ' . Html::e((string) $sample['detail']) : '' ?>
                                                        · <?= Html::e(StorageHealth::formatBytes((int) ($sample['size'] ?? 0))) ?>
                                                        · <?= Html::e(date('d.m.Y H:i:s', (int) ($sample['at'] ?? 0))) ?>
                                                    </span>
                                                </li>
                                            <?php } ?>
                                        </ul>
                                    <?php } ?>
                                </details>
                            </td>
                            <td>
                                <?php if ($incident['open']) { ?>
                                    <a class="button button--primary" href="/admin/vorfaelle?erledigen=<?= $id ?>#liste">Erledigt</a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<section class="card" id="einstellungen" aria-labelledby="incident-settings-title">
    <h2 class="card__title" id="incident-settings-title">Einstellungen der Erkennung</h2>
    <form method="post" action="/admin/vorfaelle/einstellungen" class="form storage-settings">
        <?= Csrf::field() ?>
        <div class="field field--check">
            <input type="hidden" name="incident_detection_enabled" value="0">
            <input type="checkbox" id="incident_detection_enabled" name="incident_detection_enabled" value="1"<?= ($current['incident_detection_enabled'] ?? '1') === '1' ? ' checked' : '' ?>>
            <label for="incident_detection_enabled">Auffälliges Überschreiben erkennen</label>
            <p class="field__hint">Bei einem Vorfall darf der Benutzer nur noch lesen und ein Speicherziel des Cold-Tiers wird schreibgeschützt, bis der Vorfall erledigt ist.</p>
        </div>
        <div class="storage-fields">
            <?= $field('incident_window_minutes', 'Zeitfenster', 'Zeitraum, in dem die Dateien je Benutzer gezählt werden.', 'Minuten') ?>
            <?= $field('incident_overwrite_files', 'Überschriebene Dateien', 'Ab so vielen geänderten Dateien eines Benutzers im Zeitfenster wird ein Vorfall ausgelöst.', 'Anzahl') ?>
            <?= $field('incident_content_files', 'Verschlüsselt wirkende Dateien', 'Ab so vielen überschriebenen Dateien, deren Inhalt nicht mehr zum Dateityp passt und zufällig wirkt.', 'Anzahl') ?>
            <?= $field('incident_extension_files', 'Dateien mit Ransomware-Endung', 'Ab so vielen Dateien mit einer Endung bzw. einem Namen aus der Liste unten (1 = sofort).', 'Anzahl') ?>
        </div>
        <div class="field">
            <label for="incident_protect_target">Geschütztes Speicherziel bei einem Vorfall</label>
            <select id="incident_protect_target" name="incident_protect_target" aria-describedby="incident_protect_target-hint">
                <option value="0">Automatisch (synchrones, nicht primäres Ziel bevorzugt)</option>
                <?php foreach ($targets as $targetId => $targetLabel) { ?>
                    <option value="<?= (int) $targetId ?>"<?= (string) ($current['incident_protect_target'] ?? '0') === (string) $targetId ? ' selected' : '' ?>><?= Html::e($targetLabel) ?></option>
                <?php } ?>
            </select>
            <p class="field__hint" id="incident_protect_target-hint">Dieses Ziel wird bei einem Vorfall schreibgeschützt eingebunden und nicht mehr synchronisiert, damit der unveränderte Datenbestand erhalten bleibt.</p>
            <?php if (isset($errors['incident_protect_target'])) { ?><p class="field__error"><?= Html::e($errors['incident_protect_target']) ?></p><?php } ?>
        </div>
        <div class="field">
            <label for="incident_support_contact">Support-Kontakt (optional)</label>
            <input type="text" id="incident_support_contact" name="incident_support_contact" maxlength="<?= IncidentSettings::SUPPORT_MAX_LENGTH ?>"
                   value="<?= Html::e((string) ($current['incident_support_contact'] ?? '')) ?>" aria-describedby="incident_support_contact-hint"<?= isset($errors['incident_support_contact']) ? ' aria-invalid="true"' : '' ?>>
            <p class="field__hint" id="incident_support_contact-hint">Wird betroffenen Benutzern angezeigt, z.&nbsp;B. „IT-Hotline 1234“.</p>
            <?php if (isset($errors['incident_support_contact'])) { ?><p class="field__error"><?= Html::e($errors['incident_support_contact']) ?></p><?php } ?>
        </div>
        <div class="field">
            <label for="incident_extensions">Ransomware-Endungen und Dateinamen</label>
            <textarea id="incident_extensions" name="incident_extensions" rows="12" class="incident-patterns"
                      aria-describedby="incident_extensions-hint"<?= isset($errors['incident_extensions']) ? ' aria-invalid="true"' : '' ?>><?= Html::e((string) ($current['incident_extensions'] ?? '')) ?></textarea>
            <p class="field__hint" id="incident_extensions-hint">
                Ein Eintrag je Zeile. Eine Endung ohne Punkt (z.&nbsp;B. <code>makop</code> oder <code>*.makop</code>) prüft die letzte Dateiendung.
                Einträge mit Punkt oder Platzhaltern sind Muster für den ganzen Dateinamen: <code>*</code> = beliebige Zeichen, <code>?</code> = ein Zeichen
                (z.&nbsp;B. <code>*.[*@*].*</code> für Namen mit Kontaktadresse oder <code>how_to_decrypt*</code> für Erpresserschreiben). Groß-/Kleinschreibung spielt keine Rolle.
                Aktuell <?= count($settings->patterns()) ?> Einträge.
            </p>
            <?php if (isset($errors['incident_extensions'])) { ?><p class="field__error"><?= Html::e($errors['incident_extensions']) ?></p><?php } ?>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
            <button type="submit" class="button button--ghost" name="reset_patterns" value="1"
                    data-confirm="Die Liste der Ransomware-Endungen auf die Standardliste zurücksetzen? Eigene Einträge gehen verloren.">Standardliste wiederherstellen</button>
        </div>
    </form>
</section>
</div>

<?php if ($confirm !== null) { ?>
    <div class="nav-tree-overlay" data-incident-overlay role="alertdialog" aria-modal="true" aria-labelledby="incident-confirm-title" aria-describedby="incident-confirm-text">
        <div class="nav-tree-overlay__box incident-confirm">
            <h2 class="nav-tree-overlay__title" id="incident-confirm-title">Event wirklich als erledigt markieren?</h2>
            <div id="incident-confirm-text">
                <p>Vorfall Nr. <?= (int) $confirm['id'] ?> – Benutzer <strong><?= Html::e((string) $confirm['uid']) ?></strong>, erkannt am <?= Html::e($time((string) $confirm['created_at'])) ?>.</p>
                <p><?= Html::e((string) $confirm['summary']) ?></p>
                <ul>
                    <li>Die Einschränkung von „<?= Html::e((string) $confirm['uid']) ?>“ wird aufgehoben – der Benutzer kann Dateien wieder ändern.</li>
                    <?php if ((string) $confirm['frozen_target_label'] !== '') { ?>
                        <li>Sobald kein weiterer Vorfall offen ist, wird das Speicherziel „<?= Html::e((string) $confirm['frozen_target_label']) ?>“ wieder beschreibbar eingebunden und synchronisiert. Der dort geschützte Stand wird dabei mit dem aktuellen Stand überschrieben.</li>
                    <?php } ?>
                </ul>
                <p class="nav-tree-overlay__hint">Bitte nur bestätigen, wenn die Ursache beseitigt und die Daten geprüft bzw. wiederhergestellt sind.</p>
            </div>
            <form method="post" action="/admin/vorfaelle/erledigt">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $confirm['id'] ?>">
                <div class="form__actions">
                    <button type="submit" class="button button--danger" name="confirm" value="ja" data-incident-overlay-focus>Ja</button>
                    <a class="button button--ghost" href="/admin/vorfaelle#liste" data-incident-overlay-cancel>Nein</a>
                </div>
            </form>
        </div>
    </div>
<?php } ?>
