<?php

declare(strict_types=1);

/*
 * Gemeinsame Tabellen der Speicherplatz-Seiten (Benutzerliste, Verlauf).
 * Wird per require in die Templates eingebunden.
 */

use App\Services\Office\StorageQuotaService;
use App\Support\Dates;
use App\Support\Html;

$quotaTable = static function (array $users, string $caption, bool $showAll): void {
    ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden"><?= Html::e($caption) ?></caption>
            <thead>
            <tr>
                <th scope="col">Benutzer</th>
                <th scope="col">Kontingent</th>
                <th scope="col">Grundlage</th>
                <th scope="col">Begründung / vergeben von</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user) {
                $override = is_array($user['override'] ?? null) ? $user['override'] : null;
                $details = array_filter([(string) $user['uid'], (string) $user['department'], (string) $user['source_label']], static fn (string $part): bool => $part !== '');
                ?>
                <tr>
                    <td>
                        <?= Html::e((string) $user['display_name']) ?>
                        <div class="table__hint"><?= Html::e(implode(' · ', $details)) ?></div>
                        <?php if (empty($user['active'])) { ?>
                            <span class="badge badge--muted">nicht mehr im AD aktiv</span>
                        <?php } ?>
                    </td>
                    <td><strong><?= Html::e(StorageQuotaService::formatMb((int) $user['effective_mb'])) ?></strong></td>
                    <td>
                        <?php if ($user['origin'] === 'override') { ?>
                            <span class="badge badge--active">Individuell</span>
                            <?php if ($user['active'] && (int) $user['base_mb'] !== (int) $user['effective_mb']) { ?>
                                <div class="table__hint">ohne: <?= Html::e(StorageQuotaService::formatMb((int) $user['base_mb'])) ?></div>
                            <?php } ?>
                        <?php } elseif ($user['origin'] === 'group') { ?>
                            AD-Gruppe „<?= Html::e((string) $user['group_name']) ?>“
                        <?php } else { ?>
                            Standard
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ($override !== null) { ?>
                            <?= Html::e((string) $override['reason']) ?>
                            <div class="table__hint">
                                <?= Html::e((string) ($override['updated_by'] !== '' ? $override['updated_by'] : $override['created_by'])) ?>,
                                <?= Html::e(Dates::formatDateTime((string) $override['updated_at'])) ?>
                            </div>
                        <?php } else { ?>
                            <span class="table__hint">–</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ((int) $user['id'] > 0) { ?>
                            <a class="button button--ghost" href="/admin/speicherplatz/benutzer?id=<?= (int) $user['id'] ?>"><?= $override !== null || $showAll ? 'Anpassen' : 'Individuell festlegen' ?></a>
                        <?php } elseif ($override !== null) { ?>
                            <a class="button button--ghost" href="/admin/speicherplatz/verlauf?art=user">Verlauf</a>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php
};

$historyTable = static function (array $entries): void {
    $actions = ['set' => 'vergeben', 'change' => 'geändert', 'remove' => 'entfernt'];
    $types = ['user' => 'Benutzer', 'group' => 'AD-Gruppe', 'default' => 'Standard'];
    ?>
    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Verlauf der Kontingentänderungen</caption>
            <thead>
            <tr>
                <th scope="col">Zeitpunkt</th>
                <th scope="col">Von</th>
                <th scope="col">Für</th>
                <th scope="col">Änderung</th>
                <th scope="col">Begründung</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($entries as $entry) {
                $type = (string) $entry['subject_type'];
                $label = (string) $entry['subject_label'];
                ?>
                <tr>
                    <td><span class="table__hint"><?= Html::e(Dates::formatDateTime((string) $entry['created_at'])) ?></span></td>
                    <td><?= Html::e((string) ($entry['admin_username'] !== '' ? $entry['admin_username'] : '–')) ?></td>
                    <td>
                        <?= Html::e($type === 'default' ? 'Alle (Standard)' : ($label !== '' ? $label : (string) $entry['subject'])) ?>
                        <div class="table__hint">
                            <?= Html::e($types[$type] ?? $type) ?><?= $type === 'user' ? ' · ' . Html::e((string) $entry['subject']) : '' ?>
                        </div>
                    </td>
                    <td>
                        <?= Html::e(StorageQuotaService::formatMb($entry['old_quota_mb'])) ?>
                        → <strong><?= Html::e(StorageQuotaService::formatMb($entry['new_quota_mb'])) ?></strong>
                        <div class="table__hint"><?= Html::e($actions[(string) $entry['action']] ?? (string) $entry['action']) ?></div>
                    </td>
                    <td><?= Html::e((string) $entry['reason'] !== '' ? (string) $entry['reason'] : '–') ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php
};
