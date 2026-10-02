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
   Migration 028 ergänzt die Einsatzkoordination des KAEP-Dashboards; vorhandene
   Ereignisse erhalten zunächst eine leere Koordination, ihr Plan bleibt unverändert.
3. Bei Docker den neuen Dienst starten:
   `docker compose up -d --build app auth mail`.
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
| Administrator | Alle bisherigen Adminbereiche sowie Notfallpläne, Export/Import von Notfallplänen und SMTP |
| KAEP-Team | Ausschließlich Notfallplan-Verwaltung, Freigabeeinstellungen, Einsatzübersicht und historische Auswertung; kein Export/Import von Notfallplänen, kein SMTP-, AD-, Benutzer- oder anderer Adminbereich |
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

### Optionale Live-Vorschau

**Live-Vorschau in neuem Tab** öffnet den aktuellen, auch ungespeicherten Entwurf
in der Nutzeransicht. Den Tab bei Bedarf auf einen zweiten Monitor ziehen.
Der Editor bleibt geöffnet; er wird weder neu geladen noch automatisch gespeichert.
Auch neue Pläne und noch leere Bausteine lassen sich vor dem ersten Speichern ansehen.

Mit **Ablauf jetzt simulieren** lassen sich Entscheidungen, UND-/ODER-Verbindungen,
Checklisten, Status, Kommentare, SMS-Bestätigung und Abschluss durchspielen.
Die Vorschau verwendet dieselben Ansichten und Zustandsregeln wie echte Ereignisse.
Sie prüft keine AD-Kennwörter und erzeugt **keine Ereignisse, Veröffentlichungen,
Protokolle in der Datenbank, E-Mails oder SMS**. SMS-Erfolg ist ausdrücklich simuliert,
kein Test des Gateways. Informationslinks öffnen wie im Einsatz externe Inhalte in
einem weiteren Tab; Aktionen auf diesen externen Seiten sind nicht Teil der Sandbox.

Textänderungen werden live übernommen und behalten den Simulationsfortschritt.
Änderungen an Bausteinen, Reihenfolge, Vorgängern, Verknüpfungen, Prüfpunkten oder
SMS-Vorlagen setzen ausschließlich die Simulation zurück. **Simulation zurücksetzen**
führt jederzeit zur Planansicht zurück; nach 200 simulierten Aktionen ist ein Reset nötig.
Ungültige Zwischenstände (z. B. ein noch unvollständiger Informationslink) und
Verbindungsfehler werden angezeigt; gegebenenfalls bleibt der letzte gültige Stand sichtbar.
Der Entwurf im Editor bleibt dabei erhalten.

Technisch verwendet die Vorschau einen zufällig getrennten `BroadcastChannel` je
Editor und einen rollen- und CSRF-geschützten, zustandslosen Render-Endpunkt.
Entwurf und Simulationshistorie liegen nur im Arbeitsspeicher der Tabs, nicht in
Local Storage oder einer Vorschau-Datenbank. Der Server rekonstruiert die Simulation
mit der nebenwirkungsfreien `EmergencyPlanRuntime`; produktive Speicherung und
Versand verbleiben in `EmergencyPlanService`. Ein weiterer Container ist nicht nötig.
Ein aktueller Browser mit `BroadcastChannel` im selben Browserprofil wird benötigt.
Neuladen der Vorschau übernimmt erneut den aktuellen Editorstand und beginnt eine
neue Simulation. Wird der Editor geschlossen, zeigt die Vorschau eine
Verbindungswarnung. Sie ersetzt keine Entwurfssicherung: vor dem Verlassen des
Editors weiterhin **Entwurf speichern** verwenden.

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
Die klassische Ereignisansicht prüft alle zehn Sekunden auf Änderungen, lädt aber nicht über
ungespeicherte Eingaben hinweg neu. Verbindungsfehler werden sichtbar angezeigt.
Historische Ereignisse lassen sich nach Zeitraum/Status filtern; Details zeigen
Dauer, Maßnahmenstände und Zeitverlauf. CSV-Export schützt gegen
Tabellenformel-Injektion. Abschluss mit offenen Maßnahmen benötigt eine Begründung.
Abgeschlossene Ereignisse sind schreibgeschützt; ausstehende technische
Versandergebnisse können weiterhin ins Protokoll einlaufen.

## KAEP-Dashboard: gemeinsames Lagebild

Der Link **KAEP-Dashboard** steht für berechtigte KAEP-Mitglieder und Administratoren
im Fuß neben **Administration** und öffnet einen neuen Tab. Er ist außerdem in der
KAEP-Einsatzübersicht und bei einzelnen Ereignissen erreichbar. Echte erkannte
Windows-Anmeldungen mit entsprechender Gruppenrolle benötigen keinen zusätzlichen
Adminlogin. Auf Tablets ohne Windows-SSO kann ein persönliches lokales KAEP-Konto
über **Administration** angemeldet werden. Die Freigabegruppe zum *Auslösen* eines
Plans allein berechtigt nicht zum Dashboard. Simuliertes SSO erhält keinen Zugriff.

Das Dashboard zeigt laufende und abgeschlossene Ereignisse, einen mehrtägigen
Einsatztimer, Fortschritt, überfällige und blockierte Maßnahmen sowie Alarm- und
E-Mail-Ergebnisse. Die Maßnahmen sind nach Status angeordnet, mit Such- und
Dringlichkeitsfiltern. Voraussetzungen und entfallene Entscheidungszweige bleiben
sichtbar. Durch Antippen lassen sich Details, Checklisten, Entscheidungen und
separat bestätigte SMS bedienen. Alle Statusregeln entsprechen der klassischen
Ereignisansicht.

Zusätzliche Koordination pro Einsatz:

- **Zuständigkeit, Priorität und Zielzeit** je Maßnahme mit optionaler Begründung.
  Freie Personen-/Teamnamen erlauben auch externe Kräfte; dies vergibt keine
  Systemberechtigungen und verschickt keine zusätzliche Alarmierung.
  Leere Zuständigkeit/Zielzeit verwendet wieder die Planvorgabe.
- **Lageübersicht** mit nächster Lagebesprechung; **Einsatz- und Bereichsleitungen**
  im einklappbaren Menü, einschließlich Erreichbarkeit und optional **geplanter
  Ablösung** (Zeitpunkt der nächsten Schichtübergabe). Derselbe Bereich wird
  aktualisiert; leere Person entfernt die Besetzung. Maximal 30 Bereiche.
- **Zeitplan und Wiedervorlagen**: ein Abschnitt bündelt chronologisch die nächste
  Lagebesprechung, geplante Ablösungen, Zielzeiten offener Maßnahmen und freie
  Wiedervorlagen („Blutbank in 6 Std. zurückrufen“, „Dienstplan für morgen prüfen“),
  wahlweise einer Maßnahme zugeordnet. Überfällige Punkte werden hervorgehoben.
  Wiedervorlagen werden als erledigt markiert oder entfernt; beides steht im
  Journal. Höchstens 50 offene Wiedervorlagen je Ereignis. Es gibt **keine**
  automatische Alarmierung bei Fälligkeit; der Abschnitt ist eine Sichtkontrolle
  für die Leitung, kein Erinnerungsdienst.
- **Notizen und Schichtübergaben** im gemeinsamen, unveränderlichen Einsatzjournal,
  wahlweise einer Maßnahme zugeordnet. Neue Lageübersichten und Änderungen von
  Zuständigkeit/Leitung bleiben dort mit Person und Zeitpunkt nachvollziehbar.
  Das Journal zeigt jeweils höchstens 100 Einträge; ältere Einträge sind ohne
  zeitliche Begrenzung seitenweise lesbar. Historische Ausschnitte bleiben beim
  Lesen stehen; **Zurück zum Live-Journal** zeigt wieder die neuesten Einträge.
  Die Maßnahmenlage bleibt auch beim Lesen der Historie live. Die Elementsuche
  erfolgt serverseitig, die Textsuche durchsucht den angezeigten Ausschnitt.
  Einträge sind nach Tagen gruppiert und lassen sich nach Art (Übergabe, Notiz,
  Lage, Leitung, Zuständigkeit, Wiedervorlage, Status, SMS, System) filtern.

Angezeigte Zeitpunkte verwenden die Gerätezeitzone und enthalten das Datum.
Auch Eingaben für Zielzeiten, Lagebesprechungen, Ablösungen und Wiedervorlagen
erfolgen in der **Gerätezeitzone**; die verwendete Zone wird im Dialog und im
Journalkopf angezeigt. Gespeichert und protokolliert wird in UTC, Geräte in
anderen Zeitzonen sehen denselben Zeitpunkt in ihrer Ortszeit. Die Gerätezeit
der Tablets und TVs muss daher korrekt eingestellt sein.
Der Browsertab zeigt im Titel die Zahl überfälliger und blockierter Maßnahmen,
damit das Ereignis auch in Hintergrundtabs auffällt. Eine unbekannte Ereignis-ID
in der URL fällt auf das neueste Ereignis zurück.
Der Timer richtet sich nach der Serverzeit. **TV-Ansicht** blendet Navigation und
Bearbeitungsleisten aus; der Modus und das ausgewählte Ereignis stehen in der URL
(`?id=123&tv=1`). Ein TV benötigt weiterhin ein berechtigtes Konto. **Drucken**
druckt das sichtbare Lagebild und die geladenen Journaleinträge; das vollständige
Protokoll bleibt über den bisherigen CSV-Export der Ereignisansicht verfügbar.

### Anordnung je Client

**Anordnung bearbeiten** blendet am Rand jedes Abschnitts einen Ziehgriff,
Pfeile nach oben/unten und eine Pinnadel ein. Auch die Kennzahlen sind ein eigener
Abschnitt. Am Griff lässt sich ein Abschnitt mit Maus oder Touch verschieben;
am Bildschirmrand scrollt die Seite beim Ziehen mit. Alternativ die Pfeilbuttons
oder auf dem fokussierten Griff die Pfeiltasten verwenden. Escape bricht Ziehen ab.

Die **Pinnadel** verschiebt einen Abschnitt sofort an den Anfang. Angeheftete
Abschnitte bleiben vor allen anderen und lassen sich innerhalb dieser Gruppe
sortieren. Das ist eine feste Position in der Reihenfolge, kein überlagerndes
„Sticky“-Fenster beim Scrollen. Erneutes Anklicken löst die Fixierung.
**Standardanordnung** setzt Reihenfolge und Pinnadeln nach Bestätigung zurück.

Die Anordnung wird ausschließlich im `sessionStorage` **dieses Browser-Tabs**
gespeichert: unabhängig von Konto, PC und anderen Clients, sogar von weiteren
Tabs desselben Browserprofils. Neuladen und Wechsel des Ereignisses erhalten sie;
ein neu geöffneter Tab beginnt mit dem Standardlayout. Es werden keine
Layoutänderungen an andere Geräte übertragen. Einsatzdaten bleiben unverändert
geräteübergreifend live. Bei gesperrtem Browserspeicher erscheint ein Hinweis;
die Anordnung funktioniert dann nur bis zum Neuladen. Auch die TV-Ansicht lässt
sich so individuell einrichten.

### Live-Betrieb und Konflikte

Das Dashboard nutzt **Server-Sent Events (SSE)** statt Seitenneuladungen.
Eine offene Verbindung meldet neue Daten an alle verbundenen Dashboards,
einschließlich Änderungen aus der klassischen Ereignisansicht und Ergebnissen
des Mailworkers. Der Server prüft die gemeinsamen Datenbankrevisionen alle
200 Millisekunden; der Browser lädt nur bei Änderung einen konsistenten Stand.
Unter normaler Last erfolgt die Anzeige damit typischerweise innerhalb einer
Sekunde, zuzüglich Netzwerk- und Renderzeit. Eine physikalisch verzögerungsfreie
oder bei Netzausfall garantierte Zustellung ist nicht möglich.

Ein Heartbeat kommt alle fünf Sekunden. Verbindungsfehler werden sofort, fehlende
Heartbeats spätestens nach zwölf Sekunden sichtbar markiert; Speichern ist dann
gesperrt. Wiederverbindung und Abgleich erfolgen automatisch. Jede Verbindung
endet nach 25 Sekunden und prüft beim Neuaufbau Anmeldung und Gruppenrechte erneut.
Änderungen der AD-Mitgliedschaft setzen weiterhin einen erfolgreichen AD-Abgleich
voraus. Das Dashboard ist keine offlinefähige Schreibanwendung.

Offene Eingabedialoge werden nicht durch Live-Daten ersetzt. Bei Änderungen
erscheint ein Hinweis mit aktuellem Stand. Ein veralteter Schreibversuch wird
serverseitig abgewiesen; erst nach bewusstem Vergleich kann die neue Revision
übernommen werden. Textentwürfe bleiben erhalten; Status, Entscheidung und
Prüfpunkte werden beim Übernehmen auf den aktuellen Stand gesetzt, damit keine
fremden Bestätigungen versehentlich zurückgenommen werden. Unklare Ergebnisse
zuerst im Journal prüfen, niemals blind erneut alarmieren.

SSE benötigt ungepufferte Antworten. Die Anwendung löst die PHP-Sitzungssperre
vor dem Stream, sendet `no-store, no-transform` und `X-Accel-Buffering: no`;
Kompression ist für `/kaep-dashboard/live` in den mitgelieferten Apache-Regeln
ausgenommen. Zusätzliche Reverse-Proxys dürfen diesen Pfad nicht puffern oder
zwischenspeichern und benötigen mindestens 30 Sekunden Lesetimeout.
Nach Änderungen an der Proxy-Konfiguration auch den Auth-Container neu bauen.
Jeder offene Dashboard-Tab belegt im PHP/Apache-Betrieb einen Worker und eine
Datenbankverbindung. Worker-/Verbindungslimits mit Reserve für Schreib- und
normale Webanfragen dimensionieren; vor Einsatz die erwartete Zahl gleichzeitiger
Tablets und TVs über die tatsächliche Proxy-Kette prüfen. Der PHP-Einprozess-
Entwicklungsserver ist für gleichzeitige SSE-Verbindungen nicht geeignet.

Die klassische Ereignisansicht zeigt die Dashboard-Koordination schreibgeschützt
(Lage, nächste Besprechung, Leitungen, offene Wiedervorlagen) sowie je Maßnahme
die abweichende Zuständigkeit, Priorität und Zielzeit mit Datum; Bearbeitung
erfolgt nur im Dashboard. Abgeschlossene Ereignisse zeigen ihre Dauer in Tagen
und Stunden.

Koordination und Journal liegen in der Datenbank (`emergency_events.coordination`,
`emergency_log`). Für mehrtägige Einsätze vollständige Datenbanksicherungen verwenden;
die allgemeine Anwendungsexportdatei enthält diese Einsatzdaten nicht.

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

## Export und Import von Notfallplänen

Notfallpläne lassen sich als Datei exportieren und auf einem anderen System
(z. B. Test → Produktion oder an einem weiteren Standort) wieder importieren.
Beides ist **nur Administratoren** erlaubt; das KAEP-Team erhält HTTP 403.

- **Export:** Unter **Notfallplan / KAEP → Export und Import** die gewünschten
  Pläne auswählen und **Ausgewählte Pläne exportieren** wählen. Es entsteht
  `notfallplaene-JJJJ-MM-TT.json` (Format `lanpa-notfallplaene`, Version 1) mit dem
  jeweils aktuellen Entwurf jedes Plans. Freigabehistorie, Ereignisse, Protokolle
  und E-Mail-Queue sind nicht enthalten. Die Datei enthält Zuständigkeiten,
  Telefonnummern und SMS-Daten: vertraulich behandeln.
- **Import:** Auf dem Zielsystem die Datei (höchstens 2 MB, bis zu 100 Pläne)
  hochladen. Alle Pläne werden vollständig geprüft wie beim Speichern im Editor
  und in einer Transaktion als **neue, unveröffentlichte Entwürfe** angelegt –
  alles oder nichts. Vorhandene Pläne werden nie überschrieben.
- **SMS-Elemente:** Alarmierungs-IDs unterscheiden sich zwischen Systemen. Beim
  Import wird jedes SMS-Element über den Titel (ohne Groß-/Kleinschreibung) einer
  aktiven Alarmierung des Zielsystems zugeordnet; bei mehreren gleichnamigen
  entscheiden Alarmtext und Zielrufnummer. Fehlt eine Vorlage oder ist sie nicht
  eindeutig, wird nichts importiert und die fehlenden Titel werden genannt.
  SMS-Text und Empfänger stammen danach aus der Vorlage des Zielsystems.
- **Vier-Augen-Prinzip:** Die importierende Person gilt als Autor des Entwurfs
  (Freigabeprotokoll: „Importiert“) und darf ihn nicht selbst freigeben. Vor der
  Veröffentlichung prüft eine zweite, unbeteiligte Person insbesondere die
  SMS-Ziele des Zielsystems.

## Sicherung, Datenschutz und Übungen

Pläne, Ereignisse, Protokoll und Mail-Queue sind neue Datenbanktabellen.
Die bisherige Anwendungssicherung und der Notfallplan-Export sind **keine vollständige
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
