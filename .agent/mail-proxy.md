Du arbeitest als Senior Software Engineer direkt im bestehenden Repository des Projekts. Implementiere die folgende Funktion vollständig und produktionsreif.

# Aufgabe: SMTP-/IMAP-Proxy für Orvanta ohne Exchange-Postfach

Das bestehende Projekt besitzt die Mail-/Kalender-Anwendung **Orvanta**, die derzeit primär über Exchange/EWS arbeitet. Es soll eine zusätzliche Mail-Infrastruktur für Benutzer geben, die entweder

1. kein Exchange-Postfach besitzen oder
2. zu einer AD-/Identitätsquelle gehören, für die kein Exchange-Server vorhanden ist.

Dafür soll ein **SMTP-/IMAP-Proxy** eingeführt werden.

Der Proxy soll für diese Benutzer gegenüber Orvanta wie ein normales Postfach funktionieren. Orvanta darf dabei möglichst wenig über die konkrete Backend-Infrastruktur wissen: Die Zuordnung, welches externe SMTP-/IMAP-Postfach für welchen AD-Benutzer verwendet wird, soll zentral über die neue Proxy-Konfiguration aufgelöst werden.

## 1. Zuerst Repository und Referenzdokumentation analysieren

Bevor du Code änderst:

* Lies den vorhandenen `agentsindex.md` vollständig bzw. alle für diese Aufgabe relevanten Abschnitte.
* Lies insbesondere:

  * `docs/orvanta.md`
  * `docs/orvanta-referenz.md`
  * `docs/office.md`
  * die bestehende `OrvantaExchangeService`-/Transport-Implementierung
  * `OrvantaConfigService`
  * `IdentitySourceService`
  * `IdentitySourceRepository`
  * `OfficeController`
  * bestehende Office-Views und JavaScript
  * bestehende LDAP-/AD-Gruppenlogik
  * bestehende Credential-/`SecretBox`-Mechanismen
  * vorhandene SMTP-Funktionalität und Mail-Warteschlange
  * bestehende Docker-Compose-Struktur
  * bestehende Tests und Test-Fakes.
* Prüfe außerdem die aktuelle Datenbankstruktur und die letzte Migrationsnummer.
* Suche nach bereits vorhandenen SMTP-, IMAP-, Mailbox-, EWS-, Transport-, Credential- und Proxy-Abstraktionen.

Wichtig:
**Nicht parallel eine zweite Architektur erfinden, wenn bereits geeignete Abstraktionen existieren.**
Erweitere die vorhandenen Interfaces/Services sinnvoll.

Wenn bestehende Dokumentation und diese Aufgabenbeschreibung scheinbar kollidieren, analysiere zuerst den konkreten Code und entscheide anhand der bestehenden Architektur. Dokumentiere relevante Architekturentscheidungen.

---

# 2. Funktionales Zielbild

Im Adminbereich wird unter

**Admin → Office → SMTP-/IMAP-Proxy**

eine neue Konfigurationsseite eingerichtet.

Dort werden Mailserver und Postfächer für verschiedene Identitätsquellen konfiguriert.

Beispiel:

Identitätsquelle:

`mvzintsz.local`

bzw. Distinguished Name:

`DC=mvzintsz,DC=local`

Für diese Identitätsquelle wird ein eigener Mailserver konfiguriert:

* SMTP Host
* SMTP Port
* SMTP Verschlüsselung / TLS-Modus
* SMTP Authentifizierung
* IMAP Host
* IMAP Port
* IMAP TLS-Modus
* ggf. weitere für den verwendeten Proxy notwendigen Parameter
* Aktiv/Inaktiv

Die konkrete Feldstruktur soll sich an den vorhandenen Konfigurationsmustern des Projekts orientieren.

## 2.1 Identitätsquellen als Gruppierung

Die vorhandenen Identitätsquellen sind die fachliche Grundlage.

Für jede Identitätsquelle können eigene SMTP-/IMAP-Proxy-Konfigurationen existieren.

Beispiel:

```text
Identitätsquelle: mvzintsz.local
    SMTP: smtp.mvzintsz.local:587
    IMAP: imap.mvzintsz.local:993

Identitätsquelle: tochterfirma.local
    SMTP: mail.tochterfirma.local:587
    IMAP: mail.tochterfirma.local:993
```

Es darf nicht davon ausgegangen werden, dass alle Identitätsquellen denselben Mailserver benutzen.

Die Zuordnung muss eindeutig über die Identitätsquelle erfolgen.

---

# 3. Postfächer verwalten

Für jede Proxy-/Identitätsquellen-Konfiguration können Postfächer angelegt werden.

Ein Postfach enthält mindestens:

* Benutzername
* E-Mail-Adresse
* Passwort
* Aktiv/Inaktiv

Optional notwendige Felder für die technische Implementierung dürfen ergänzt werden.

Passwörter dürfen **niemals im Klartext gespeichert** werden.

Nutze die bereits vorhandene Verschlüsselungsinfrastruktur des Projekts (`SecretBox` bzw. den etablierten Mechanismus für gespeicherte Credentials).

Passwörter dürfen:

* nicht in Logs erscheinen
* nicht in HTML ausgegeben werden
* nicht über normale JSON-APIs zurückgegeben werden
* nicht in Exporten landen, sofern das bestehende Sicherheitsmodell dies ebenfalls für Zugangsdaten vorsieht.

Beim Bearbeiten gilt:

* leeres Passwortfeld = bestehendes Passwort unverändert lassen
* neues Passwort = verschlüsselt speichern.

---

# 4. Mapping AD-User → Postfach

Für Benutzer einer Identitätsquelle sollen Postfächer zugeordnet werden können.

Die Admin-Oberfläche soll dafür eine **zweispaltige Gegenüberstellung** anzeigen:

```text
AD-Benutzer                         Postfach
-----------------------------------------------------------
Max Mustermann                     max.mustermann@firma.de
Erika Musterfrau                   erika.musterfrau@firma.de
...
```

Links:

**AD-User**

Rechts:

**Postfach**

Beide Seiten müssen eine Autovervollständigung besitzen.

## AD-User-Autovervollständigung

Die Vorschläge sollen aus dem bereits synchronisierten Benutzerbestand kommen.

Dabei sollen die bestehenden Identitätsquellen berücksichtigt werden.

Ein Vorschlag sollte möglichst aussagekräftige Informationen anzeigen, z. B.:

```text
Max Mustermann
max.mustermann
mvzintsz.local
```

Der tatsächlich gespeicherte Schlüssel muss stabil und eindeutig sein.

Nicht lediglich einen frei eingegebenen Anzeigenamen speichern.

## Postfach-Autovervollständigung

Die rechte Seite schlägt nur konfigurierte Postfächer vor.

Dabei sollen insbesondere berücksichtigt werden:

* Postfach gehört zur passenden Identitätsquelle
* Postfach ist aktiv
* Postfach ist nicht bereits unzulässig mehrfach vergeben, sofern die Fachlogik keine Mehrfachzuordnung erlaubt.

Die UX soll sich an bereits vorhandenen Autocomplete-/Vorschlagskomponenten im Projekt orientieren.

Keine externen JavaScript-Frameworks verwenden.

---

# 5. Fachliche Auflösung beim Öffnen von Orvanta

Das ist der wichtigste Teil.

Wenn ein Benutzer Orvanta öffnet, muss festgestellt werden:

1. Welche SSO-/AD-Identität hat der Benutzer?
2. Zu welcher Identitätsquelle gehört der Benutzer?
3. Gibt es für diese Identitätsquelle eine aktive SMTP-/IMAP-Proxy-Konfiguration?
4. Gibt es für diesen Benutzer ein aktives Postfach-Mapping?
5. Falls ja: Verwende dieses Postfach über den SMTP-/IMAP-Proxy.
6. Falls nein: Verwende weiterhin das bestehende Exchange-Verhalten.

Damit muss das bestehende Exchange-Verhalten vollständig erhalten bleiben.

### Beispiel

Benutzer:

```text
MAXMUSTERMANN
Identitätsquelle: mvzintsz.local
```

Mapping:

```text
MAXMUSTERMANN
    ↓
max.mustermann@mvzintsz.local
```

Postfach:

```text
Username: max.mustermann
E-Mail: max.mustermann@mvzintsz.local
Password: ********
```

Mailserver:

```text
SMTP: smtp.mvzintsz.local:587
IMAP: imap.mvzintsz.local:993
```

Wenn Max Orvanta öffnet, darf Orvanta nicht versuchen, ihn gegen den Exchange-Server zu authentifizieren.

Stattdessen muss die Mail-Kommunikation über das konfigurierte Proxy-Backend erfolgen.

---

# 6. Technische Proxy-Architektur

Entwirf die Implementierung so, dass die bestehende Orvanta-Fachlogik nicht mit SMTP-/IMAP-Protokolldetails überladen wird.

Die bestehende Exchange-Anbindung besitzt bereits eine Transport-/Service-Abstraktion.

Nutze bzw. erweitere diese Architektur.

Zielbild sinngemäß:

```text
Orvanta
   │
   ▼
Mailbox/Transport-Abstraktion
   │
   ├── Exchange/EWS Transport
   │
   └── SMTP/IMAP Proxy Transport
            │
            ├── SMTP
            └── IMAP
```

Die konkreten Klassen-/Interface-Namen sind anhand des bestehenden Codes zu wählen.

Bevorzugt:

* klare Interfaces
* Dependency Injection
* getrennte Transportimplementierungen
* keine `if`-Blöcke mit verstreuter Proxylogik in Controllern
* keine direkte DB-Abfrage aus Views oder Controllern.

---

# 7. Wichtiger Punkt: SMTP und IMAP fachlich trennen

SMTP ist für Versand zuständig.

IMAP ist für den Zugriff auf das Postfach zuständig.

Der Proxy muss deshalb beide Richtungen sauber abbilden:

### Lesen

Orvanta:

```text
Benutzer
  ↓
Orvanta
  ↓
IMAP Proxy
  ↓
konfigurierter IMAP-Server
  ↓
Postfach
```

### Senden

Orvanta:

```text
Benutzer
  ↓
Orvanta
  ↓
SMTP Proxy
  ↓
konfigurierter SMTP-Server
  ↓
Empfänger
```

Der Proxy darf die Zugangsdaten des Benutzers nicht einfach ungeprüft an den Backend-Mailserver weitergeben.

Die im Adminbereich hinterlegten Postfach-Credentials sind die technische Identität des Zielpostfachs.

---

# 8. Eigene Python-Container sind ausdrücklich erlaubt

Für Management, Proxying und Caching dürfen zusätzliche Container mit Python-Code eingeführt werden.

Wenn die bestehende PHP-Anwendung für den eigentlichen SMTP-/IMAP-Proxy ungeeignet ist, darf und soll eine separate Python-Komponente verwendet werden.

Bevorzugte Trennung:

```text
PHP-App
   │
   │ Management / Mapping / Konfiguration
   ▼
Proxy-Service
   │
   ├── SMTP
   └── IMAP
```

Mögliche Komponenten:

```text
mail-proxy
mail-proxy-cache
```

Die tatsächlichen Container-Namen sind frei wählbar, sollen aber verständlich und konsistent sein.

## Anforderungen an den Python-Proxy

* keine unnötigen externen Abhängigkeiten
* möglichst schlanke Runtime
* Dockerfile im bestehenden Docker-Stil
* Healthcheck
* strukturierte Logs
* keine Passwörter im Log
* sauberes Fehlerverhalten
* Timeouts
* keine unendlichen Verbindungen
* kontrollierte maximale Verbindungsanzahl
* TLS-Unterstützung
* Zertifikatsprüfung standardmäßig aktiv
* explizite Konfigurationsmöglichkeit nur wenn technisch notwendig.

Falls Python verwendet wird, soll der Agent prüfen, ob bereits vorhandene Python-Container/Abhängigkeiten im Projekt wiederverwendet werden können.

---

# 9. Caching

Für das Proxy-System darf ein Cache verwendet werden.

Der Cache darf niemals dazu führen, dass ein Benutzer nach einer Konfigurationsänderung dauerhaft das falsche Postfach erhält.

Beispielsweise:

```text
SSO User
   ↓
Identity Source
   ↓
Mailbox Mapping
   ↓
Proxy Credentials
```

kann gecacht werden.

Bei Änderungen im Adminbereich muss der Cache invalidiert werden.

Mindestens folgende Ereignisse müssen den relevanten Cache ungültig machen:

* Postfach angelegt
* Postfach gelöscht
* Postfach geändert
* Mapping angelegt
* Mapping geändert
* Mapping gelöscht
* Proxy-Konfiguration geändert
* Proxy-Konfiguration deaktiviert
* Identitätsquelle geändert/deaktiviert.

Der Cache muss außerdem einen sinnvollen TTL-/Fallback-Mechanismus besitzen.

---

# 10. Verhalten bei Fehlern

Die Anwendung darf nicht stillschweigend ein falsches Postfach verwenden.

Beispiele:

### Kein Mapping

→ bestehendes Exchange-Verhalten.

### Mapping vorhanden, Proxy deaktiviert

→ kein Proxy verwenden; Verhalten gemäß bestehender Orvanta-Fallback-Logik.

### Mapping vorhanden, Postfach deaktiviert

→ nicht verbinden.

### Proxy konfiguriert, Mailserver nicht erreichbar

→ aussagekräftiger Fehler in Orvanta.

### Credentials falsch

→ Fehler wird sauber an Orvanta weitergegeben und geloggt, aber Passwort niemals.

### Identitätsquelle nicht mehr vorhanden

→ Mapping wird nicht verwendet.

### Benutzer nicht mehr aktiv

→ Mapping darf nicht verwendet werden.

---

# 11. Sicherheit

Die Funktion verarbeitet hochsensible Mail-Zugangsdaten.

Daher zwingend:

* Passwörter verschlüsselt speichern.
* Keine Passwörter in Logs.
* Keine Passwörter in HTML.
* Keine Passwörter in API-Antworten.
* Keine Passwörter in JavaScript.
* Keine Passwörter in Browser-Autocomplete-Werten.
* Keine Passwörter in Export-/Backup-Dateien.
* CSRF-Schutz bei allen schreibenden Admin-Routen.
* Admin-Rechte für die komplette Proxy-Konfiguration.
* Prepared Statements.
* konsequentes HTML-Escaping.
* TLS-Zertifikate der Backend-Mailserver standardmäßig prüfen.
* Timeouts für Netzwerkverbindungen.
* SSRF-Risiken beachten: Da Administratoren Mailserver konfigurieren können, müssen Host-/URL-/Port-Eingaben trotzdem sauber validiert werden.
* Keine frei kontrollierbaren internen HTTP-Requests aus dem Webfrontend.
* keine Credentials in Exceptions.

Orientiere dich strikt am bestehenden Sicherheitsmodell des Projekts.

---

# 12. Datenmodell

Führe neue Migration(en) ein.

Die genaue Tabellenstruktur soll zur vorhandenen Datenbankkonvention passen.

Sinnvoll ist mindestens eine Struktur vergleichbar mit:

```text
mail_proxy_servers
------------------
id
identity_source_id
name
smtp_host
smtp_port
smtp_security
smtp_authentication
imap_host
imap_port
imap_security
active
created_at
updated_at
```

und:

```text
mail_proxy_mailboxes
--------------------
id
proxy_server_id
username
email_address
password_encrypted
active
created_at
updated_at
```

sowie:

```text
mail_proxy_mappings
-------------------
id
identity_source_id
phonebook_id / user identifier
mailbox_id
created_at
updated_at
```

Das ist nur ein Ausgangspunkt.

**Passe das Datenmodell an die tatsächlich vorhandenen Identitäts-/Benutzerstrukturen an.**

Insbesondere prüfen:

* `phonebook`
* `identity_sources`
* `ad_groups`
* SSO-Kennungen
* eindeutige Benutzeridentifikation.

Keinesfalls einen instabilen Anzeigenamen als Primärreferenz verwenden.

Foreign Keys und sinnvolle Unique Constraints definieren.

---

# 13. Admin-Oberfläche

Erweitere den bestehenden Office-Adminbereich.

Aktuell besitzt Office bereits mehrere Unterseiten und `Admin\OfficeController` sowie eine `PAGES`-Zuordnung.

Integriere die neue Funktion in dieses bestehende Muster.

Neue Seite beispielsweise:

```text
/admin/office/mail-proxy
```

Menü:

```text
Office
 ├── Status & Diagnose
 ├── Fußzeile
 ├── KI
 ├── App-Store
 ├── Orvanta
 ├── SMTP-/IMAP-Proxy
 ├── Kachel
 └── ...
```

Die konkrete Position soll sich am vorhandenen Menü orientieren.

## UI-Aufteilung

Empfohlen:

### Abschnitt 1 – Proxy-Konfiguration

Liste:

```text
Identitätsquelle       SMTP                 IMAP              Status
mvzintsz.local         smtp...:587          imap...:993       Aktiv
tochter.local          smtp...:587          imap...:993       Aktiv
```

CRUD:

* hinzufügen
* bearbeiten
* aktivieren/deaktivieren
* löschen, sofern keine Abhängigkeiten bestehen.

### Abschnitt 2 – Postfächer

Postfächer der ausgewählten Identitätsquelle.

Spalten:

```text
Benutzername
E-Mail-Adresse
Status
Aktionen
```

### Abschnitt 3 – Zuordnung

Zweispaltig:

```text
AD-Benutzer                         Postfach
------------------------------------------------
[Max Mustermann        ▼]          [max@firma.de ▼]
[Erika Musterfrau      ▼]          [erika@firma.de ▼]
```

Aktionen:

* Zuordnung hinzufügen
* Zuordnung ändern
* Zuordnung entfernen.

---

# 14. Autocomplete-API

Falls für die UX API-Endpunkte notwendig sind, füge schlanke Admin-JSON-Endpunkte hinzu.

Beispielsweise:

```text
GET /admin/office/mail-proxy/users?q=...
GET /admin/office/mail-proxy/mailboxes?q=...
```

Nur für Administratoren.

Die APIs dürfen ausschließlich Daten zurückgeben, die für die Auswahl erforderlich sind.

**Nie Credentials zurückgeben.**

Autocomplete muss serverseitig nach Identitätsquelle filtern.

---

# 15. Orvanta-Integration

Untersuche insbesondere:

* `OrvantaController`
* `OrvantaApiController`
* `OrvantaExchangeService`
* `ExchangeTransportInterface`
* `CurlExchangeTransport`
* `DemoExchangeTransport`
* `OrvantaConfigService`
* Mail-, Attachment-, Calendar- und Reminder-Logik.

Bestimme exakt, welche Orvanta-Funktionen aktuell EWS voraussetzen.

Wichtig:

Der Proxy darf nicht nur das reine Anzeigen und Senden von E-Mails unterstützen, wenn Orvanta an anderer Stelle zwingend EWS voraussetzt.

Dokumentiere deshalb explizit:

* Mail lesen
* Mail senden
* Antworten
* Weiterleiten
* Anhänge
* Ordner
* Suche
* Kalender
* Kontakte
* Aufgaben
* Notizen
* Erinnerungen
* Archivierung.

Wenn SMTP/IMAP bestimmte bisherige Exchange-Funktionen nicht abbilden können, muss das sauber abstrahiert werden.

**Nicht versuchen, EWS-Funktionalität künstlich aus IMAP nachzubauen, ohne dies fachlich und technisch zu begründen.**

Das Primärziel dieses Features ist die Nutzung von Orvanta für Benutzer ohne Exchange-Postfach.

---

# 16. Kalender / Kontakte / Aufgaben

Prüfe ausdrücklich, wie Orvanta aktuell Kalender, Kontakte, Aufgaben und Notizen aus Exchange bezieht.

Für Benutzer mit SMTP/IMAP-Postfach gibt es möglicherweise keine entsprechende Exchange-Funktion.

Deshalb:

* Mail-Funktionen über IMAP/SMTP bereitstellen.
* Nicht vorhandene Funktionen sauber als nicht verfügbar behandeln.
* Keine kaputten EWS-Aufrufe durchführen.
* UI ggf. gezielt deaktivieren/ausblenden, wenn die Funktion für Proxy-Postfächer nicht verfügbar ist.
* Keine stillen Datenverluste.

Die Entscheidung muss in der technischen Dokumentation festgehalten werden.

---

# 17. Archivierung

Prüfe die vorhandene Orvanta-Langzeitarchivierung.

Der bestehende Archivmechanismus darf nicht versehentlich für Proxy-Postfächer beschädigt werden.

Wenn die Archivierung unabhängig von Exchange funktioniert, soll sie auch für Proxy-Postfächer funktionieren.

Wenn bestimmte Voraussetzungen fehlen, implementiere eine saubere Capability-Prüfung.

---

# 18. Docker / Deployment

Wenn ein Python-Proxy benötigt wird:

* `docker-compose.yml` erweitern.
* separates Dockerfile erstellen.
* Healthcheck hinzufügen.
* internes Docker-Netz verwenden.
* keine unnötige Portfreigabe nach außen.
* Kommunikation PHP ↔ Proxy möglichst ausschließlich über internes Docker-Netz.
* Secrets nicht fest in Images einbauen.
* bestehende `.env`-/Secret-Konventionen respektieren.
* Startreihenfolge und Healthchecks berücksichtigen.

Der Proxy soll nicht direkt aus dem Internet erreichbar sein.

Wenn möglich:

```text
app ──────┐
          │ internes Netzwerk
          ▼
      mail-proxy
       │      │
       ▼      ▼
     SMTP    IMAP
```

---

# 19. Konfiguration und Secret Handling

Nutze bestehende Projektmechanismen.

Nicht einfach neue Klartext-Environment-Variablen für jedes Postfach einführen.

Die dynamischen Postfachdaten gehören in die Datenbank und müssen verschlüsselt gespeichert werden.

Falls der Python-Proxy Zugriff auf entschlüsselte Credentials benötigt, entwirf einen sicheren internen Übergabemechanismus.

Bevorzugt:

```text
PHP
 │
 │ authentifizierter interner Request
 ▼
Proxy
```

statt:

```text
gemeinsames Klartext-Config-File
```

Credentials möglichst nur so lange im Speicher halten wie nötig.

---

# 20. Tests

Implementiere umfassende Tests.

Mindestens:

## Repository

* Proxy anlegen
* Proxy lesen
* Proxy aktualisieren
* Proxy löschen
* Postfach anlegen
* Postfach aktualisieren
* Postfach löschen
* Mapping anlegen
* Mapping ändern
* Mapping löschen.

## Sicherheitslogik

* Passwort wird verschlüsselt gespeichert.
* Passwort erscheint nicht in Rückgaben.
* Passwort erscheint nicht in Logs.
* Nicht-Admin kann Konfiguration nicht verändern.
* CSRF wird geprüft.

## Mapping

* korrekte Identitätsquelle wird gefunden.
* Benutzer ohne Mapping fällt auf Exchange zurück.
* Benutzer mit Mapping verwendet Proxy.
* Benutzer aus anderer Identitätsquelle verwendet nicht das falsche Mapping.
* deaktivierte Postfächer werden nicht verwendet.
* deaktivierte Proxy-Konfigurationen werden nicht verwendet.

## Cache

* Cache Hit
* Cache Miss
* Invalidierung nach Mappingänderung
* Invalidierung nach Postfachänderung
* Invalidierung nach Proxyänderung
* TTL.

## Transport

Teste SMTP und IMAP über Mock-/Fake-Transporte.

Keine echten externen Mailserver für Unit-Tests voraussetzen.

## Regression

Bestehende Orvanta-Exchange-Tests müssen weiterhin bestehen.

---

# 21. Diagnose

Integriere sinnvolle Diagnoseinformationen in den bestehenden Office-/Orvanta-Diagnosebereich.

Administratoren sollen erkennen können:

```text
SMTP-/IMAP-Proxy
----------------
Konfiguration: OK
Proxy-Dienst: OK
Konfigurationen: 3
Postfächer: 124
Mappings: 118

Letzte erfolgreiche Verbindung: ...
Fehler: ...
```

Keine Zugangsdaten anzeigen.

Optional darf es einen "Verbindung testen"-Button geben.

Der Test muss:

* SMTP-Verbindung prüfen
* IMAP-Verbindung prüfen
* TLS prüfen
* Credentials testen, sofern sinnvoll
* Timeout besitzen
* keine Mails senden.

---

# 22. Logging

Logge nur technische Informationen:

```text
mail-proxy mapping resolved
identity_source=mvzintsz.local
user=<interne Kennung>
mailbox_id=123
```

Keine:

```text
password=...
Authorization: ...
LOGIN username/password
```

Logs müssen vorhandene Maskierungsmechanismen verwenden.

Fehler müssen für Administratoren nachvollziehbar sein, ohne Geheimnisse zu verraten.

---

# 23. Dokumentation

Erweitere die bestehende technische Dokumentation.

Mindestens:

* `docs/orvanta.md`
* `docs/orvanta-referenz.md`
* `docs/office.md`
* ggf. eigene `docs/mail-proxy.md`.

Dokumentiere:

* Architektur
* Datenmodell
* Mapping
* Identitätsquellen
* Proxy-Container
* SMTP
* IMAP
* Cache
* Credential Handling
* Fehlerverhalten
* Orvanta-Capabilities
* Docker
* Troubleshooting.

---

# 24. Coding-Standards des bestehenden Projekts

Strikt einhalten:

* PHP 8.5 / mindestens 8.4
* `declare(strict_types=1);`
* bestehender Namespace-Stil
* `final` Klassen, sofern passend
* Constructor Dependency Injection
* `private readonly`
* PDO / Prepared Statements
* keine Composer-Abhängigkeit
* kein npm
* keine externen CDNs
* Vanilla JavaScript
* handgeschriebenes CSS
* bestehende View-/Controller-/Repository-/Service-Struktur.
* bestehende CSRF-, Session- und Authentifizierungsmechanismen.
* bestehende Migration-Konvention.

Das Repository ist bewusst frameworkfrei. Führe daher kein Laravel, Symfony, React, Vue, jQuery o. Ä. ein.

---

# 25. Keine vorschnelle Implementierung

Arbeite in dieser Reihenfolge:

### Phase 1 – Analyse

1. Bestehende Orvanta-Architektur untersuchen.
2. Identitätsquellen untersuchen.
3. Benutzeridentifikation untersuchen.
4. Office-Admin-Struktur untersuchen.
5. Mail-/SMTP-Code untersuchen.
6. Docker-Struktur untersuchen.
7. Datenmodell untersuchen.
8. Tests untersuchen.

### Phase 2 – Architektur

Erstelle vor der eigentlichen Implementierung einen kurzen technischen Plan:

```text
Betroffene Dateien:
Neue Dateien:
Geänderte Dateien:
Neue Migration:
Neue Routen:
Neue Services:
Neue Repositories:
Neue Interfaces:
Neue Container:
Orvanta-Änderungen:
Tests:
```

Achte dabei darauf, bestehende Abstraktionen wiederzuverwenden.

### Phase 3 – Implementierung

Danach vollständig implementieren.

### Phase 4 – Tests

Führe mindestens aus:

```bash
php tests/run.php
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

Wenn Docker geändert wurde:

```bash
docker compose config
docker compose build
```

und soweit in der Entwicklungsumgebung möglich:

```bash
docker compose up -d
```

Anschließend Healthchecks bzw. relevante Funktionstests durchführen.

### Phase 5 – Abschluss

Am Ende berichten:

1. Was wurde implementiert?
2. Welche Dateien wurden geändert?
3. Welche Migration wurde hinzugefügt?
4. Welche Container wurden hinzugefügt/geändert?
5. Wie funktioniert das Mapping?
6. Wie entscheidet Orvanta zwischen Exchange und Proxy?
7. Welche Orvanta-Funktionen sind über IMAP/SMTP verfügbar?
8. Welche Funktionen sind bei Proxy-Postfächern nicht verfügbar?
9. Welche Sicherheitsmaßnahmen wurden umgesetzt?
10. Welche Tests wurden ausgeführt?
11. Welche Tests sind ggf. aufgrund fehlender Infrastruktur nicht ausführbar?

---

# 26. Akzeptanzkriterien

Die Implementierung gilt erst als fertig, wenn mindestens folgende Szenarien funktionieren:

### Szenario A – bestehender Exchange-Benutzer

```text
AD-User
→ keine Proxy-Zuordnung
→ Orvanta
→ bestehender Exchange/EWS-Transport
```

Das bestehende Verhalten bleibt unverändert.

### Szenario B – Proxy-Benutzer

```text
AD-User
→ Identitätsquelle mvzintsz.local
→ Mapping auf mailbox@example.local
→ Orvanta
→ IMAP/SMTP Proxy
→ mailbox@example.local
```

Das muss ohne Exchange-Postfach funktionieren.

### Szenario C – zwei Identitätsquellen

```text
User A → mvzintsz.local → Mailserver A
User B → firma2.local   → Mailserver B
```

Die Benutzer dürfen niemals versehentlich den jeweils anderen Mailserver bzw. das andere Postfach verwenden.

### Szenario D – falsche/deaktivierte Konfiguration

```text
Mapping vorhanden
+
Proxy deaktiviert
```

→ kein Zugriff auf das deaktivierte Proxy-Postfach.

### Szenario E – Cache

Mapping ändern:

```text
User A → mailbox1
```

auf:

```text
User A → mailbox2
```

Danach muss Orvanta zuverlässig `mailbox2` verwenden und darf aufgrund eines alten Caches nicht weiter `mailbox1` verwenden.

### Szenario F – Sicherheit

Ein Admin kann Passwörter konfigurieren, aber:

* Passwort wird verschlüsselt gespeichert.
* Passwort wird niemals angezeigt.
* Passwort erscheint nicht in API-Antworten.
* Passwort erscheint nicht im Log.
* Passwort erscheint nicht im Backup/Export.

---

# 27. Besonders wichtig

Vermeide eine Implementierung, bei der die komplette Mail-Funktionalität hart an die aktuelle Exchange-Implementierung gekoppelt bleibt.

Das Ziel ist eine langfristig wartbare Architektur:

```text
                         ┌── Exchange / EWS
Orvanta Mail Backend ────┤
                         └── SMTP/IMAP Proxy
```

Die Auswahl erfolgt anhand des aktuell angemeldeten Benutzers und seines Mailbox-Mappings.

Die vorhandene Exchange-Funktionalität ist dabei der Default/Fallback, nicht etwas, das durch die neue Funktion zerstört oder ersetzt werden darf.

Implementiere die Funktion vollständig, einschließlich Datenbankmigration, Admin-UI, Mapping, Autocomplete, Proxy-/Transportlogik, Cache, Sicherheitsmaßnahmen, Docker-Integration, Tests und Dokumentation.

Wenn eine technische Detailentscheidung aus dieser Aufgabenbeschreibung nicht eindeutig hervorgeht, entscheide anhand der bestehenden Repository-Architektur und dokumentiere die Entscheidung. Erfinde keine neuen Frameworks oder parallelen Mechanismen, wenn das Projekt bereits eine passende Abstraktion besitzt.
