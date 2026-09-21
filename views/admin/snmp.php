<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
?>
<section class="card">
    <h2 class="card__title">SNMP-Agent</h2>
    <p class="card__hint">
        Konfiguration des SNMP-Agenten (UDP-Port 161). Änderungen werden nach einem Neustart
        des <code>snmp</code>-Containers wirksam. Der am Host veröffentlichte UDP-Port bleibt
        über die Umgebungsvariable <code>SNMP_PORT</code> konfiguriert.
    </p>

    <form method="post" action="/admin/snmp" class="form form--wide">
        <?= Csrf::field() ?>

        <div class="field">
            <label for="snmp_community">Community-String</label>
            <input type="text" id="snmp_community" name="snmp_community" maxlength="64"
                   value="<?= Html::e((string) $values['snmp_community']) ?>"
                   placeholder="public"
                   autocomplete="off"
                   <?= isset($errors['snmp_community']) ? 'aria-invalid="true" aria-describedby="snmp_community-error"' : '' ?>>
            <p class="field__hint">Zugriffsschutz des SNMP-Agenten (SNMP v2c). Für den Produktivbetrieb zwingend ändern.</p>
            <?php if (isset($errors['snmp_community'])) { ?>
                <p class="field__error" id="snmp_community-error"><?= Html::e($errors['snmp_community']) ?></p>
            <?php } ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="snmp_sys_location">Standort (sysLocation)</label>
                <input type="text" id="snmp_sys_location" name="snmp_sys_location" maxlength="255"
                       value="<?= Html::e((string) $values['snmp_sys_location']) ?>"
                       placeholder="Intranet">
            </div>

            <div class="field">
                <label for="snmp_sys_contact">Kontakt (sysContact)</label>
                <input type="text" id="snmp_sys_contact" name="snmp_sys_contact" maxlength="255"
                       value="<?= Html::e((string) $values['snmp_sys_contact']) ?>"
                       placeholder="admin@example.internal">
            </div>
        </div>

        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card__title">Abgefragte Dienste</h2>
    <p class="card__hint">Der Agent liefert Statusinformationen über die NET-SNMP-Tabelle
        <code>UCD-SNMP-MIB::extTable</code> (Basis <code>.1.3.6.1.4.1.2021.8.1</code>).</p>

    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Überwachte Dienste</caption>
            <thead>
            <tr>
                <th scope="col">Prüfung</th>
                <th scope="col">extResult (Exit-Code)</th>
                <th scope="col">extOutput (Text)</th>
            </tr>
            </thead>
            <tbody>
            <tr><td>app (Web)</td><td><code>.1.3.6.1.4.1.2021.8.1.100.1</code></td><td><code>.1.3.6.1.4.1.2021.8.1.101.1</code></td></tr>
            <tr><td>db (MySQL)</td><td><code>.1.3.6.1.4.1.2021.8.1.100.2</code></td><td><code>.1.3.6.1.4.1.2021.8.1.101.2</code></td></tr>
            <tr><td>sync (AD-Dauerlauf)</td><td><code>.1.3.6.1.4.1.2021.8.1.100.3</code></td><td><code>.1.3.6.1.4.1.2021.8.1.101.3</code></td></tr>
            <tr><td>sync_workflow (letzter AD-Lauf)</td><td><code>.1.3.6.1.4.1.2021.8.1.100.4</code></td><td><code>.1.3.6.1.4.1.2021.8.1.101.4</code></td></tr>
            <tr><td>phpmyadmin (optional)</td><td><code>.1.3.6.1.4.1.2021.8.1.100.5</code></td><td><code>.1.3.6.1.4.1.2021.8.1.101.5</code></td></tr>
            </tbody>
        </table>
    </div>
</section>
