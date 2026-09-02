<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,mixed>|null $lastSync */
/** @var array<string,mixed>|null $lastSuccessfulSync */
?>
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
        <p class="card__hint">
            Letzte erfolgreiche Synchronisation:
            <?= $lastSuccessfulSync === null ? 'noch keine' : Html::e(Dates::formatDateTime((string) $lastSuccessfulSync['finished_at'])) ?>
        </p>
        <?php if ($lastSync !== null && (string) $lastSync['status'] === 'error') { ?>
            <p class="flash flash--error">Der letzte Synchronisationslauf ist fehlgeschlagen. Der vorherige Datenbestand bleibt aktiv.</p>
        <?php } ?>
        <form method="post" action="/admin/ad/sync" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--primary">Jetzt synchronisieren</button>
        </form>
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
</div>
