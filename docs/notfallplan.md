# Notfallpläne und KAEP-Team

## Zweck und Grenzen

Der mittig platzierte rote **Notfallplan**-Button öffnet freigegebene, interaktive
Abläufe. Der Editor benötigt keine Programmierkenntnisse. Er unterstützt Maßnahmen,
Kontakte, Hinweise, Checklisten, Ja/Nein-Entscheidungen und explizit bestätigte
SMS-Alarmierungen. Automatisches Diagrammlayout, parallele Startpunkte,
UND-/ODER-Verbindungen, Zuständigkeiten, Informationslinks und Zielzeiten helfen
bei der Planung. Die Vorlagen Brandfall und MANF sind **keine fachlich freigegebenen
Einsatzpläne**.

Die Anwendung ersetzt weder Notruf, Einsatzleitung noch verbindliche klinische
oder behördliche Vorgaben. AD, Netzwerk, Datenbank, Stromversorgung, SMTP und
SMS-Gateway können ausfallen. Papierpläne und alternative Meldewege sind notwendig.
Es gibt keine garantierte Zustellung, Rufbereitschafts-Eskalation oder
automatische Alarmierung bei Fristüberschreitung.

## Installation und Betrieb

1. Datenbank vollständig sichern; Anwendung aktualisieren.
2. `php scripts/migrate.php` ausführen (Docker: automatisch beim App-Start).
   Migration 025 legt Pläne, Ereignisse, Protokoll, SMS-Reservierungen und
   Bestätigungsversuche an; 026 ergänzt SMTP-Warteschlange und lokale E-Mail-Adressen.
   Migration 027 ergänzt den Vier-Augen-Workflow. Bereits veröffentlichte Pläne
   werden dabei **zurückgezogen**, weil ihnen der zweite Freigabenachweis fehlt.
   Sie müssen vor erneuter Nutzung eingereicht und unabhängig freigegeben werden.
   Historische/laufende Ereignisse bleiben unverändert.
3. Bei Docker den neuen Dienst starten:
   `docker compose up -d --build app mail`.
4. Ohne Docker `php scripts/mail_worker.php` als überwachten Dienst betreiben.
   Alternativ mindestens minütlich `php scripts/mail_worker.php --once` ausführen.
5. HTTPS bereitstellen. Die AD-Kennwortbestätigung benötigt LDAPS oder StartTLS
   **mit Zertifikatsprüfung** in der jeweiligen Identitätsquelle. Der Benutzer
   wird ausschließlich in seiner eigenen Quelle gesucht. Deaktivierte AD-Konten
   werden nicht akzeptiert. Simuliertes SSO darf keine Ereignisse starten.
6. SMTP konfigurieren und den Testversand tatsächlich im Empfängerpostfach prüfen.
7. Berechtigungen und einen freigegebenen Übungsplan prüfen, bevor der Button
   produktiv aktiviert wird.

## Berechtigungen

| Rolle / Voraussetzung | Umfang |
| --- | --- |
| Administrator | Alle bisherigen Adminbereiche sowie Notfallpläne und SMTP |
| KAEP-Team | Ausschließlich Notfallplan-Verwaltung, Freigabeeinstellungen, Einsatzübersicht und historische Auswertung; kein SMTP-, AD-, Benutzer- oder anderer Adminbereich |
| Redaktion | Bestehender Zugriff auf wichtige Links; kein Notfallplan-Adminzugriff |
| Angemeldeter AD-Benutzer in der Freigabegruppe | Veröffentlichte Pläne ansehen und nach AD-Kennwortbestätigung auslösen; eigene Ereignisse bearbeiten |
| Keine Freigabegruppe, deaktivierter Button oder fehlende Mitgliedschaft | Kein Button und kein Zugriff auf Benutzerinhalte, auch nicht per Direktlink |

Unter **Benutzer → KAEP-Team** ordnet ein Administrator eine oder mehrere
AD-Gruppen zu. Deren Mitglieder melden sich per Windows-Anmeldung im Adminbereich
an. Administratorrechte haben bei Mehrfachmitgliedschaft Vorrang.
Alternativ gibt es lokale Konten mit Rolle `kaep`; für diese ist eine E-Mail-Adresse
Pflicht. Lokale Konten berechtigen nicht zum Auslösen: dafür ist immer die eigene
freigegebene AD-Identität erforderlich.

Unter **Notfallplan / KAEP → Freigabe und Sichtbarkeit** aktivieren Admin/KAEP den
Button und wählen **eine** AD-Gruppe wie in den bestehenden Gruppenvorschlägen.
Die Verwaltungsrolle ersetzt diese Freigabe nicht. Eine leere Gruppe sperrt
immer. Deaktivierung oder Entzug der Gruppe sperrt auch den Benutzerzugriff auf
laufende Ereignisse; Admin/KAEP behalten den Verwaltungszugriff.

Gruppenmitgliedschaften entsprechen dem letzten erfolgreichen AD-Abgleich
(einschließlich aufgelöster verschachtelter Mitgliedschaften). Änderungen im AD
werden erst nach Synchronisation wirksam. Gleichnamige Gruppen verhalten sich
wie bei der vorhandenen Rechteverwaltung quellenübergreifend.

## Pläne, Versionen und gleichzeitige Bearbeitung

Ein Plan enthält 1–80 Elemente, eine Checkliste 1–20 Prüfpunkte. Verbindungen dürfen
nur auf vorherige Elemente zeigen; Schleifen, unbekannte Vorgänger, doppelte IDs
und ungültige Zweige werden serverseitig abgewiesen. Mehrere Elemente ohne Vorgänger
werden parallel freigegeben. „Alle Vorgänger“ verlangt alle Voraussetzungen;
„Mindestens einer“ führt alternative Zweige zusammen. Ein nicht gewählter Zweig
entfällt, sobald die Entscheidung feststeht. Zielzeiten gelten ab Ereignisstart.

Entwürfe sind nicht auslösbar. **Entwurf speichern** veröffentlicht niemals.
Ein Speicherkonflikt
überschreibt keine zwischenzeitlichen Änderungen; der Entwurf bleibt im Browser
erhalten und muss nach Abgleich erneut gespeichert werden.

### Verbindliche Vier-Augen-Freigabe

Der Workflow gilt für **alle**, einschließlich Administratoren:

1. Autor speichert den Entwurf und wählt **Freigabe anfordern**.
2. Ein anderes, am aktuellen Entwurf nicht beteiligtes KAEP-Mitglied oder ein
   unbeteiligter Administrator öffnet denselben Plan, prüft Diagramm, Texte,
   Checklisten, Verbindungen und SMS-Ziele.
3. **Geprüften Entwurf freigeben und veröffentlichen** schaltet genau diese
   gespeicherte Revision frei. Alternativ **Freigabe verweigern** wählen;
   dafür ist ein nicht leerer Prüfkommentar zwingend.
4. Bei Ablehnung Kommentar im Freigabeprotokoll lesen, korrigieren, speichern
   und erneut einreichen.

Alle Mitautoren seit der letzten Freigabe sowie die einreichende Person sind
von der Entscheidung über diesen Entwurf ausgeschlossen. Ein Wechsel der
bearbeitenden Person macht vorherige Mitautoren nicht zu unabhängigen Prüfern.
Bei erkannter echter AD-Anmeldung wird auch bei lokalem Adminlogin dieselbe
AD-Person für die Prüfung verwendet. Ohne AD-Zuordnung sind lokale Konten
eigenständige Identitäten: organisatorisch sicherstellen, dass Personen keine
zusätzlichen lokalen Konten zur Selbstfreigabe besitzen. Geteilte Konten sind
unzulässig. Benutzernamen nicht zwischen Personen wiederverwenden.

Speichern nach Antragstellung verwirft den offenen Antrag. Parallel eingehende
Freigaben oder veraltete Revisionen werden abgewiesen. Antrag, Autoren,
Freigabe, Ablehnung samt Kommentar und Rücknahme bleiben im Freigabeprotokoll.
Ein neuer Entwurf ersetzt **nicht** die zuvor freigegebene Live-Version.
Auch eine Ablehnung lässt die bisherige Live-Version unverändert.
**Veröffentlichung zurückziehen** sperrt einen Plan unmittelbar, ohne eine
zweite Person abzuwarten; erneutes Veröffentlichen benötigt wieder zwei Personen.

Autor(en), Freigeber, Freigabezeit und Version werden dezent im geöffneten Plan
und in der Ereignisansicht angezeigt. Diese Angaben sind Teil des veröffentlichten
Snapshots und bleiben bei späteren Änderungen unverändert. Alte Ereignisse ohne
Freigabenachweis sind entsprechend gekennzeichnet.

Beim Start entsteht ein unveränderlicher Snapshot des veröffentlichten Plans
einschließlich SMS-Empfänger und -Text. Bearbeiten oder Zurückziehen des Plans
verändert laufende/historische Ereignisse nicht. Beim erneuten Speichern eines
Plans werden dessen SMS-Daten aus den aktuell ausgewählten Alarmvorlagen kopiert.
Eine zentrale Änderung/Deaktivierung der Vorlage stoppt also **keine bereits
gespeicherten oder gestarteten Notfallpläne**. Bei Rücknahme einer Alarmierung
betroffene Pläne ausdrücklich prüfen und zurückziehen.

## Auslösung, Zusammenarbeit und Protokoll

Das Kennwort wird unverändert (einschließlich Leerzeichen) ausschließlich gegen
das AD-Konto der angemeldeten Identität geprüft. Es wird weder gespeichert noch
geloggt. Die Bestätigung ist an Planversion und Startanforderung gebunden.
Doppelte Übermittlung derselben Anforderung liefert dasselbe Ereignis und plant
keine weiteren E-Mails ein. Pro AD-Identität sind maximal fünf Prüfungen in einem
Fünf-Minuten-Fenster erlaubt, unabhängig von Browser/Sitzung. Auch erfolgreiche
Prüfungen zählen, um missbräuchliche Massenstarts zu begrenzen.

Die auslösende Person und Admin/KAEP dürfen Status ändern, Checklisten abhaken und
Kommentare ergänzen. Andere freigegebene Benutzer sehen keine fremden Ereignisse.
Status: Offen, In Arbeit, Blockiert, Erledigt.
Checklisten speichern für jeden bestätigten Prüfpunkt Person und Zeitpunkt.
Die Checklisten-Auswertung zeigt offene, bestätigte und entfallene Punkte
einzeln. Bestätigungen, Rücknahmen und erneute Bestätigungen werden als eigene
Protokolleinträge exportiert; bereits unverändert bestätigte Punkte behalten
ihren ursprünglichen Bestätigungszeitpunkt. Erst wenn alle Punkte bestätigt sind,
kann die gesamte Checkliste als erledigt markiert werden.

Entscheidungen werden beim Erledigen verbindlich; erledigte Maßnahmen werden nicht wieder geöffnet.
Korrekturen erfolgen als neue Kommentare. Alle Änderungen werden mit Zeitpunkt
(UTC), Person und Maßnahme append-only protokolliert. Dies ist kein
manipulationssicheres externes Auditarchiv; Datenbankadministratoren bleiben
technisch in der Lage, Daten zu verändern.

Transaktionen und Revisionsvergleich verhindern verlorene Änderungen.
Die Anzeige prüft alle zehn Sekunden auf Änderungen, lädt aber nicht über
ungespeicherte Eingaben hinweg neu. Verbindungsfehler werden sichtbar angezeigt.
Historische Ereignisse lassen sich nach Zeitraum/Status filtern; Details zeigen
Dauer, Maßnahmenstände und Zeitverlauf. CSV-Export schützt gegen
Tabellenformel-Injektion. Abschluss mit offenen Maßnahmen benötigt eine Begründung.
Abgeschlossene Ereignisse sind schreibgeschützt; ausstehende technische
Versandergebnisse können weiterhin ins Protokoll einlaufen.

## SMS

Der Editor verwendet vorhandene aktive Alarmkacheln und das bestehende Gateway.
Das KAEP-Team kann weder Gateway-Zugangsdaten ändern noch fremde Adminbereiche
öffnen. Ein Administrator legt fehlende Alarmvorlagen an.

**Kein automatischer SMS-Versand beim Start.** Jede SMS wird im Ereignis mit
Empfänger und Text angezeigt und separat bestätigt. Vor dem Netzwerkaufruf wird
die Maßnahme dauerhaft reserviert, um Doppelklicks und parallele Auslösung
abzuweisen. Pro SMS-Maßnahme/Ereignis ist nur ein Versandversuch vorgesehen.
Fehler oder unklare Ergebnisse erfordern Prüfung am Gateway/Ersatzmeldeweg;
es gibt bewusst keinen automatischen SMS-Neuversand. „Erledigt“ ohne erfolgreiche
Gateway-Annahme erfordert einen Kommentar zur Ersatzalarmierung.

## E-Mail und SMTP

**E-Mail (SMTP)** ist ausschließlich für Administratoren zugänglich:
Aktivierung, Host, Port, STARTTLS/TLS oder internes Relay ohne TLS,
Benutzername, Passwort, Absenderadresse/-name und Timeout sind im Adminbereich
einstellbar. SMTP AUTH LOGIN wird unterstützt; Authentifizierung ohne TLS ist
gesperrt. Es gibt keine OAuth2- oder SMTPUTF8-Unterstützung.
TLS-Zertifikate werden immer geprüft. SMTP-Passwörter sind mit dem vorhandenen
`SecretBox`-Schlüssel verschlüsselt, werden nie zurückgerendert oder in die
Anwendungssicherung exportiert und bei deren Import nicht überschrieben.

Beim Ereignisstart werden E-Mails **in derselben Datenbanktransaktion** eingeplant:
an aktive Mitglieder der KAEP-AD-Gruppen mit gültiger AD-E-Mail sowie aktive lokale
KAEP-Konten mit E-Mail. Mehrfachmitgliedschaften/gleiche Adressen führen nicht zu
mehrfachen Mails. Administratoren erhalten nur dann eine Mail, wenn sie zusätzlich
KAEP-Mitglieder sind. Fehlende/ungültige Adressen werden gezählt und im Ereignis
sichtbar protokolliert; sie dürfen den Notfallstart nicht blockieren.

Die Nachricht enthält Planname, Ereignisnummer, auslösende Identität, Startzeit
und einen zugriffsgeschützten Verwaltungslink. `APP_URL` muss deshalb die korrekte
öffentliche HTTPS-Adresse enthalten. Empfängerlisten stammen vom Zeitpunkt der
Auslösung. Jede Adresse erhält eine eigene Mail, keine offene Sammeladressierung.

Der Docker-Dienst `mail` prüft alle fünf Sekunden und verarbeitet bis zu 20
Nachrichten pro Durchlauf. Netzwerkfehler verhindern die Auslösung nicht.
Maximal drei Versuche; nach Fehlern Wartezeiten von 60/120 Sekunden.
Nach Prozessabbruch werden hängende Versuche nach 15 Minuten erneut eingeplant
(bzw. nach dem dritten Versuch als fehlgeschlagen markiert).
Die Queue bietet begrenzte **At-least-once**-Zustellung: bei verlorener
SMTP-Bestätigung kann eine Mail doppelt eintreffen; dieselbe Message-ID wird
beibehalten. „SMTP-Annahme bestätigt“ ist keine Zustell-/Lesebestätigung.

Die letzten 50 Nachrichten stehen unter SMTP, ereignisbezogene Ergebnisse im
Ereignis. Bleiben Mails auf „queued“, Mail-Dienst und Datenbank prüfen.
Bei „failed“ Team anderweitig benachrichtigen, Ursache beheben und Testmail
versenden. Es erfolgt kein unbegrenzter Wiederholungsversuch.

## Sicherung, Datenschutz und Übungen

Pläne, Ereignisse, Protokoll und Mail-Queue sind neue Datenbanktabellen.
Die bisherige Anwendungssicherung ist ein Konfigurationsexport, **keine vollständige
Notfallplan-/Ereignissicherung**. Für diese Funktion MySQL vollständig und
konsistent sichern, außerdem `storage/keys/secrets.key` sicher verwahren.
Wiederherstellung und AD-/SMTP-Konfiguration regelmäßig testen.
Keine Kennwörter, Gesundheitsdaten oder unnötigen personenbezogenen Informationen
in Maßnahmenkommentaren speichern. CSV/Print nur berechtigt weitergeben.
Es gibt keine automatische Löschung; Aufbewahrung und Löschverfahren organisatorisch
festlegen.

Die [bebilderte Einsatzanleitung](notfallplan-anleitung.md) ist zusätzlich
zugriffsgeschützt innerhalb der Anwendung unter **Notfallplan → Kurzanleitung**
und **Notfallplan / KAEP → Kurzanleitung** verfügbar und druckbar.
Die Abbildungen sind ausdrücklich Beispielgrafiken mit Demo-Daten.
