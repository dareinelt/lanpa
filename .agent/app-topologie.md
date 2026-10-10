# Auftrag: Ganzheitliches 2D-/3D-Topologie-Dashboard für lanpa implementieren

## 1. Ziel und Aufgabenstellung

Implementiere im bestehenden Repository ein vollständiges, fachlich und technisch korrektes Topologie-Dashboard für die Gesamtanwendung **lanpa**.

Die vorhandene Topologie des Orvanta-Nachrichtenflusses dient als technische und gestalterische Referenz. Die neue Topologie soll jedoch nicht nur den Nachrichtenfluss darstellen, sondern die gesamte Anwendung mit ihren Modulen, Diensten, Infrastrukturkomponenten, Identitätsquellen, Datenflüssen, Speicherzielen, Sicherungen und Abhängigkeiten abbilden.

**lanpa ist der zentrale Einstiegspunkt und der Hauptknoten der Darstellung.** Von diesem Knoten verzweigt sich die Architektur hierarchisch in die fachlichen Module und technischen Komponenten. Komplexe Module besitzen wiederum eigene, verschachtelbare Teilgraphen.

Die Topologie muss zwei gleichwertige Darstellungsmodi anbieten:

- **2D:** übersichtliche, möglichst gerichtete Architekturansicht mit klaren Ebenen, Gruppen und Verbindungen.
- **3D:** interaktive räumliche Netzansicht nach dem Prinzip der vorhandenen Orvanta-Nachrichtenfluss-Topologie.

Beide Modi müssen dasselbe fachliche Datenmodell, dieselben Zustände und dieselben Abhängigkeiten verwenden. Ein Wechsel zwischen 2D und 3D darf weder Knoten verlieren noch deren fachliche Bedeutung oder Zustand verändern.

Das Ergebnis ist eine zuverlässige Monitoring- und Diagnoseansicht für Betrieb, Administration und Fehleranalyse – keine bloße illustrative Architekturzeichnung.

## 2. Verbindliche Arbeitsgrundlage

Lies vor Änderungen mindestens folgende Dateien und untersuche die dazugehörigen Implementierungen:

1. `agentsindex-3.md` – Projektarchitektur, Konventionen, Container, Dienste, Module und technische Dokumentation.
2. `topologie-referenz.md` – bestehende Topologie des Nachrichtenflusses einschließlich Datenvertrag, Renderer, 2D-/3D-Layout, Interaktion, Aktualisierung, Sicherheitsanforderungen und Tests.
3. `docs/orvanta-nachrichtenfluss.md` – fachliches Modell des Nachrichtenflusses und dessen Datenquellen.
4. `docs/orvanta-referenz.md` – Orvanta-Implementierung, Exchange-Anbindung, Host-Pool, Nachrichtenfluss und Archiv.
5. `docs/mail-proxy.md` und `docs/mail-proxy-referenz.md` – SMTP-/IMAP-Proxy, Routing und Identitätsquellen.
6. `docs/office.md` – Office-Integration mit Nextcloud und Euro-Office.
7. `docs/storage.md`, `docs/storage-stack.md` und `docs/storage-referenz.md` – Speicher-Tiering, Synchronisation, Katalog, Redis, Snapshots und Wiederherstellung.
8. Die Dokumentation und Implementierung des SNMP-Monitorings sowie die vorhandenen Health-, Diagnose-, Backup- und Incident-Funktionen.

Prüfe anschließend die tatsächlichen Dateien und Klassen im Repository. Insbesondere sind `docker-compose.yml` beziehungsweise die tatsächlich verwendeten Compose-Dateien, Dockerfiles, Konfigurationen, PHP-Services, Controller, Repositories, Migrationen, Monitoring-Skripte und vorhandenen Tests zu untersuchen.

Die Dateinamen und Klassen in diesem Auftrag sind Orientierungspunkte. Maßgeblich ist der tatsächlich vorhandene Code.

**Wichtige Regel:** Erfinde keine Komponenten, Verbindungen, Monitoringwerte oder Fähigkeiten. Wo die Dokumentation eine Beziehung nicht eindeutig belegt, untersuche den Code. Bleibt die Beziehung unklar, kennzeichne sie als ungeklärt und dokumentiere den Klärungsbedarf, statt eine technische Abhängigkeit vorzutäuschen.

Die vorhandene Nachrichtenfluss-Topologie bleibt funktional erhalten. Ihre bestehenden Routen, Datenverträge und Tests dürfen nicht unbeabsichtigt verändert oder beschädigt werden.

## 3. Fachliches Topologiemodell

Entwickle ein vollständiges Modell aus stabil identifizierbaren Knoten und gerichteten Kanten.

### 3.1 Zentrale Anwendung

Der Hauptknoten `lanpa` steht im Mittelpunkt. Er repräsentiert die Anwendung als fachlichen und funktionalen Einstiegspunkt, nicht lediglich einen einzelnen Docker-Container.

Unterhalb beziehungsweise neben ihm werden mindestens folgende Bereiche dargestellt, sofern sie in der jeweiligen Installation vorhanden sind:

- Kernanwendung und Webzugriff
- Authentifizierung und Reverse-Proxy
- Identitätsverwaltung und AD-/LDAP-Synchronisation
- Office-Integration
- Orvanta
- Datenbank und persistente Anwendungsdaten
- E-Mail-Versand und E-Mail-Archivierung
- Monitoring und SNMP
- lokale Speicherinfrastruktur und Speicher-Tiering
- Backup, Snapshots und Wiederherstellung
- optionale Zusatzdienste und weitere tatsächlich vorhandene Komponenten

Unterscheide konsequent zwischen der logischen Anwendung, ihren Modulen, den laufenden Prozessen, den Containern, den Netzwerken, den persistenten Speichern und externen Systemen.

Ein Container ist nicht automatisch mit einem fachlichen Modul gleichzusetzen. Mehrere Container können gemeinsam ein Modul bilden; ein Container kann mehrere technische Aufgaben erfüllen.

### 3.2 Reverse-Proxy und Authentifizierung

Stelle den tatsächlichen Zugriffspfad dar. Berücksichtige insbesondere:

- Benutzer beziehungsweise Clients, soweit fachlich erforderlich
- den zentralen Auth-/Reverse-Proxy
- die von ihm tatsächlich weitergeleiteten Anwendungen und Endpunkte
- Windows-Anmeldung, NTLM/SSO und beteiligte Identitätsquellen
- HTTPS-Zertifikat und dessen Gültigkeit
- gegebenenfalls zusätzliche Auth-Instanzen für weitere Domänen
- interne und externe Verbindungen, soweit sie aus der Implementierung hervorgehen

Unterscheide den HTTP-Zugriffspfad von fachlichen Datenflüssen und Authentifizierungsabhängigkeiten.

Das Zertifikat ist beispielsweise eine Abhängigkeit des HTTPS-Endpunkts und nicht zwangsläufig ein eigenständiger Netzwerk-Hop.

Zeige keine nicht vorhandene direkte Verbindung zwischen Benutzern, Identitätsquellen und Modulen.

### 3.3 Office mit Nextcloud und Euro-Office

Bilde den Office-Bereich als verschachtelten Teilgraphen ab. Untersuche insbesondere:

- Nextcloud-Webanwendung
- Euro-Office DocumentServer
- Nextcloud-Datenbank und Redis
- Nextcloud-Cron und KI-Worker
- Office-Backup
- Office-Konfiguration und Integrationsschnittstellen
- gegebenenfalls LLMInt beziehungsweise KI-Endpunkte
- persistente Dateien und Volumes
- Authentifizierung, SSO, vertrauenswürdige Domains und Zertifikate
- Speicher-Tiering und Rückholung von Daten
- tatsächliche Abhängigkeiten und Aufrufrichtungen

Stelle die Verbindungen zwischen Nextcloud und Euro-Office so dar, wie sie implementiert sind. Zeige auch die relevanten internen Abhängigkeiten des DocumentServers und der Nextcloud-Integration, ohne lediglich aus gemeinsamen Compose-Netzen eine fachliche Abhängigkeit abzuleiten.

Der Office-Backup-Container muss mit den von ihm tatsächlich gesicherten Datenquellen, Datenbanken und Speicherzielen verbunden werden. Unterscheide den Backup-Prozess vom Backup-Ziel und vom Status der letzten erfolgreichen Sicherung.

### 3.4 Orvanta als vollständiger Teilgraph

Orvanta ist nicht nur ein einzelner Knoten, sondern ein komplexes Modul mit eigenen Unterkomponenten.

Die vorhandene Nachrichtenfluss-Topologie muss in ihrer fachlichen Aussage erhalten bleiben und als Teil des Gesamtmodells wiedererkennbar sein.

Berücksichtige, soweit tatsächlich implementiert:

- Orvanta-Anwendung und API
- Exchange-Anbindung über EWS
- einzelne Exchange-Hosts beziehungsweise Hosts einer Exchange-DAG
- Lastverteilung, Sitzungsaffinität und Failover
- Identitätsquellen und AD-/LDAP-Zuordnungen
- SMTP-/IMAP-Proxy für Benutzer ohne Exchange-Postfach
- tatsächliche Mailserver und Postfachzuordnungen des Proxy-Pfads
- unterschiedliche Transportwege für Exchange- und Proxy-Benutzer
- Anhang-Zwischenspeicher und Nextcloud-Dateispeicher
- Mail-Archivierungsdienst und dessen Worker
- Archivdaten, Suchindex und Commit-/Recovery-Prozess
- KI-Endpunkt und KI-Textunterstützung
- Erinnerungen und weitere externe oder interne Abhängigkeiten, sofern für den Betrieb relevant
- Speicher-Tiers und Snapshot-Speicher, sofern sie tatsächlich beteiligt sind

Die Exchange-Hosts müssen einzeln erkennbar sein. Das gilt ebenfalls für einzelne Identitätsquellen und konfigurierte Proxy-Mailserver, soweit diese in den Datenquellen vorhanden sind.

Zeige nicht pauschal eine einzige Verbindung von Orvanta zu „Exchange“ oder „Mail“. Stelle stattdessen die realen Verbindungen und Transportwege dar.

Wichtig sind insbesondere folgende Unterschiede:

- Eine Identitätsquelle kann abhängig von der Konfiguration den Exchange- oder Proxy-Pfad verwenden.
- Die Exchange-Host-Auswahl erfolgt über die tatsächlich implementierte Host-Pool-Logik.
- Der SMTP-/IMAP-Proxy ist eine eigenständige Transportkomponente.
- Die Langzeitarchivierung verwendet den implementierten Archivierungs- und Nextcloud-Speicherpfad.
- KI, Anhang-Zwischenspeicher und Speicher-Tiers besitzen jeweils eigene Abhängigkeiten und Betriebszustände.

Nutze das bestehende Nachrichtenfluss-Modell als fachliche Referenz, nicht als Anlass, dessen Daten lediglich optisch in einen übergeordneten Graphen einzubetten.

### 3.5 Speicher, Tiering, Snapshots und Wiederherstellung

Bilde die Speicherarchitektur detailliert und verschachtelbar ab.

Berücksichtige die tatsächlich implementierten Komponenten:

- lokalen Hot-Tier
- einzelne Cold-Tier-Speicherziele
- SMB-/S3-Ziele, sofern konfiguriert
- `storage-sync`
- `storage-sync-catalog`
- `storage-sync-redis`
- Snapshot-Speicher
- Office-Backup
- Nextcloud-Datenbank und Nextcloud-Dateien
- Orvanta-Anhang-Zwischenspeicher
- Langzeitarchiv
- weitere persistente Volumes und Datenbanken
- Wiederherstellungs- und Rückholpfade

Dokumentiere die unterschiedlichen Aufgaben:

- reguläre Speicherung
- Synchronisation beziehungsweise Auslagerung
- Rückholung
- Snapshoterstellung
- Aufbewahrung und Bereinigung
- Backup
- Wiederherstellung

Eine Kopie, ein Snapshot und ein Backup sind unterschiedliche Konzepte und müssen im Datenmodell unterscheidbar sein.

Die Darstellung muss die tatsächlichen Datenflüsse zeigen. Sie darf nicht suggerieren, dass sämtliche Daten automatisch in allen Tiers, im Office-Backup und im Snapshot-Speicher vorhanden sind.

Wenn eine Sicherung einen Datenbank-Dump, Dateidaten und weitere Metadaten benötigt, müssen diese Abhängigkeiten getrennt untersucht und korrekt abgebildet werden.

Zeige, wo es fachlich sinnvoll ist, auch Rückwege für Restore und Recall. Solche Wege sind keine normalen Datenflüsse und müssen als Wiederherstellungsoperationen gekennzeichnet werden.

### 3.6 Weitere Module und Infrastruktur

Untersuche die vollständige Anwendung und ergänze alle relevanten Komponenten, die im Repository tatsächlich vorhanden sind.

Dazu gehören insbesondere:

- MySQL-Datenbank und ihre Abhängigkeiten
- LDAP-/AD-Synchronisation
- Mailversand und Versandwarteschlange
- SMS-Gateway und Alarmierungsfunktionen
- Notfallpläne und KAEP
- SNMP-Agent
- TLS-Zertifikatsverwaltung
- optionale KI-Dienste
- optionales phpMyAdmin
- weitere Compose-Dienste, persistente Volumes und externe Systeme

Auch fachlich wichtige Querschnittsabhängigkeiten wie Datenbankzugriff, Authentifizierung, Zertifikatsabhängigkeit oder Monitoring-Zugriff müssen berücksichtigt werden.

Optionale Dienste sind als optional beziehungsweise nicht aktiviert erkennbar. Nicht vorhandene Komponenten dürfen nicht mit einem erfundenen Fehlerzustand dargestellt werden.

## 4. Verbindliche Semantik des Graphen

Entwickle ein explizites, erweiterbares Modell. Eine mögliche Struktur ist:

**Knoten:**

- stabiler Schlüssel
- Anzeigename und Beschreibung
- Komponententyp
- übergeordneter Knoten beziehungsweise Modul
- Installations- und Aktivierungszustand
- Laufzeitstatus
- Datenherkunft
- Zeitstempel der letzten Messung
- Fakten und Kennzahlen
- gegebenenfalls interne Verwaltungsroute
- optionale Kindknoten
- optional verfügbare Monitoring-Prüfungen

**Kanten:**

- stabile ID
- Quelle und Ziel
- Beziehungstyp
- fachliche Beschreibung
- Richtung
- optionaler Transport oder Protokolltyp
- tatsächlicher beziehungsweise konfigurierter Verbindungsstatus, soweit belegbar
- Zustand und Aktualität der Überwachung
- Begründung für eine Warnung oder Störung
- Kennzeichnung des Belegstatus

Definiere eine nachvollziehbare Menge von Beziehungstypen, beispielsweise:

- HTTP-/Reverse-Proxy-Weiterleitung
- Authentifizierung beziehungsweise Identitätsauflösung
- API-Aufruf
- Datenbankzugriff
- Mailtransport
- Dateizugriff
- Speicherung
- Synchronisation
- Backup
- Snapshot
- Restore/Recall
- Monitoring-Abfrage

Diese Liste ist anhand des tatsächlichen Codes zu vervollständigen.

Eine Kante darf mehrere Eigenschaften besitzen, muss aber eine eindeutige Hauptsemantik haben. Insbesondere dürfen Monitoring-Abfragen nicht mit dem fachlichen Datenfluss verwechselt werden.

### Belegstatus und Unsicherheit

Unterscheide mindestens zwischen:

- technisch nachgewiesener Verbindung
- aus Konfiguration beziehungsweise Implementierung abgeleiteter Abhängigkeit
- fachlich vermuteter, noch nicht verifizierter Beziehung
- nicht überwachten beziehungsweise unbekannten Beziehungen

Die Kennzeichnung muss auch ohne Farbe verständlich sein, beispielsweise durch Symbole, Beschriftungen oder unterschiedliche Linienstile.

Keine Verbindung darf allein deshalb als gesund gelten, weil ihre beiden Endknoten gesund sind.

## 5. Monitoring und Zustandsmodell

Die Topologie soll die vorhandenen Monitoring-Daten nutzen und nicht eigenständig alternative Zustandswahrheiten erfinden.

Untersuche die vorhandenen Health-Services, SNMP-Abfragen, Storage-Statusfunktionen, Office-Diagnosen, Exchange-Host-Health und weiteren Diagnosepfade.

Führe die Messwerte in einem zentralen Topologie-Datenmodell zusammen, ohne die bestehenden Fachservices unnötig zu duplizieren.

Unterscheide mindestens:

- `ok`: nach den verfügbaren Prüfungen gesund
- `warn`: eingeschränkt oder teilweise verfügbar
- `error`: bestätigte Störung
- `off`: bewusst deaktiviert beziehungsweise nicht aktiv
- `unknown`: Zustand nicht zuverlässig ermittelbar
- `stale`: Messwerte veraltet, sofern dieser Zustand nicht sauber durch eine getrennte Aktualitätsinformation modelliert wird

Falls die bestehende Infrastruktur andere Statuswerte nutzt, definiere eine zentrale, getestete Abbildung, ohne die ursprünglichen Statusinformationen zu verlieren.

**Verbindliche Regeln:**

1. Nicht aktivierte optionale Module sind nicht automatisch gestört.
2. Fehlende Messwerte sind nicht gleichbedeutend mit einem gesunden Dienst.
3. Ein erfolgreicher Containerstatus beweist nicht, dass die Anwendung fachlich funktioniert.
4. Eine erreichbare Datenbank beweist nicht, dass ihre Daten fachlich konsistent sind.
5. Ein erreichbarer Backup-Container beweist nicht, dass eine Sicherung erfolgreich oder wiederherstellbar ist.
6. Ein erfolgreicher Snapshot-Lauf und eine erfolgreich getestete Wiederherstellung sind unterschiedliche Aussagen.
7. Ein ausgefallener Teil eines HA-Systems kann einen Warnzustand statt eines Totalausfalls bedeuten, sofern dies dem realen Failover-Verhalten entspricht.
8. Eine abhängige Komponente darf nicht ohne eigene Evidenz als gestört bezeichnet werden. Abgeleitete Auswirkungen müssen als solche gekennzeichnet sein.
9. Ein unbekannter Zustand darf nicht als `ok` dargestellt werden.
10. Für jeden Fehler muss möglichst die primäre Ursache erkennbar sein, ohne abhängige Fehler zu verschleiern.

Verknüpfe die Knoten mit den vorhandenen Diagnose- und Verwaltungsseiten, sofern eine passende interne Route tatsächlich existiert.

Die Topologie bleibt eine lesende Ansicht. Sie darf keine Konfiguration ändern, keine Sicherung auslösen und keine destruktiven Wiederherstellungsaktionen durchführen.

## 6. UI und Visualisierung

Nutze die bestehende Topologie als technische Grundlage für:

- Vollbilddarstellung mit Leitwartencharakter
- konsistente Statusfarben und Symbole
- stabile Knotenidentitäten
- deterministische Anordnung
- interaktive Auswahl und Detailansicht
- Zoom, Verschieben und Einpassen
- Aktualisierung ohne unnötige Positionssprünge
- reduzierte Animationen bei `prefers-reduced-motion`
- responsive Darstellung
- barrierearme Beschriftungen und Tastatursteuerung

### 6.1 2D-Modus

Die 2D-Ansicht soll eine gut lesbare, gerichtete Architekturkarte darstellen.

Ordne die Anwendung in verständliche Ebenen und Bereiche, beispielsweise:

1. Benutzer und externe Systeme
2. Einstieg, Reverse-Proxy und Identität
3. Anwendungs- und Fachmodule
4. Laufzeitdienste und Verarbeitungsprozesse
5. Datenbanken und persistente Speicher
6. Backup, Snapshots und Wiederherstellung

Diese Anordnung ist ein Layoutvorschlag und darf die tatsächliche Architektur nicht verfälschen.

Verwende Gruppen, einklappbare Teilgraphen oder eine Kombination aus Gesamtübersicht und fokussierter Modulansicht, um auch bei vielen Knoten lesbar zu bleiben.

Kantenbeschriftungen müssen sich bei Bedarf einblenden lassen. Überlagerungen, Kreuzungen und unlesbare Beschriftungen sind durch Layout, Bündelung und Filter zu reduzieren.

### 6.2 3D-Modus

Nutze die bestehende 3D-Projektion als Referenz. Erhalte die Möglichkeit, den Graphen zu drehen, zu verschieben und zu zoomen.

Platziere `lanpa` als optisches Zentrum. Ordne Hauptmodule zunächst in stabilen, deterministischen Bereichen an und verteile deren Unterkomponenten in nachvollziehbaren Teilräumen.

Der Graph muss bei großen Knotenzahlen bedienbar bleiben. Vermeide unkontrollierte physikbasierte Neuordnungen und zufällige Positionswechsel nach jeder Aktualisierung.

Die Projektion muss Punkte hinter der Kamera sicher behandeln. Kanten dürfen bei ungültiger Projektion keine Zeichenfehler auslösen.

### 6.3 Gemeinsame Interaktionen

Implementiere mindestens:

- Umschalten zwischen 2D und 3D
- Gesamtansicht und Einpassen
- Zoom und Verschieben
- Auswahl eines Knotens
- Detailtafel mit Status, Fakten, Datenquelle und letzter Aktualisierung
- Anzeige der direkten ein- und ausgehenden Abhängigkeiten
- Hervorheben aller Abhängigkeiten eines gewählten Knotens
- Hervorheben des Pfads zu einer ausgewählten Ressource
- Filter nach Modul, Komponententyp und Status
- Funktion „Nur Probleme“
- Navigation zwischen Problemknoten
- manuelle Aktualisierung
- Anzeige des letzten erfolgreichen Aktualisierungszeitpunkts
- Hinweis auf veraltete oder fehlgeschlagene Datenabfragen
- Anzeige des Aktualisierungsintervalls
- optional ein begrenztes Ereignisprotokoll der letzten Zustandsänderungen

Die bestehende Topologie besitzt bereits ein umfangreiches Interaktionsmodell. Übernimm passende Funktionen und Tastaturkonventionen, soweit sie für die Gesamtansicht sinnvoll sind.

Bei sehr großen Graphen muss die Auswahl eines Moduls eine fokussierte Detailansicht ermöglichen, ohne den Zusammenhang zur Gesamtanwendung zu verlieren.

## 7. Architektur und Implementierung

Bevorzuge eine Lösung, die sich in die vorhandene Architektur integriert und ohne neue externe Laufzeitabhängigkeiten auskommt.

Der dokumentierte Stack besteht aus PHP, PDO/MySQL, Vanilla JavaScript, handgeschriebenem CSS und Docker Compose. Es gibt keinen regulären Composer-/npm-Buildprozess und keine externen Frontend-CDNs.

Behalte diese Vorgaben bei, sofern der aktuelle Repository-Code sie bestätigt.

Untersuche zunächst, ob das Topologiemodell auf vorhandene Services und Datenverträge aufsetzen kann. Extrahiere gegebenenfalls gemeinsame, fachlich passende Bausteine, ohne den Nachrichtenfluss zu beschädigen.

Ergänze die Gesamtansicht als eigenständige Funktion mit klarer Zuständigkeit und sauberem Datenvertrag.

Die Implementierung soll mindestens folgende Verantwortlichkeiten trennen:

1. Erhebung vorhandener Monitoring-Daten
2. Aufbau und Validierung des fachlichen Graphen
3. Ableitung von Status und Auswirkungen
4. JSON-Datenvertrag
5. HTML-Hülle und Berechtigungsprüfung
6. gemeinsamer Renderer für 2D und 3D
7. Layout, Projektion und Interaktion
8. Aktualisierung und Differenzbildung
9. Tests und technische Dokumentation

Ein zentraler Topologie-Service soll den Graphen aus nachvollziehbaren Datenquellen aufbauen. Er darf nicht selbst unkontrolliert beliebige Netzwerkprüfungen ausführen.

Vermeide N+1-Datenbankabfragen, unnötige Container-Aufrufe und aufwendige Live-Prüfungen bei jedem Browser-Refresh.

Die Topologie muss auch dann eine brauchbare Darstellung liefern, wenn optionale Module fehlen oder einzelne Datenquellen nicht erreichbar sind.

### Datenvertrag

Definiere ein stabiles JSON-Schema für den Gesamtgraphen. Mindestens enthalten sein sollten:

- Schema-Version
- Erstellungszeitpunkt
- Gesamtstatus
- Knoten
- Kanten
- Modulgruppen
- Störungen beziehungsweise relevante Einschränkungen
- Datenaktualität
- verfügbare Kennzahlen
- gegebenenfalls Aktualisierungsintervall

Knoten- und Kanten-IDs müssen stabil sein. Labels dürfen geändert werden, ohne dadurch die Identität eines Knotens zu verändern.

Validiere beim Erstellen des Graphen:

- eindeutige IDs
- gültige Endpunkte aller Kanten
- keine unbeabsichtigten Selbstkanten
- gültige Status- und Beziehungstypen
- keine unerreichbaren beziehungsweise verwaisten Knoten ohne erklärten Grund
- nachvollziehbare Eltern-Kind-Beziehungen
- keine Zyklen in Hierarchien, sofern die Hierarchie azyklisch definiert ist

Fachliche Abhängigkeitszyklen können technisch korrekt sein und dürfen nicht pauschal verboten werden.

## 8. Routen, Rechte und Sicherheit

Integriere die Ansicht über den bestehenden Router und die vorgesehenen Admin-Zugriffsprüfungen.

Verwende das vorhandene Vollbildlayout, die vorhandenen Hilfsfunktionen zum HTML-Escaping und zur JSON-Ausgabe sowie die etablierten Mechanismen für CSP und Nonces.

Alle JSON-Endpunkte der Topologie müssen angemessen vor Caching geschützt werden, insbesondere wenn Statusinformationen dynamisch sind.

Berücksichtige:

- keine Offenlegung von Kennwörtern, Tokens, Secrets oder sensiblen Verbindungsdetails
- keine ungeprüften externen Links aus Monitoring-Daten
- ausschließlich sichere interne Verwaltungslinks, sofern vorhanden
- sichere HTML- und JSON-Ausgabe
- keine Inline-Skripte, die gegen die bestehende CSP verstoßen
- keine unnötige Offenlegung interner Hostnamen oder Infrastrukturdetails an unberechtigte Benutzer
- keine schreibenden Aktionen über die Topologie-API
- angemessene Begrenzung von Datenumfang und Aktualisierungsfrequenz

Übernimm die Sicherheits- und Berechtigungskonventionen des vorhandenen Adminbereichs.

## 9. Aktualisierung und Betrieb

Die Gesamtansicht soll ihre Daten aus einem zentralen, dokumentierten JSON-Endpunkt beziehen. Der initiale Zustand kann wie bei der Referenz in die HTML-Seite eingebettet werden.

Verwende für weitere Aktualisierungen denselben Datenvertrag. Setze `Cache-Control: no-store` beziehungsweise eine gleichwertige geeignete Cache-Strategie ein.

Anforderungen:

- konfigurierbares, begrenztes Aktualisierungsintervall
- keine parallelen, unkontrollierten Refresh-Aufrufe
- keine unnötige Aktualisierung im Hintergrund, wenn der Tab verborgen ist
- Wiederaufnahme bei erneut sichtbarem Tab
- manuelle Aktualisierung
- Erkennung fehlgeschlagener oder ungültiger Antworten
- Anzeige der Datenaktualität
- Differenzbildung anhand stabiler IDs
- Positionsstabilität bestehender Knoten
- kontrolliertes Einfügen und Entfernen von Knoten
- deterministische Layouts bei gleichen Daten
- nachvollziehbare Protokollierung relevanter Fehler

Ein fehlgeschlagener Refresh darf nicht unbemerkt alte Messwerte als aktuellen Gesundheitszustand erscheinen lassen.

Trenne den Zeitpunkt der Graph-Erzeugung vom Zeitpunkt der tatsächlichen Messung einzelner Komponenten.

## 10. Tests und Abnahmekriterien

Erweitere den bestehenden dependency-freien Testansatz, soweit möglich. Halte die vorhandenen Tests lauffähig und ergänze gezielte Tests für neue Funktionalität.

### Fachliche Tests

- Alle tatsächlich vorhandenen Module werden entsprechend ihrer Aktivierung erkannt.
- Orvanta besitzt die erwarteten Exchange- und Proxy-Pfade.
- Identitätsquellen werden anhand der tatsächlichen Konfiguration korrekt zugeordnet.
- Nextcloud und Euro-Office besitzen nur die tatsächlich implementierten Abhängigkeiten.
- Office-Backup, Langzeitarchiv, Speicher-Tiering und Snapshots werden korrekt modelliert.
- Datenbank- und Redis-Abhängigkeiten werden nicht mit fachlichen Netzwerkpfaden verwechselt.
- optionale Komponenten erscheinen bei deaktivierter Konfiguration nicht als fehlerhaft.
- unbekannte und veraltete Messwerte werden nicht als gesund dargestellt.
- abhängige Störungen werden korrekt von der primären Ursache unterschieden.
- fehlende Monitoringdaten erzeugen keine erfundenen Zustände.

### Technische Tests

- JSON-Vertrag und Schema-Version
- stabile IDs
- gültige Kantenreferenzen
- konsistente Modulzuordnungen
- Statusaggregation
- fehlerhafte oder unvollständige Datenquellen
- sichere JSON-Einbettung und CSP-Verträglichkeit
- Berechtigungsprüfung der Routen
- Aktualisierung, Fehlerbehandlung und Differenzbildung
- unveränderte Funktionsfähigkeit der bisherigen Nachrichtenfluss-Topologie
- stabile Layouts und sichere 3D-Projektion
- Erreichbarkeit und Korrektheit der internen Verwaltungslinks

### UI-Abnahme

- 2D und 3D zeigen dieselben Knoten, Kanten und Zustände.
- `lanpa` ist als Zentrum klar erkennbar.
- Module lassen sich fokussieren und bei Bedarf verschachtelt aufklappen.
- Direkte Abhängigkeiten und betroffene Komponenten sind schnell nachvollziehbar.
- Filter, Zoom, Auswahl, Detailtafel und Aktualisierung funktionieren.
- Die Darstellung bleibt bei vielen Knoten bedienbar.
- Fehlerzustände sind auch ohne Farbe erkennbar.
- reduzierte Animationen und Tastaturbedienung funktionieren.
- Bei fehlenden optionalen Diensten bleibt die Gesamtansicht nutzbar.

Führe nach der Implementierung mindestens die vorhandene PHP-Syntaxprüfung und `php tests/run.php` aus. Prüfe zusätzlich die relevanten JavaScript-Dateien und die tatsächlich betroffenen Routen. Dokumentiere fehlgeschlagene Tests und Umgebungsgrenzen ausdrücklich.

## 11. Dokumentation

Erstelle eine technische Referenz für das neue Gesamt-Topologie-Dashboard, beispielsweise `docs/lanpa-topologie-referenz.md`.

Sie muss mindestens enthalten:

- Zweck und fachliche Abgrenzung
- Code-Landkarte
- Routen und Berechtigungen
- Datenquellen und Erhebungslogik
- vollständiger JSON-Datenvertrag
- Knoten- und Kantensemantik
- Statusmodell und Aktualitätsregeln
- Modulhierarchie
- Layout- und Projektionsmodell für 2D und 3D
- Aktualisierung und Differenzbildung
- Sicherheits- und CSP-Anforderungen
- Invarianten
- Teststrategie
- Erweiterungsrezepte für neue Komponenten und Abhängigkeiten
- bekannte Einschränkungen und nicht überwachte Beziehungen

Aktualisiere außerdem `agentsindex-3.md`, damit der neue Topologie-Einstieg, die relevanten Dateien und die technische Referenz auffindbar sind.

Dokumentiere ausdrücklich, welche Abhängigkeiten tatsächlich überwacht werden, welche lediglich aus der Konfiguration abgeleitet werden und welche noch nicht verifiziert werden konnten.

## 12. Vorgehensweise

Arbeite in folgenden Schritten:

**Phase A – Bestandsaufnahme**

1. Lies die verbindlichen Referenzen.
2. Ermittle alle laufenden und optionalen Dienste, Module, Datenbanken, Speicherziele und externen Systeme.
3. Rekonstruiere tatsächliche Abhängigkeiten anhand von Code, Konfiguration, Docker-Netzen, Volumes und Dokumentation.
4. Vergleiche das fachliche Modell mit dem bestehenden Nachrichtenfluss.
5. Identifiziere vorhandene Monitoringquellen, Datenlücken und offene Architekturfragen.
6. Erstelle eine nachvollziehbare interne Komponenten- und Abhängigkeitsmatrix.

**Phase B – Datenmodell und Monitoring**

1. Definiere Knoten, Kanten, Beziehungstypen und Statussemantik.
2. Lege die Regeln für optionale, deaktivierte, unbekannte und veraltete Komponenten fest.
3. Implementiere den zentralen Graph-Aufbau.
4. Ergänze gezielte Tests für das fachliche Modell.
5. Stelle sicher, dass jede Kante aus einer dokumentierten Quelle stammt oder ausdrücklich als ungeklärt markiert wird.

**Phase C – Oberfläche**

1. Ergänze Routen, Controller und Template.
2. Implementiere die gemeinsame Graph-Verarbeitung für beide Ansichten.
3. Entwickle die 2D-Ansicht.
4. Integriere die 3D-Ansicht nach dem Referenzprinzip.
5. Ergänze Interaktionen, Filter, Detailansicht und Aktualisierung.
6. Stelle Positionsstabilität, CSP-Konformität und Barrierefreiheit sicher.

**Phase D – Validierung und Abschluss**

1. Führe die fachlichen und technischen Tests aus.
2. Prüfe die Darstellung mit deaktivierten Modulen und simulierten Ausfällen.
3. Verifiziere alle kritischen Abhängigkeiten gegen den tatsächlichen Code.
4. Prüfe die bestehende Nachrichtenfluss-Topologie auf Regressionen.
5. Erstelle die technische Referenz und aktualisiere den Projektindex.
6. Liefere eine Zusammenfassung der Änderungen, Testergebnisse, bekannten Grenzen und noch nicht verifizierten Abhängigkeiten.

Arbeite selbstständig weiter, bis die implementierte Funktion, die Tests und die Dokumentation vollständig sind. Unterbrich die Arbeit nicht nach einem bloßen Konzept oder einer Komponentenliste.

Wenn eine technische Entscheidung ohne zusätzliche Information möglich ist, triff sie anhand der vorhandenen Architektur. Wenn eine fachliche Beziehung nicht eindeutig feststellbar ist, dokumentiere die Unsicherheit und implementiere keine irreführende Verbindung.

## 13. Definition of Done

Der Auftrag ist erst abgeschlossen, wenn:

- eine eigenständige Gesamt-Topologie für lanpa vorhanden ist;
- lanpa als zentraler Hauptknoten erkennbar ist;
- die fachlichen Module und ihre relevanten technischen Unterkomponenten vollständig und verschachtelbar dargestellt werden;
- Orvanta inklusive Exchange-Hosts, Identitätsquellen und IMAP-/SMTP-Proxy korrekt eingebunden ist;
- Nextcloud, Euro-Office, Auth-Proxy, Datenbanken, Office-Backup, Mail-Archiv, lokale Speicher, Cold-Tiers, Snapshot-Speicher und Restore-Pfade gemäß tatsächlicher Implementierung modelliert sind;
- die Kanten fachlich und technisch nachvollziehbar sind;
- nicht überwachte und unbekannte Abhängigkeiten erkennbar bleiben;
- 2D und 3D dasselbe Datenmodell verwenden;
- Status, Datenaktualität, Fehlerursachen und Abhängigkeiten nachvollziehbar dargestellt werden;
- bestehende Funktionalität und Nachrichtenfluss-Topologie erhalten bleiben;
- Tests und Dokumentation aktualisiert sind;
- keine unbelegten Zustände oder Verbindungen als Tatsachen ausgegeben werden.

**Priorität:** fachliche Korrektheit vor visuellen Effekten, nachgewiesene Abhängigkeiten vor bloßer Vollständigkeit, zuverlässige Statussemantik vor scheinbarer Echtzeit und nachvollziehbare Fehlerursachen vor dekorativer Darstellung.
