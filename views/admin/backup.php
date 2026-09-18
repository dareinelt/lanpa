<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;
?>
<section class="card">
    <h2 class="card__title">Sicherung erstellen</h2>
    <p class="card__hint">
        Erstellt eine Archivdatei mit allen Einstellungen und Inhalten dieser Installation:
        Navigation, wichtige Links, Notfallnummern, Mitteilungen, Seitenbeschreibungen, Design,
        Active-Directory-Einstellungen, Benutzerkonten (inkl. Passwort-Hashes), Telefonbuch sowie
        die hochgeladenen Dateien (Logo, Hintergrundbild, Favicons). Zugangsdaten aus der .env-Datei
        sind nicht enthalten.
    </p>

    <form method="post" action="/admin/sicherung/export" class="form">
        <?= Csrf::field() ?>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Sicherung herunterladen</button>
        </div>
    </form>
</section>

<section class="card">
    <h2 class="card__title">Sicherung einspielen</h2>
    <p class="card__hint">
        Spielt eine zuvor erstellte Sicherungsdatei ein. <strong>Alle vorhandenen Einstellungen und
        Inhalte werden dabei ersetzt.</strong> Maschinell erzeugte Daten (Klick-Statistik, Sync- und
        Audit-Protokoll) bleiben unberührt. Die .env-Datei wird weder gelesen noch verändert.
    </p>

    <form method="post" action="/admin/sicherung/import" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="archive">Sicherungsdatei auswählen (ZIP)</label>
            <input type="file" id="archive" name="archive" accept=".zip,application/zip" required>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--danger" data-confirm="Soll die Sicherung eingespielt werden? Alle vorhandenen Einstellungen und Inhalte werden ersetzt.">
                Sicherung einspielen
            </button>
        </div>
    </form>
</section>
