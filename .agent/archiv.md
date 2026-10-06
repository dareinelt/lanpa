# Aufgabe: Robustes Mailarchiv für Orvanta implementieren

Du arbeitest direkt in der bestehenden Codebasis der Mail- und Kalender-App **Orvanta** unter `/office/orvanta`.

Die Datei `orvanta-referenz.md` ist die technische Referenz für die bestehende Architektur. **Lies sie vollständig und untersuche zusätzlich den tatsächlichen Code. Bei Abweichungen gilt der vorhandene Code.** Die dort beschriebenen Invarianten dürfen nicht gebrochen werden.

Implementiere eine produktionsreife Archivierungsfunktion für Exchange-E-Mails.

---

## 1. Ziel

Orvanta soll E-Mails aus dem Exchange-Postfach automatisch in ein dauerhaftes, komprimiertes Archiv verschieben können.

Das Archiv soll:

* Daten aus dem Exchange-Postfach dauerhaft übernehmen,
* anschließend die entsprechenden Elemente aus Exchange entfernen,
* dadurch den belegten Exchange-Postfachspeicher reduzieren,
* in Nextcloud gespeichert werden,
* **nicht auf das normale Orvanta-/Benutzer-Quota angerechnet werden**,
* die vollständige Exchange-Ordnerstruktur abbilden,
* durchsucht werden können,
* auch bei sehr großen Archiven zuverlässig funktionieren,
* gegen unvollständige Schreibvorgänge und Prozessabbrüche geschützt sein,
* langfristig die Integrität der archivierten Daten gewährleisten,
* bei erneutem Überschreiten der Exchange-Schwelle dasselbe Archiv fortschreiben.

Der Benutzer entscheidet **nicht**, wann archiviert wird und welche Nachrichten archiviert werden.

Die Archivierung ist eine durch Richtlinien gesteuerte Systemfunktion.

---

# 2. Fachliche Regeln

## 2.1 Auslöser

Für jedes Postfach muss eine konfigurierbare Grenze für die belegte Exchange-Postfachgröße existieren.

Beispiel:

* Archivierungsschwelle: 80 % oder alternativ ein absoluter Wert in MB
* Altersschwelle: 60 Tage

Sobald die konfigurierte Grenze überschritten wird:

1. wird automatisch ein Archivierungslauf gestartet,
2. es werden alle archivierungsfähigen E-Mail-Elemente ausgewählt, die **älter als 60 Tage** sind,
3. diese werden in das Archiv übernommen,
4. erst nach erfolgreicher und verifizierter Archivierung dürfen die Originale aus Exchange gelöscht werden.

**Wichtig:** Niemals zuerst aus Exchange löschen und anschließend archivieren.

Der Grundsatz muss sein:

> Copy → verify → durable commit → delete from Exchange

Ein Abbruch zu irgendeinem Zeitpunkt darf niemals zu Datenverlust führen.

---

# 3. Welche Exchange-Daten archiviert werden

Archiviert werden E-Mail-Elemente aus dem Exchange-Postfach.

Mindestens:

* Posteingang
* Gesendete Elemente
* eigene Mailordner
* Unterordner
* sonstige normale `IPF.Note*`-Ordner

Die vorhandene Exchange-Ordnerstruktur muss erhalten bleiben.

Nicht einfach alle Nachrichten in eine einzige Liste schreiben.

Das Archiv muss beispielsweise eine Struktur wie diese abbilden können:

```text
Archiv
├── Posteingang
│   ├── Kunde A
│   └── Kunde B
├── Gesendete Elemente
├── Projekte
│   ├── Projekt Alpha
│   └── Projekt Beta
└── Sonstige
```

Verwende die tatsächlichen Exchange-Ordner-IDs und Namen als Grundlage für eine stabile Ordneridentifikation.

---

# 4. Archivformat

Entwickle ein geeignetes **containerbasiertes Archivformat**.

Nicht einfach eine lose Sammlung einzelner Dateien in Nextcloud ablegen.

Das Archiv soll mindestens enthalten:

* Formatversion
* Archiv-ID
* Benutzer-/Postfachbezug
* Erstellungsdatum
* Aktualisierungsdatum
* Archivversion bzw. Generation
* Ordnerstruktur
* Nachrichten
* Anhänge
* Metadaten
* Integritätsinformationen
* Suchindex bzw. Referenzen auf den Suchindex

Das Format muss für zukünftige Migrationen versionierbar sein.

### Anforderungen an das Datenformat

Es muss:

* komprimiert sein,
* streambar sein,
* große Archive unterstützen,
* einzelne Nachrichten wiederfinden können,
* nicht für jede Suche vollständig dekomprimiert werden müssen,
* Anhänge verlustfrei erhalten,
* HTML-Body erhalten,
* Text-Body erhalten,
* relevante Mailheader erhalten,
* Originaldaten möglichst vollständig erhalten.

Bevorzugt soll für E-Mail-Elemente das originale MIME/RFC-5322-Dokument gespeichert werden, sofern dieses über EWS zuverlässig verfügbar ist.

Die vorhandene EWS-Funktion für `MimeContent`/Kopfzeilen ist dabei zu untersuchen.

**Keine unnötige proprietäre Rekonstruktion einer E-Mail**, wenn das Originalformat zuverlässig gespeichert werden kann.

---

# 5. Langzeitstabilität und Integrität

Das Archiv ist kein temporärer Cache.

Es muss für eine langfristige Aufbewahrung ausgelegt sein.

Jede archivierte Einheit benötigt eine Integritätsprüfung, mindestens über einen kryptografischen Hash.

Beispielsweise:

```text
SHA-256(message bytes)
```

Zusätzlich sollte auch auf Container-/Chunk-Ebene mit Checksummen gearbeitet werden.

Der Agent soll ein Format entwerfen, bei dem beschädigte Daten möglichst lokal erkannt werden können.

Bei einem Lesefehler muss klar erkennbar sein:

> Archivdaten beschädigt / Integritätsprüfung fehlgeschlagen

und nicht einfach eine leere oder unvollständige Mail angezeigt werden.

---

# 6. Schutz vor abgebrochenen Schreibvorgängen

Dies ist eine **harte Anforderung**.

Ein Archiv darf niemals durch einen Prozessabbruch, PHP-Fatal-Error, Timeout, Server-Neustart, Nextcloud-Fehler oder Netzwerkabbruch in einen Zustand geraten, in dem bereits archivierte Daten als vollständig gelten, obwohl sie unvollständig geschrieben wurden.

Implementiere deshalb einen transaktionalen bzw. journalartigen Archivierungsmechanismus.

Beispielhafte Zustände:

```text
pending
writing
verifying
committed
deleting_from_exchange
completed
failed
```

Ein Archivierungsvorgang muss nach einem Absturz fortgesetzt werden können.

### Wichtig

Die Information

> Nachricht ist archiviert und darf aus Exchange gelöscht werden

darf **erst nach erfolgreicher dauerhafter Speicherung und Integritätsprüfung** entstehen.

Eine sinnvolle Architektur kann beispielsweise mit:

* temporärer Archivdatei,
* append-only Chunks,
* Manifest,
* Journal,
* Commit-Markern,
* Checksummen,
* atomarem Rename,
* Write-Ahead-Log

arbeiten.

Wähle die konkrete technische Lösung anhand der vorhandenen Nextcloud- und Storage-Abstraktionen.

**Nicht voraussetzen, dass ein einzelner Upload einer großen Datei atomar und ausfallsicher ist.**

---

# 7. Umgang mit Nextcloud

Verwende die bestehende Nextcloud-Integration von Orvanta.

Die vorhandene `NextcloudFilesService`-Implementierung und ihre Grenzen sind zu untersuchen.

Das Archiv benötigt aber eine eigene Speicherklasse und darf **nicht wie der vorhandene flüchtige Attachment-Cache behandelt werden**.

Insbesondere:

* kein FIFO-Evict,
* kein normales Cache-Quota,
* kein automatisches Löschen,
* keine Vermischung mit `orvanta_cache_items`.

Archive müssen dauerhaft gespeichert werden.

Die bestehende Nextcloud-Ablage erfolgt über die vorhandene Integration `intranet_integration`; diese Infrastruktur soll wiederverwendet bzw. sinnvoll erweitert werden.

---

# 8. Ein Archiv pro Benutzer/Postfach

Ein Benutzer erhält grundsätzlich ein dauerhaftes Archiv.

Beim ersten Archivierungslauf wird es erzeugt.

Bei späteren Archivierungsläufen darf **kein neues Archiv erzeugt werden**, wenn das bestehende Archiv weitergeführt werden kann.

Beispiel:

```text
Postfach überschreitet Grenze
    ↓
Archivierung
    ↓
Archiv.orvanta
    ↓
Exchange wird wieder kleiner

Monate später:
Postfach überschreitet Grenze
    ↓
selbes Archiv.orvanta
    ↓
neue alte Nachrichten werden angehängt
```

Das Archiv muss also inkrementell erweitert werden können.

---

# 9. Keine Duplikate

Ein Archivierungslauf kann jederzeit unterbrochen werden.

Beim nächsten Lauf muss der Agent feststellen können:

* welche Exchange-Elemente bereits sicher archiviert wurden,
* welche noch fehlen,
* welche gerade geschrieben wurden,
* welche bereits erfolgreich gelöscht wurden.

Eine Nachricht darf nicht unkontrolliert mehrfach archiviert werden.

Da Exchange-IDs und ChangeKeys relevant sind, muss eine stabile Archiv-Identität definiert werden.

Untersuche insbesondere:

* EWS `ItemId`
* `ChangeKey`
* `InternetMessageId`
* MIME-Inhalt
* Ordner-ID

Die Lösung muss berücksichtigen, dass Exchange-IDs bei Verschiebungen problematisch sein können.

Der Archivindex benötigt daher eine robuste interne Identität.

---

# 10. Löschen aus Exchange

**Das Löschen aus Exchange ist der gefährlichste Teil des Features.**

Eine Nachricht darf nur dann aus Exchange entfernt werden, wenn:

1. der Inhalt vollständig gelesen wurde,
2. alle benötigten Anhänge enthalten sind,
3. der Archivdatensatz geschrieben wurde,
4. die Checksummen stimmen,
5. der Archivdatensatz committed wurde,
6. der Index aktualisiert bzw. persistent gemacht wurde,
7. der Archivstatus eindeutig auf `committed` steht.

Danach darf das Exchange-Element gelöscht bzw. in die vorgesehenen Löschmechanismen verschoben werden.

Wenn irgendeiner dieser Schritte fehlschlägt:

**Nachricht in Exchange belassen.**

---

# 11. Suche

Die Orvanta-Suche soll sowohl:

* aktuelle Exchange-Nachrichten
* als auch archivierte Nachrichten

durchsuchen.

Die Suche darf **nicht jedes Mal das gesamte Archiv dekomprimieren**.

Dafür ist ein persistenter Suchindex erforderlich.

Der Index soll mindestens unterstützen:

* Betreff
* Absender
* Empfänger
* Datum
* Ordner
* Internet Message ID
* Volltext des Nachrichtentextes
* optional Dateinamen von Anhängen
* Archiv-ID
* interne Nachrichten-ID

Untersuche, welche lokale Datenbank Orvanta bereits verwendet und welche Suchtechnologie dort sinnvoll integriert werden kann.

Die bestehende Architektur verwendet Repository-SQL, das sowohl MySQL als auch SQLite in Tests unterstützen muss.

Der Index darf Metadaten und Suchtext enthalten.

**Der eigentliche Mailinhalt bleibt im Archiv.**

---

# 12. Suchergebnis aus Archiv

Ein Suchergebnis muss erkennen lassen, dass es aus dem Archiv kommt.

Beispiel:

```text
📦 Archiv

Max Mustermann
Re: Projekt ABC
12.04.2024
```

Aktuelle Exchange-Mail:

```text
Max Mustermann
Re: Projekt ABC
12.04.2026
```

Archivierte Treffer müssen in der UI eindeutig gekennzeichnet werden.

---

# 13. Abruf archivierter Nachrichten

Wenn der Benutzer eine archivierte Nachricht öffnet:

1. wird zunächst der Indextreffer angezeigt,
2. der eigentliche Datensatz wird aus dem Archiv geladen,
3. der entsprechende Container-/Chunk wird gelesen,
4. die Daten werden dekomprimiert,
5. Integrität wird geprüft,
6. anschließend wird die Nachricht wie eine normale Mail dargestellt.

Das kann bewusst langsamer sein als der Abruf einer Exchange-Mail.

Die Benutzeroberfläche muss das sichtbar machen.

Beispiel:

```text
Archiv wird geladen …
Nachricht wird aus dem Langzeitarchiv gelesen …
```

Bei längerer Dauer:

```text
Archiv wird geladen …
████████░░░░░░░░ 52 %
```

Falls keine echte Prozentzahl ermittelt werden kann, keinen künstlichen Fortschritt vortäuschen.

Dann lieber:

```text
Archiv wird geladen …
Dieser Vorgang kann bei großen Archiven einige Sekunden dauern.
```

---

# 14. Archivierte Nachricht und Anhänge

Anhänge müssen vollständig Bestandteil des Archivs sein.

Beim Öffnen eines Anhangs:

* aus dem Archiv lesen,
* Integrität prüfen,
* dekomprimieren,
* anschließend den bestehenden Viewer-/Download-Mechanismus verwenden.

Der vorhandene Attachment-Mechanismus verwendet kurzlebige, benutzergebundene Tokens. Diese Sicherheitsarchitektur ist beizubehalten bzw. für Archivanhänge wiederzuverwenden.

Ein Archivanhang darf nicht einfach durch eine öffentlich erratbare URL erreichbar sein.

---

# 15. Ordnerstruktur

Die Archivansicht soll die Exchange-Ordnerstruktur widerspiegeln.

Es soll erkennbar sein, ob ein Ordner:

* aktuelle Exchange-Nachrichten,
* archivierte Nachrichten,
* oder beides

enthält.

Beispielsweise:

```text
Posteingang
  ├─ Exchange
  └─ Archiv

Gesendete Elemente
  ├─ Exchange
  └─ Archiv

Projekte
  ├─ Exchange
  └─ Archiv
```

Die konkrete UI soll sich aber möglichst natürlich in die bestehende Orvanta-Ordneransicht integrieren.

Nicht unnötig eine zweite komplett getrennte Mail-App bauen.

---

# 16. Benutzerinformation

Der Benutzer bekommt **keine Entscheidungsmöglichkeit**.

Es gibt keine:

* „Jetzt archivieren“-Bestätigung,
* Auswahl der Nachrichten,
* Auswahl des Alters,
* Auswahl des Archivs,
* Möglichkeit, die Richtlinie zu deaktivieren.

Der Benutzer wird ausschließlich informiert.

Beispiel:

> Ihr Postfach hat die zulässige Belegungsgrenze überschritten. Zur Einhaltung der Postfachrichtlinie wurden ältere Nachrichten automatisch im Langzeitarchiv gespeichert.

Die Meldung sollte nach erfolgreichem bzw. angestoßenem Archivierungslauf erscheinen.

Fehler dürfen nicht verschwiegen werden.

Beispiel:

> Die automatische Archivierung konnte nicht vollständig durchgeführt werden. Ihre Nachrichten wurden nicht gelöscht. Die Archivierung wird erneut versucht.

---

# 17. Admin-Konfiguration

Erweitere die bestehende Orvanta-Konfiguration um die notwendigen Archivierungsparameter.

Mindestens:

```text
archive_enabled
archive_group
archive_threshold
archive_threshold_unit
archive_age_days
archive_storage_folder
archive_compression
archive_batch_size
archive_poll_interval
```

Die konkrete Benennung soll sich an den vorhandenen `OrvantaConfigService::DEFAULTS` und Konventionen orientieren.

Die Standard-Altersgrenze ist:

```text
60 Tage
```

Die Archivierungsschwelle muss durch den Administrator definierbar sein.

Die bestehende `mailboxUsage()`-Logik liefert bereits Postfachbelegung und Quota-/Warnwerte und soll wiederverwendet bzw. sinnvoll erweitert werden, statt eine parallele Exchange-Quota-Logik zu implementieren.

---

# 18. Automatischer Hintergrundprozess

Die Archivierung darf **nicht davon abhängig sein, dass der Benutzer die Orvanta-Oberfläche geöffnet hat**.

Untersuche die bestehende Anwendung auf vorhandene:

* Cron-/Scheduler-Mechanismen,
* CLI-Kommandos,
* Queue-Mechanismen,
* wiederkehrende Hintergrundjobs.

Verwende die vorhandene Infrastruktur, sofern geeignet.

Falls keine geeignete Infrastruktur vorhanden ist, implementiere einen dedizierten, wiederaufnehmbaren Archivierungs-Worker.

Wichtig:

* keine parallele Archivierung desselben Postfachs,
* Locking,
* Wiederaufnahme nach Prozessabbruch,
* begrenzte Batchgrößen,
* kein unbegrenzter RAM-Verbrauch,
* kein vollständiges Laden eines großen Postfachs in den Speicher.

---

# 19. Batch-Verarbeitung

Große Postfächer dürfen nicht in einem einzigen Request verarbeitet werden.

Archivierung muss in Batches erfolgen.

Beispielsweise:

```text
100 Nachrichten
→ speichern
→ prüfen
→ committen
→ Exchange löschen
→ nächster Batch
```

Die Batchgröße soll konfigurierbar sein.

Die konkrete Größe ist nach Analyse der bestehenden EWS- und Nextcloud-Grenzen festzulegen.

Der Prozess muss auch mit sehr großen Postfächern funktionieren.

---

# 20. Speicherverbrauch

Es dürfen niemals alle:

* Nachrichten,
* Anhänge,
* MIME-Daten

eines Postfachs gleichzeitig im PHP-RAM gehalten werden.

Verwende Streaming bzw. kleine Verarbeitungseinheiten, wo die vorhandenen APIs das zulassen.

---

# 21. Archivindex und Konsistenz

Der Index darf niemals dauerhaft auf einen nicht existierenden Archivdatensatz zeigen.

Ebenso darf ein erfolgreicher Archivdatensatz nicht dauerhaft unsichtbar sein, nur weil ein Indexschritt nach einem Prozessabbruch fehlgeschlagen ist.

Deshalb muss die Reihenfolge bzw. Recovery-Strategie klar definiert werden.

Der Agent soll eine robuste Recovery-Logik implementieren, z. B.:

```text
ARCHIVE RECORD
     ↓
VERIFY
     ↓
COMMIT
     ↓
INDEX
     ↓
EXCHANGE DELETE
```

oder eine technisch bessere Variante, sofern sie dieselben Garantien erfüllt.

Nach einem Absturz muss der nächste Lauf den Zustand rekonstruieren können.

---

# 22. Archivvalidierung

Implementiere eine Möglichkeit, Archive zu prüfen.

Mindestens intern bzw. administrativ:

```text
Archiv vorhanden
Archivformat gültig
Manifest gültig
Chunks gültig
Checksummen gültig
Indexreferenzen gültig
Anzahl Datensätze konsistent
```

Ein beschädigtes Archiv darf nicht stillschweigend als leer angezeigt werden.

---

# 23. Archivrotation / Versionierung

Das Archivformat muss versioniert sein.

Beispiel:

```text
format_version = 1
```

Eine spätere Änderung des Archivformats muss Migration oder Lesen älterer Formate ermöglichen.

Nicht einfach eine undokumentierte PHP-Serialisierung verwenden.

Insbesondere:

**Kein `serialize()` als langfristiges Dateiformat für die Maildaten.**

---

# 24. Sicherheit

Alle Archivzugriffe müssen an den aktuell authentifizierten Benutzer gebunden sein.

Der Benutzer darf niemals:

* fremde Archive,
* fremde Archiv-IDs,
* fremde Archivnachrichten,
* fremde Nextcloud-Dateien

abrufen können, indem er IDs im Request manipuliert.

Die bestehende Orvanta-Invariante

> Identität nur vom Server

bleibt vollständig erhalten. Kein API-Endpunkt darf ein beliebiges Postfach aus dem Request akzeptieren.

Alle schreibenden API-Aufrufe müssen weiterhin POST + CSRF verwenden.

---

# 25. API

Erweitere die bestehende Orvanta-API konsistent.

Mögliche Endpunkte:

```text
GET  /api/orvanta/archiv/status
GET  /api/orvanta/archiv/suche
GET  /api/orvanta/archiv/nachricht
GET  /api/orvanta/archiv/anhang
GET  /api/orvanta/archiv/ordner
GET  /api/orvanta/archiv/fortschritt
```

Administrative bzw. interne Worker-Endpunkte nur dann als HTTP-Endpunkte bauen, wenn sie tatsächlich benötigt werden.

Die bestehende API-Konvention ist einzuhalten:

```text
/api/orvanta/*
```

JSON rein/raus, vorhandene Autorisierung, CSRF für schreibende Endpunkte, bestehende Fehlerbehandlung.

---

# 26. UI

Integriere die Funktion in das bestehende Orvanta-Frontend.

Die bestehende Oberfläche ist Vanilla-JS ohne Build und verwendet `.ov-*`-CSS-Präfixe. Keine neue Frontend-Technologie einführen, wenn sie nicht zwingend erforderlich ist.

Die UI soll mindestens:

### Status

anzeigen können:

```text
Postfach
7,8 GB / 10 GB

Archiv
4,2 GB
2.184 Nachrichten
```

### Archivkennzeichnung

Archivierte Nachrichten sichtbar kennzeichnen.

### Ladezustand

Beim Abruf aus dem Archiv:

```text
Archiv wird geladen …
```

Bei längerer Verarbeitung zusätzlich Hinweis:

```text
Das Archiv wird dekomprimiert. Dies kann einige Sekunden dauern.
```

### Benachrichtigung

Nach automatischer Archivierung eine normale Orvanta-Benachrichtigung anzeigen.

Der Benutzer darf den Vorgang nicht konfigurieren.

---

# 27. Exchange-Synchronität

Berücksichtige Race Conditions.

Während ein Archivierungslauf läuft, können Nachrichten:

* verschoben,
* gelesen,
* gelöscht,
* geändert

werden.

Eine Nachricht darf nur archiviert und gelöscht werden, wenn der zum Archivieren gelesene Zustand eindeutig dem Exchange-Element entspricht.

Nutze, wo sinnvoll:

* `ItemId`
* `ChangeKey`
* `InternetMessageId`

und die vorhandenen EWS-Mechanismen.

Bei Konflikten gilt:

> Nicht löschen. Später erneut prüfen.

Die bestehende Anwendung verwendet bei schreibenden EWS-Aufrufen teilweise `ConflictResolution="AlwaysOverwrite"`; **diese Strategie darf nicht blind für die Archivlöschung übernommen werden**. Für Archivierung ist Datenverlustvermeidung wichtiger als „letzte Änderung gewinnt“.

---

# 28. EWS

Neue EWS-Funktionen müssen vollständig in `OrvantaExchangeService` integriert werden.

**Nie direkt aus Controller oder Worker auf den Transport zugreifen.**

Die bestehende Aufrufkette bleibt:

```text
OrvantaExchangeService
    ↓
call()
    ↓
EwsXml::envelope()
    ↓
ExchangeTransportInterface
```

Die vorhandenen EWS-Konventionen sind einzuhalten.

Insbesondere:

* `EwsXml::escape()`
* `EwsXml::folderId()`
* `EwsXml::dateTime()`
* vorhandene Fehlerübersetzung
* Impersonation
* Demo-Transport

Neue EWS-Operationen müssen ebenfalls im `DemoExchangeTransport` berücksichtigt werden.

---

# 29. Demo-Modus

Die Archivfunktion muss auch im bestehenden Demo-Modus testbar sein.

Erweitere:

```text
DemoExchangeTransport
```

um realistische Archivierungsdaten und Schreiboperationen.

Der Demo-Modus darf weiterhin niemals in Produktion aktiviert werden.

---

# 30. Datenbank

Erstelle die erforderlichen Migrationen.

Das Datenmodell sollte mindestens Zustände für:

### Archive

```text
id
user_uid
mailbox
storage_path
format_version
status
created_at
updated_at
last_successful_run
size_bytes
message_count
```

### Archive items

```text
id
archive_id
folder_id
folder_path
exchange_item_id
change_key
internet_message_id
content_hash
archive_offset / chunk_reference
status
created_at
committed_at
```

### Archive jobs

```text
id
archive_id
status
started_at
finished_at
last_error
processed_count
committed_count
deleted_count
```

Die endgültige Struktur ist vom Agenten nach Analyse der Anforderungen und des vorhandenen Repository-Stils zu wählen.

Keine unnötige Redundanz.

SQL muss weiterhin mit MySQL und SQLite-Tests kompatibel sein, soweit dies für die Anwendung vorgesehen ist.

---

# 31. Locking

Es darf niemals zwei aktive Archivierungsjobs für dasselbe Postfach geben.

Implementiere einen zuverlässigen Lock.

Berücksichtige:

* PHP-Prozessabbruch,
* Worker-Absturz,
* Server-Neustart,
* stale locks.

Ein Lock darf nicht dazu führen, dass ein Postfach nach einem Absturz dauerhaft nicht mehr archiviert wird.

---

# 32. Fehlerbehandlung

Fehler müssen differenziert behandelt werden.

Beispiele:

### Exchange nicht erreichbar

```text
Archivierung pausieren.
Exchange-Daten nicht löschen.
Beim nächsten Lauf erneut versuchen.
```

### Nextcloud nicht erreichbar

```text
Archivierung pausieren.
Exchange-Daten nicht löschen.
```

### Archivprüfung fehlgeschlagen

```text
Datensatz nicht committen.
Exchange-Daten nicht löschen.
```

### Index fehlgeschlagen

Eine Recovery-Strategie implementieren, sodass der Datensatz beim nächsten Lauf sicher wieder indexiert werden kann.

### Einzelne Nachricht beschädigt

Nicht das gesamte Archiv unbrauchbar machen.

Nachricht als fehlerhaft markieren, Exchange-Version erhalten und nächsten Lauf ermöglichen.

---

# 33. Keine Datenverluste

Dies ist die wichtigste fachliche Invariante:

> **Eine Mail darf niemals ausschließlich deshalb aus Exchange gelöscht werden, weil der Archivierungsprozess glaubt, sie gespeichert zu haben.**

Es muss eine nachweisbare Kette geben:

```text
Exchange
   ↓
vollständig gelesen
   ↓
Archiv geschrieben
   ↓
Archiv validiert
   ↓
dauerhaft committed
   ↓
Index persistiert / Recovery möglich
   ↓
erst jetzt Exchange löschen
```

Bei Unsicherheit immer:

```text
Exchange behalten
```

---

# 34. Tests

Erweitere die vorhandenen Unit-Tests.

Die bestehende Testinfrastruktur verwendet insbesondere:

* `RecordingExchangeTransport`
* SQLite
* `orvantaPdo()`
* `orvantaConfig()`
* `orvantaExchange()`

und wird über

```bash
php tests/run.php
```

ausgeführt.

Mindestens folgende Tests implementieren:

## Konfiguration

* Archiv standardmäßig korrekt konfiguriert
* Schwelle validiert
* Alter 60 Tage
* ungültige Werte abgelehnt

## Auswahl

* Nachrichten älter als 60 Tage werden ausgewählt
* Nachrichten jünger als 60 Tage nicht
* Grenzdatum korrekt
* verschiedene Ordner
* Unterordner
* gesendete Nachrichten

## Archivformat

* Archiv lässt sich erzeugen
* Archiv lässt sich wieder öffnen
* Formatversion
* Manifest
* Checksummen
* Kompression

## Crash Recovery

Simuliere Abbruch:

* während Schreiben
* nach Schreiben
* während Verify
* nach Verify
* vor Commit
* nach Commit
* während Indexierung
* vor Exchange-Löschung
* nach Exchange-Löschung

Der nächste Lauf muss den Zustand korrekt behandeln.

## Keine Duplikate

Archivierung derselben Nachricht zweimal darf keine zweite Kopie erzeugen.

## Exchange-Löschung

Prüfen:

```text
Archivierung erfolgreich → löschen erlaubt
Archivierung fehlgeschlagen → löschen verboten
Checksumme falsch → löschen verboten
Nextcloud-Upload fehlgeschlagen → löschen verboten
```

## Suche

* Exchange-Treffer
* Archiv-Treffer
* gemischte Treffer
* Volltext
* Betreff
* Absender
* Ordner
* Datum

## Abruf

* archivierte Mail lesen
* HTML
* Text
* Anhänge
* beschädigte Daten erkennen
* falsche Benutzer-ID ablehnen

## Ordner

* Systemordner
* eigene Ordner
* Unterordner
* gleiche Namen in verschiedenen Pfaden

## Wiederaufnahme

Ein unterbrochener Job wird beim nächsten Lauf fortgesetzt.

## Lock

Zwei Jobs für dasselbe Postfach dürfen nicht gleichzeitig laufen.

## Große Datenmengen

Sicherstellen, dass Verarbeitung batchweise erfolgt und nicht das gesamte Archiv in den RAM geladen wird.

---

# 35. Integritätstest des gesamten Archivs

Implementiere zusätzlich einen Test:

```text
1000 Nachrichten
+ Anhänge
↓
Archiv
↓
Archiv schließen
↓
Archiv erneut öffnen
↓
alle 1000 Nachrichten lesen
↓
Hash prüfen
↓
alle Daten identisch
```

Außerdem:

```text
Archiv beschädigen
↓
Öffnen
↓
Integritätsfehler muss erkannt werden
```

---

# 36. Dokumentation

Aktualisiere:

* `orvanta-referenz.md`
* `docs/orvanta.md`
* gegebenenfalls `agentsindex.md`

Dokumentiere:

* Archivformat
* Datenmodell
* Worker
* Zustandsautomat
* Recovery
* Speicherort
* Suchindex
* Konfiguration
* API
* Sicherheitsmodell
* Archivintegrität
* Migrationen
* Tests

---

# 37. Vorgehensweise des Coding-Agenten

Arbeite in dieser Reihenfolge:

## Phase 1 – Analyse

1. Lies `orvanta-referenz.md` vollständig.
2. Untersuche den tatsächlichen Code.
3. Identifiziere:

   * EWS-Mailabfragen
   * MIME-Zugriff
   * Exchange-Löschlogik
   * Postfachgrößenberechnung
   * Nextcloud-Integration
   * Repository
   * Migrationen
   * Konfiguration
   * Cron/Worker/Scheduler
   * Frontend-Mailansicht
   * Suchfunktion
4. Erstelle zunächst einen kurzen technischen Implementierungsplan.

**Noch keinen Code ändern, bevor die Architektur verstanden ist.**

## Phase 2 – Architektur

Definiere:

* Archivformat
* Datenmodell
* Zustandsautomat
* Commit-Protokoll
* Recovery-Verhalten
* Locking
* Index
* Nextcloud-Speicherung
* Worker
* EWS-Operationen

Achte besonders auf die Frage:

> Was passiert exakt, wenn der Prozess zwischen zwei beliebigen Instruktionen abstürzt?

Für jeden Zustand muss klar sein, ob die Nachricht noch in Exchange bleiben muss.

## Phase 3 – Implementierung

Dann implementieren:

1. Migrationen
2. Repository
3. Archivformat
4. Archivservice
5. EWS-Erweiterungen
6. Nextcloud-Storage
7. Worker
8. Konfiguration
9. API
10. Suche
11. Frontend
12. Benachrichtigung
13. Demo-Transport
14. Tests
15. Dokumentation

---

# 38. Bestehende Architektur respektieren

Die folgenden Regeln sind verbindlich:

* Keine neue parallele Exchange-Abstraktion.
* Keine direkte SOAP-Kommunikation außerhalb `OrvantaExchangeService`.
* Keine neue Datenbankabstraktion neben dem bestehenden Repository.
* Keine zweite Nextcloud-Clientimplementierung ohne zwingenden Grund.
* Keine Änderung der bestehenden Authentifizierungslogik.
* Keine Umgehung von `authorize()`.
* Keine Benutzer-/Mailbox-ID aus dem Request als Vertrauensquelle.
* Keine Änderung der bestehenden CSP-Sicherheitsregeln.
* Keine Inline-Styles im Frontend.
* Kein Build-System nur für diese Funktion einführen.
* Keine proprietäre Suchmaschine als externe Pflichtabhängigkeit, wenn die bestehende Infrastruktur eine robuste Lösung erlaubt.

Die bestehenden Invarianten sind nicht optional.

---

# 39. Definition of Done

Die Aufgabe ist erst abgeschlossen, wenn alle folgenden Punkte erfüllt sind:

* [ ] Administrator kann Archivierung konfigurieren.
* [ ] Schwelle für Postfachbelegung ist konfigurierbar.
* [ ] Standardalter beträgt 60 Tage.
* [ ] Überschreitung der Schwelle startet automatische Archivierung.
* [ ] Benutzer muss nichts bestätigen.
* [ ] Gesendete und empfangene Mails werden archiviert.
* [ ] Eigene Exchange-Ordner und Unterordner bleiben erhalten.
* [ ] Anhänge werden vollständig archiviert.
* [ ] Archiv ist komprimiert.
* [ ] Archiv ist versioniert.
* [ ] Archiv verfügt über Integritätsprüfungen.
* [ ] Abgebrochene Schreibvorgänge können sicher erkannt und fortgesetzt werden.
* [ ] Keine Nachricht wird vor erfolgreichem Archiv-Commit aus Exchange gelöscht.
* [ ] Archiv wird bei späteren Läufen fortgesetzt.
* [ ] Keine Duplikate.
* [ ] Archiv liegt dauerhaft in Nextcloud.
* [ ] Archiv zählt nicht zum normalen Cache-Quota.
* [ ] Suche durchsucht Exchange und Archiv.
* [ ] Archivsuche verwendet einen Index.
* [ ] Archivnachrichten sind in der UI gekennzeichnet.
* [ ] Langsamer Archivabruf wird visualisiert.
* [ ] Archivanhänge können geöffnet werden.
* [ ] Zugriff ist benutzergebunden.
* [ ] Parallel laufende Jobs für dasselbe Postfach werden verhindert.
* [ ] Worker kann nach Absturz fortgesetzt werden.
* [ ] Fehler führen niemals zu stillem Datenverlust.
* [ ] Demo-Modus funktioniert.
* [ ] Unit-Tests vorhanden.
* [ ] Crash-Recovery-Tests vorhanden.
* [ ] Integritätstests vorhanden.
* [ ] MySQL-/SQLite-Kompatibilität der Repository-SQLs berücksichtigt.
* [ ] `php tests/run.php` läuft erfolgreich.
* [ ] PHP-Syntaxprüfung läuft erfolgreich.
* [ ] Dokumentation aktualisiert.

---

# 40. Abschlussbericht des Coding-Agenten

Nach der Implementierung liefere einen kompakten Abschlussbericht mit:

1. geänderten Dateien,
2. neuen Dateien,
3. Datenbankmigrationen,
4. Archivformat,
5. Recovery-/Commit-Strategie,
6. Worker-/Scheduler-Integration,
7. Suchindex,
8. Sicherheitsmaßnahmen,
9. Tests,
10. ausgeführten Testkommandos und deren Ergebnis,
11. bekannten Einschränkungen.

Besonders erläutern:

> **Warum kann unter keinem der implementierten Absturzszenarien eine Mail aus Exchange verschwinden, ohne dauerhaft im Archiv vorhanden und validiert zu sein?**

Wenn diese Frage nicht eindeutig beantwortet werden kann, ist die Implementierung noch nicht fertig.
