<?php
declare(strict_types=1);
use App\Support\Html;
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<article class="ep-guide">
    <div class="toolbar"><a class="button button--ghost" href="<?= $base ?>">Zurück zum Notfallplan</a><button type="button" class="button button--ghost" data-ep-print-guide>Anleitung drucken</button></div>
    <h1>Notfallplan – Kurzanleitung für den Einsatz</h1>
    <p class="ep-warning"><strong>Menschen schützen. Örtlichen Notruf und Meldeweg nutzen. Erst dann hier dokumentieren.</strong> Die Anwendung ersetzt weder Rettungsdienst noch Einsatzleitung. Bei Ausfall: freigegebenen Papierplan, Telefon und Ersatzdokumentation verwenden.</p>
    <p><strong>Alle Abbildungen sind Beispielgrafiken mit Demo-Daten.</strong> Namen, Kontakte, Abläufe und Zeiten sind keine verbindlichen Einsatzvorgaben.</p>

    <h2>1. Notfallplan auslösen</h2>
    <ol>
        <li>Mit dem eigenen Windows-/AD-Konto anmelden. In der Mitte der Kopfleiste <strong>Notfallplan</strong> öffnen.</li>
        <li>Passenden Plan wählen und Hinweise prüfen. Ein geöffnetes Diagramm bedeutet noch <strong>keine Auslösung</strong>.</li>
        <li>Das angezeigte Konto kontrollieren. Über HTTPS das <strong>eigene AD-Kennwort</strong> eingeben und „Jetzt Notfallereignis auslösen“ wählen. Kein fremdes Konto und kein lokales Adminpasswort verwenden.</li>
        <li>Auf die Bestätigung mit <strong>Ereignisnummer</strong> warten. Bleibt sie aus: unter „Meine Ereignisse“ prüfen, ob der Start bereits erfolgt ist. Nicht ungeprüft ein zweites Ereignis anlegen.</li>
    </ol>
    <p>Das KAEP-Team wird automatisch per E-Mail benachrichtigt. E-Mail kann verzögert sein oder ausfallen. Im Ereignis den Versandstatus prüfen; bei fehlenden Empfängern oder Fehlern das Team zusätzlich über den örtlichen Meldeweg informieren. SMS werden erst nach separater Bestätigung versendet.</p>
    <figure><img src="/manuals/notfallplan/auswahl.png" alt="Demo: Roter Notfallplan-Button und Auswahl veröffentlichter Pläne" loading="lazy"><figcaption>Beispielgrafik mit Demo-Daten: Einstieg und Planauswahl.</figcaption></figure>

    <h2>2. Maßnahmen bearbeiten</h2>
    <ol>
        <li>Im Diagramm die passende Maßnahme anklicken. Die zugehörige Karte enthält Anweisung, Zuständigkeit, Telefonnummer, Zielzeit und Vorgänger.</li>
        <li>Bei Beginn <strong>In Arbeit</strong> wählen. Hindernisse als <strong>Blockiert</strong> markieren und beschreiben. Immer „Status und Kommentar speichern“ drücken.</li>
        <li>Rückmeldungen konkret notieren, z. B. „GF um 14:05 erreicht“ oder „Einbahnstraßenprinzip beauftragt“. „Nur Kommentar ergänzen“ verändert den Status nicht.</li>
        <li>Nach Durchführung <strong>Erledigt</strong> setzen. Bei Checklisten alle Prüfpunkte bestätigen. Entscheidungen mit <strong>Ja oder Nein</strong> beantworten und als erledigt speichern.</li>
    </ol>
    <p>Erst erledigte Vorgänger geben Folgemaßnahmen frei. Nicht zutreffende Ja/Nein-Zweige werden als „Entfällt“ angezeigt. Erledigte Maßnahmen und Entscheidungen lassen sich nicht zurücksetzen; Korrekturen nachvollziehbar kommentieren und mit der Einsatzleitung abstimmen.</p>
    <p>Zielzeiten laufen ab Ereignisstart, nicht ab Freigabe einer Maßnahme. Eine Überschreitung ist ein Hinweis, keine automatische Alarmierung. Alle protokollierten Zeitangaben sind <strong>UTC</strong> (Deutschland: im Winter +1, im Sommer +2 Stunden).</p>
    <figure><img src="/manuals/notfallplan/ereignis.png" alt="Demo: Einsatzübersicht mit erledigten, offenen und laufenden Maßnahmen" loading="lazy"><figcaption>Beispielgrafik mit Demo-Daten: Maßnahmenstand im laufenden Ereignis.</figcaption></figure>

    <h2>3. SMS gezielt auslösen</h2>
    <ol>
        <li>Empfänger und vollständigen Nachrichtentext in der SMS-Karte prüfen.</li>
        <li>„SMS jetzt separat bestätigen und senden“ wählen und den Bestätigungsdialog bestätigen.</li>
        <li>Ergebnis abwarten. <strong>Gateway-Annahme ist keine Zustell- oder Lesebestätigung.</strong> Rückmeldung der Empfänger separat kontrollieren.</li>
    </ol>
    <p class="ep-warning">Bei „Versand begonnen“, unklarem Ergebnis oder Fehler nicht nochmals auslösen. Gateway bzw. Alarmierungsverantwortliche kontaktieren und Ersatzmeldeweg nutzen. Die Anwendung erlaubt pro SMS-Maßnahme und Ereignis nur einen Versandversuch. Ersatzalarmierung als Kommentar dokumentieren.</p>

    <h2>4. Lage aktuell halten und abschließen</h2>
    <p>Die Anwendung prüft den Stand alle zehn Sekunden. Ohne ungespeicherte Eingaben wird ein neuer Stand automatisch geladen. Sonst erscheint „Aktuellen Stand laden“. Ungespeicherte Texte vorher sichern; Aktualisierung kann sie verwerfen. Bei fehlender Verbindung ist der sichtbare Stand möglicherweise veraltet.</p>
    <p>Bei einem Änderungskonflikt wurde die Eingabe nicht übernommen: Text sichern, aktuellen Stand laden, Maßnahme prüfen und erneut speichern. Bei unklarem technischem Fehler zuerst das Protokoll prüfen. Nicht blind wiederholen.</p>
    <p>Nach Freigabe durch die Einsatzleitung „Ereignis abschließen“ öffnen. Bei offenen Maßnahmen oder ungeklärtem SMS-Versand ist eine Begründung erforderlich. Ein Abschluss ist unwiderruflich; danach bleibt das Ereignis nur lesbar.</p>

    <h2>5. KAEP-Team: Pläne vorbereiten</h2>
    <ol>
        <li>Administration öffnen und per Windows-Anmeldung oder zugeteiltem lokalen KAEP-Konto anmelden. Das KAEP-Team sieht nur <strong>Notfallplan / KAEP</strong>.</li>
        <li>Neuen Plan entwerfen oder bestehenden bearbeiten. Beispiel Brandfall/MANV nur als Ausgangspunkt verwenden.</li>
        <li>Bausteine hinzufügen: Maßnahme, Kontakt, Entscheidung, Checkliste, Hinweis oder SMS. Titel kurz und eindeutig; Zuständigkeiten und konkrete Arbeitsanweisungen ergänzen.</li>
        <li>Vorgänger auswählen. Mehrere Startpunkte ermöglichen paralleles Arbeiten. „Alle“ verbindet Pflichten; „Mindestens einer“ führt alternative Zweige zusammen. Ja/Nein nur nach Entscheidungen. Das Layout entsteht automatisch.</li>
        <li>SMS-Vorlage aus der vorhandenen Alarmierung auswählen. Fehlende Vorlagen muss ein Administrator anlegen. Empfänger und Nachricht werden beim Speichern in den Plan kopiert.</li>
        <li>„Entwurf speichern“, gespeicherten Entwurf öffnen und „Freigabe anfordern“. Speichern allein veröffentlicht niemals.</li>
        <li>Eine zweite, unbeteiligte Person prüft und wählt „Geprüften Entwurf freigeben und veröffentlichen“. Das gilt auch für Administratoren. Mitautoren und Antragsteller dürfen nicht selbst freigeben.</li>
        <li>Bei Mängeln „Freigabe verweigern“ wählen und zwingend einen Prüfkommentar eingeben. Autor liest den Kommentar im Freigabeprotokoll, korrigiert, speichert und reicht erneut ein.</li>
    </ol>
    <p>Änderungen wirken nur auf künftig gestartete Ereignisse. Laufende und historische Ereignisse behalten ihre ursprüngliche Planversion. Beim Speichern einer bestehenden SMS-Maßnahme wird die aktuelle Alarmvorlage übernommen – deshalb erneut prüfen.</p>
    <p><strong>Optional: Live-Vorschau in neuem Tab.</strong> Den Tab auf einen zweiten Monitor ziehen und „Ablauf jetzt simulieren“ wählen. Ungespeicherte Editoränderungen erscheinen live, ohne Neuladen des Editors. Entscheidungen, Checklisten, Kommentare und SMS werden nur simuliert – keine echten Ereignisse, E-Mails oder SMS und keine Kennwortprüfung. Textänderungen behalten den Testfortschritt; geänderte Bausteine, Verbindungen, Prüfpunkte oder SMS-Vorlagen setzen ihn zurück. „Simulation zurücksetzen“ beginnt bei der Planansicht. Die Vorschau ersetzt kein Speichern; bei Verbindungswarnungen den Editor prüfen.</p>
    <figure><img src="/manuals/notfallplan/editor.png" alt="Demo: Planeditor mit Bausteinliste, automatisch gezeichnetem Diagramm und Eingabefeldern" loading="lazy"><figcaption>Beispielgrafik mit Demo-Daten: Bearbeitung ohne Programmierkenntnisse.</figcaption></figure>
    <p>Die bisher veröffentlichte Version bleibt während der Prüfung live. Änderungen nach Antragstellung machen eine neue Freigabeanforderung nötig. „Veröffentlichung zurückziehen“ sperrt sofort; die Wiederveröffentlichung erfordert erneut zwei Personen. Autor(en), Freigeber und Freigabezeit sind im geöffneten Plan und im Ereignis dezent sichtbar.</p>
    <figure><img src="/manuals/notfallplan/freigabe.png" alt="Demo: Vier-Augen-Workflow mit Prüfkommentar und getrennten Freigabeaktionen" loading="lazy"><figcaption>Beispielgrafik mit Demo-Daten: unabhängige Prüfung und Freigabe.</figcaption></figure>

    <h2>6. KAEP-Team: Überwachen und auswerten</h2>
    <p>Die <strong>Checklisten-Auswertung</strong> zeigt jeden Prüfpunkt mit Bestätigungsstatus, Person und Zeitpunkt. Auch Rücknahmen und erneute Bestätigungen bleiben im chronologischen Protokoll und CSV erhalten. Eine Checkliste wird erst vollständig erledigt, wenn alle Punkte bestätigt sind.</p>
    <figure><img src="/manuals/notfallplan/checkliste.png" alt="Demo: Einzelne Checklistenpunkte mit Status, Person und Bestätigungszeit" loading="lazy"><figcaption>Beispielgrafik mit Demo-Daten: Prüfpunkt-Auswertung.</figcaption></figure>
    <p>In der Einsatzübersicht „Laufend“ wählen und das Ereignis öffnen. Das Team und Administratoren sehen alle Ereignisse und dürfen Maßnahmen mitbearbeiten. Auslösende Benutzer sehen nur ihre eigenen Ereignisse, solange ihre AD-Freigabe besteht.</p>
    <p>Für die Nachbereitung nach Datum und „Abgeschlossen“ filtern. Ereignisdauer, erledigte/offene/entfallene Maßnahmen, Entscheidungen, Kommentare, SMS- und E-Mail-Ergebnisse prüfen. „Protokoll als CSV“ liefert den zeitlichen Verlauf zur Auswertung. „Drucken“ erzeugt eine lesbare Einsatzdokumentation.</p>
    <figure><img src="/manuals/notfallplan/historie.png" alt="Demo: Filterbare historische Ereignisübersicht" loading="lazy"><figcaption>Beispielgrafik mit Demo-Daten: Historische Auswertung.</figcaption></figure>

    <h2>7. Wenn etwas nicht funktioniert</h2>
    <table class="table"><thead><tr><th>Problem</th><th>Sofortmaßnahme</th></tr></thead><tbody>
        <tr><td>Button fehlt / Zugriff gesperrt</td><td>Windows-Anmeldung prüfen. KAEP/Admin prüft Aktivierung, Freigabegruppe und letzten AD-Abgleich. Notfall nicht verzögern.</td></tr>
        <tr><td>AD-Kennwort abgelehnt / AD nicht erreichbar</td><td>Kein Ereignis gestartet. Eigenes AD-Kennwort prüfen, sonst Ersatzmeldeweg. Nach fünf Bestätigungsversuchen innerhalb von fünf Minuten greift eine zeitweise Sperre.</td></tr>
        <tr><td>SMS-Ergebnis unklar</td><td>Nicht wiederholt senden. Gateway prüfen, telefonisch alarmieren und dokumentieren.</td></tr>
        <tr><td>Keine KAEP-E-Mail</td><td>Team anderweitig verständigen. Admin prüft SMTP, Mail-Dienst, AD-E-Mail-Adressen und lokale KAEP-Konten.</td></tr>
        <tr><td>Verbindung ausgefallen</td><td>Letzten Stand als veraltet behandeln. Papierplan, Telefon und Ersatzprotokoll verwenden; später dokumentiert nachtragen.</td></tr>
    </tbody></table>
    <p><strong>Vor dem Ernstfall:</strong> Diese Anleitung ausdrucken, örtliche Notrufnummern und Ersatzmeldewege ergänzen und mit freigegebenen Plänen griffbereit hinterlegen. Übungsereignisse deutlich als Übung kennzeichnen; echte SMS/E-Mails nicht versehentlich auslösen.</p>
</article>
