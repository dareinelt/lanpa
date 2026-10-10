<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;
use App\Support\SourceNetworks;

/** @var string $value */
/** @var array<string,string> $errors */
/** @var list<array{network:string,first:string,last:string,addresses:float,host_bits:int}> $networks */
/** @var array{recorded_at:?string,rows:list<array{network:string,count:int,target:?string}>,merged:list<array{network:string,count:int,share:float}>} $preview */

/** Zahl der Adressen eines Netzes; bei sehr grossen Netzen (IPv6) als Zweierpotenz. */
$addressCount = static function (array $network): string {
    if ($network['addresses'] <= 4294967296.0) {
        return number_format($network['addresses'], 0, ',', '.');
    }

    return '2^' . $network['host_bits'];
};
?>
<section class="card">
    <h2 class="card__title">Bekannte Quellnetze</h2>
    <p class="card__hint">
        Der Reverse-Proxy meldet die Anfragen je Quellnetz verfeinert: bei IPv4 je /24, bei IPv6 je /64.
        Größere Netze des eigenen Hauses lassen sich hier in CIDR-Schreibweise eintragen (ein Netz je Zeile
        oder durch Leerzeichen, Komma oder Semikolon getrennt, IPv4 und IPv6). Der Adressbereich wird aus
        dem Präfix errechnet; alle gemeldeten Netze, die darin liegen, werden in der Statistik
        („Anfragen nach Quellnetz“ auf dem <a href="/admin">Dashboard</a>) zu dem bekannten Netz
        zusammengefasst – auch rückwirkend für bereits gemessene Proben.
    </p>
    <p class="card__hint">
        Beispiel: <code>192.168.200.0/21</code> reicht von 192.168.200.0 bis 192.168.207.255 und fasst damit
        die gemeldeten Netze 192.168.200.0/24 bis 192.168.207.0/24 zu einem Eintrag zusammen. Ist das Feld
        leer, bleibt jedes gemeldete Netz einzeln.
    </p>

    <form method="post" action="/admin/quellnetze" class="form form--wide">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="auth_known_source_networks">Netze (CIDR)</label>
            <textarea id="auth_known_source_networks" name="auth_known_source_networks" rows="6"
                      spellcheck="false" autocomplete="off"
                      placeholder="192.168.200.0/21"
                      <?= isset($errors['auth_known_source_networks']) ? 'aria-invalid="true" aria-describedby="auth_known_source_networks-error"' : '' ?>><?= Html::e($value) ?></textarea>
            <p class="field__hint">Höchstens <?= SourceNetworks::MAX_NETWORKS ?> Netze. Ohne Präfix gilt die Angabe als einzelne Adresse (/32 bzw. /128).</p>
            <?php if (isset($errors['auth_known_source_networks'])) { ?>
                <p class="field__error" id="auth_known_source_networks-error"><?= Html::e($errors['auth_known_source_networks']) ?></p>
            <?php } ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card__title">Errechnete Bereiche</h2>
    <?php if ($networks === []) { ?>
        <p class="card__hint">Es sind keine bekannten Quellnetze eingetragen.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Bereiche der bekannten Quellnetze</caption>
                <thead>
                <tr>
                    <th scope="col">Netz</th>
                    <th scope="col">Erste Adresse</th>
                    <th scope="col">Letzte Adresse</th>
                    <th scope="col">Adressen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($networks as $network) { ?>
                    <tr>
                        <td><code><?= Html::e($network['network']) ?></code></td>
                        <td><code><?= Html::e($network['first']) ?></code></td>
                        <td><code><?= Html::e($network['last']) ?></code></td>
                        <td><?= Html::e($addressCount($network)) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<section class="card">
    <h2 class="card__title">Wirkung auf die letzte Messung</h2>
    <?php if ($preview['recorded_at'] === null) { ?>
        <p class="card__hint">Es liegen noch keine Messwerte des auth-Containers vor.</p>
    <?php } else { ?>
        <p class="card__hint">Probe vom <?= Html::e($preview['recorded_at']) ?>. Links die gemeldeten
            Quellnetze, rechts das bekannte Netz, dem sie zugeordnet werden.</p>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Zusammenfassung der letzten Messung</caption>
                <thead>
                <tr>
                    <th scope="col">Gemeldetes Quellnetz</th>
                    <th scope="col">Anfragen</th>
                    <th scope="col">Wird zusammengefasst zu</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['rows'] as $row) { ?>
                    <tr>
                        <td><code><?= Html::e($row['network']) ?></code></td>
                        <td><?= (int) $row['count'] ?></td>
                        <td>
                            <?php if ($row['target'] === null) { ?>
                                – (bleibt einzeln)
                            <?php } else { ?>
                                <code><?= Html::e($row['target']) ?></code>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <h3>Anzeige in der Statistik</h3>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Anzeige der letzten Messung nach der Zusammenfassung</caption>
                <thead>
                <tr>
                    <th scope="col">Quellnetz</th>
                    <th scope="col">Anfragen</th>
                    <th scope="col">Anteil</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['merged'] as $source) { ?>
                    <tr>
                        <td><code><?= Html::e($source['network']) ?></code></td>
                        <td><?= (int) $source['count'] ?></td>
                        <td><?= Html::e(number_format($source['share'], 1, ',', '.')) ?> %</td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
