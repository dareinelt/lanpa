# Speicher-Tiering und HA-Synchronisation (Nextcloud + Euro-Office)

Die Daten von Nextcloud und Euro-Office liegen nicht mehr nur im Volume des
jeweiligen Containers. Im Adminbereich unter **Speicher (HA)**
(`/admin/speicher-ha`) werden SMB-Freigaben per UNC-Pfad und/oder Buckets
S3-kompatibler Objektspeicher (MinIO, Ceph RGW, NetApp StorageGRID, AWS S3,
Wasabi …) als Speicherziele eingebunden. Jedes
Speicherziel enthält eine vollständige Kopie aller Daten. Der Container
`storage-sync` übernimmt Einbindung, Synchronisation, Auslagerung und
Rückholung.

| Begriff | Bedeutung |
| --- | --- |
| **Hot-Tier (lokales Storage)** | Volumes `nextcloud_data` und `eurooffice_data` auf dem Docker-Host. Cache für häufig und kürzlich genutzte Dateien. |
| **Cold-Tier (SMB-/S3-Tier)** | Alle eingetragenen Speicherziele, SMB-Freigaben und S3-Buckets beliebig gemischt. Jedes Ziel hält den **vollständigen** Datenbestand. |

Dieses Dokument beschreibt Einrichtung und Betrieb. Die technische Referenz
für Entwickler und Coding-Agenten (Code-Landkarte, Prozesse, Datenformate,
Algorithmen, Invarianten, Tests, Änderungsrezepte, Fehlersuche) steht in
[docs/storage-referenz.md](storage-referenz.md).

---

## 1. Einrichtung

Voraussetzung ist das Profil `office` ([docs/office.md](office.md)).

```bash
docker compose --profile office up -d --build storage-sync
```

1. Adminbereich → **Speicher (HA)** → **Speicherziel hinzufügen**.
2. Bezeichnung und **Art** des Ziels wählen:
   - **SMB-Freigabe:** UNC-Pfad (`\\server\freigabe\pfad`), Benutzername,
     Domäne, Kennwort und SMB-Version (automatisch, 3.1.1, 3.0, 2.1).
   - **S3-kompatibler Objektspeicher:** siehe [Abschnitt 1a](#1a-s3-kompatible-objektspeicher).

   Ein Ziel kann als **primär** markiert werden. Von dort holt der Dienst
   Dateien bevorzugt zurück.
3. Weitere Ziele (zweites NAS, anderer Standort …) genauso hinzufügen. Jedes
   aktive Ziel erhält eine vollständige Kopie.
4. Unter **Einstellungen** „Daten im Cold-Tier (SMB-/S3-Tier) ablegen“ und
   „Speicher-Tiering: selten genutzte Dateien aus dem Hot-Tier auslagern“
   setzen.

Beim ersten Einbinden legt `storage-sync` im Ziel die Datei
`.lanpa-storage.json` mit der Instanz-ID dieser Installation an. Enthält eine
Freigabe bereits Daten einer anderen Installation, wird sie nicht beschrieben.
So überschreiben sich zwei Installationen nicht gegenseitig.

Aufbau eines Speicherziels:

```
\\server\freigabe\pfad\
  .lanpa-storage.json
  nextcloud-data\      Benutzerdateien, Versionen, Papierkorb, appdata
  nextcloud-config\    config.php u. a.
  nextcloud-db\        PostgreSQL-Abzug (pg_dump -Fc), Intervall einstellbar
  eurooffice-data\     Daten des DocumentServers
```

Bei S3-Zielen liegt derselbe Aufbau als Objektschlüssel unter
`<bucket>/<präfix>/`, z. B. `lanpa-cold/intranet/nextcloud-data/…`.

### 1a. S3-kompatible Objektspeicher

`storage-sync` bindet einen Bucket per [s3fs](https://github.com/s3fs-fuse/s3fs-fuse)
(FUSE) unter `/mnt/targets/<id>` ein. Synchronisation, Rückholung,
Vorfallschutz (schreibgeschütztes Einbinden) und Wiederherstellung arbeiten
dadurch genau wie bei SMB-Zielen.

| Feld | Bedeutung |
| --- | --- |
| Endpunkt | `https://host[:port]`, z. B. `https://s3.eu-central-1.amazonaws.com` oder `https://minio.firma.local:9000`. Nur Schema, Host und Port. |
| Region | Signaturregion, z. B. `eu-central-1`. Bei MinIO/Ceph meist `us-east-1` oder leer. |
| Bucket | Bestehender Bucket (wird nicht angelegt). |
| Präfix | Optionaler Unterordner im Bucket, z. B. `intranet/prod`. So können mehrere Installationen einen Bucket teilen. |
| Kapazität (GB) | Optionales Kontingent für Füllstand, Hochrechnung und SNMP. `0` = ohne Grenze. |
| Access Key ID / Secret Access Key | Zugangsdaten. Das Secret wird verschlüsselt gespeichert und beim Bearbeiten nur bei Eingabe ersetzt. |
| Pfad-Adressierung | `https://host/bucket` statt `https://bucket.host` – für MinIO, Ceph und die meisten lokalen Objektspeicher aktiv lassen. |
| TLS-Zertifikat prüfen | Nur für Tests mit selbstsignierten Zertifikaten abschalten. |

Hinweise:

- Ein Objektspeicher meldet keine Größe. Der Füllstand ergibt sich aus der
  synchronisierten Datenmenge und der eingetragenen Kapazität. Ohne Kapazität
  zeigt der Adminbereich „Objektspeicher ohne Kapazitätsgrenze“ und
  `storage_cold_fill` wertet das Ziel nicht als „voll“.
- Die Erreichbarkeit prüft `monitor` per Verzeichnisauflistung (ein
  `ListObjects` je Prüfzyklus). MB/s und IOPS stammen aus den Zählern des
  Agenten statt aus der CIFS-Statistik.
- Der Container benötigt `/dev/fuse`. Der Entrypoint legt das Gerät an; die
  Freigabe erfolgt in `docker-compose.yml` über
  `device_cgroup_rules: ["c 10:229 rwm"]`. Ohne FUSE-Modul auf dem Host
  starten SMB-Ziele weiterhin, nur S3-Ziele melden einen Fehler.
- Uploads werden in `/var/lib/storage-sync/s3-tmp` (Volume
  `storage_sync_state`) zwischengespeichert. Dort muss Platz für die größte
  Einzeldatei sein.
- Mindestrechte des Schlüssels auf den Bucket bzw. das Präfix:
  `s3:ListBucket`, `s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`.

---

## 2. Architektur

```mermaid
flowchart LR
  NC[nextcloud] -- Dateien --> HOT[(Hot-Tier<br>nextcloud_data<br>eurooffice_data)]
  EO[eurooffice] --> HOT
  NC -- Rückhol-Auftrag --> T[(storage_tiering)]
  SS[storage-sync] -- inotify + Vollabgleich --> HOT
  SS <-- Aufträge / Status --> T
  SS -- mount.cifs --> C1[(Cold-Tier: SMB-Ziel)]
  SS -- s3fs / HTTPS --> C2[(Cold-Tier: S3-Bucket …)]
  SS -- Messwerte, Status --> DB[(MySQL intranet)]
  ADM[Adminbereich] --> DB
  SNMP[snmp] -- docker exec --> APP[app: scripts/storage_status.php] --> DB
```

`storage-sync` startet drei Prozesse. Endet einer davon, startet Docker den
Container neu, und der Healthcheck schlägt fehl.

| Prozess | Aufgabe |
| --- | --- |
| `monitor` | Bindet die Ziele ein bzw. neu ein und misst Füllstand, MB/s und IOPS (Hot-Tier über `/proc/diskstats`, Cold-Tier über die CIFS-Statistik, bei S3 über die Zähler des Agenten). Ermittelt den HA-Status und schreibt Messwerte und Verlauf in die Datenbank. |
| `sync` | Erkennt Änderungen sofort (inotify) und gleicht zusätzlich in festem Abstand vollständig ab. Kopiert neue oder geänderte Dateien auf **alle** aktiven Ziele (temporäre Datei, danach Umbenennen, SHA-256-Prüfung) und übernimmt Umbenennungen und Löschungen. Erstellt den Datenbank-Abzug und lagert Dateien aus. |
| `recall` | Holt ausgelagerte Dateien auf Anforderung von Nextcloud zurück, mehrere parallel, mit Fortschritt. |

Zustand und Katalog liegen im Volume `storage_sync_state` (SQLite).
Auslagerungsmarker und Rückhol-Warteschlange liegen im Volume
`storage_tiering`, das auch in `nextcloud` und `nextcloud-ai-worker` unter
`/var/lib/lanpa-tiering` eingebunden ist.

---

## 3. Einstellungen und Regeln des Tierings

| Einstellung | Standard | Wirkung |
| --- | --- | --- |
| Dateien der letzten X Tage im Hot-Tier vorhalten | 30 Tage | Dateien, die in diesem Zeitraum geändert oder geöffnet wurden, bleiben lokal. |
| Häufig genutzt ab | 3 Zugriffstage | Dateien, die innerhalb von 30 Tagen an so vielen Tagen geöffnet wurden, bleiben lokal, auch wenn sie älter sind. |
| Maximale Dateigröße im Hot-Tier | 0 (aus) | Größere Dateien liegen nach der Synchronisation nur im Cold-Tier. |
| Limit des Hot-Tiers gesamt | 0 (automatisch) | Obergrenze für lokal vorgehaltene Daten. `0` = nach freiem Platz des Datenträgers (Auslagerung unter 10 % frei). |
| Speicher-Tiering: selten genutzte Dateien auslagern | ein | Aus = reiner Spiegel, alles bleibt zusätzlich lokal. |
| Vollständiger Abgleich alle | 60 min | Absicherung zusätzlich zu inotify. |
| Datenbank-Abzug von Nextcloud alle | 60 min | `pg_dump` in den Cold-Tier. |
| Warnung bei Rückstand ab | 15 min | Ab hier ist der Status „Rückstand“ (SNMP: WARNING). |
| Füllstand Warnung / kritisch ab | 85 % / 95 % | Gilt für den Hot-Tier und jedes Ziel des Cold-Tiers. |
| Maximale Wartezeit beim Zurückholen | 600 s | So lange wartet Nextcloud auf eine ausgelagerte Datei. |

Ausgelagert werden nur Dateien unter `<benutzer>/files`, `files_versions` und
`files_trashbin`. Konfiguration, `appdata` und die Euro-Office-Daten werden
synchronisiert, bleiben aber immer lokal.

Eine Datei wird nur ausgelagert, wenn

- sie seit mindestens 10 Minuten unverändert ist (laufende Bearbeitungen sind
  geschützt),
- sie mindestens 64 KiB groß ist (kleinere Dateien bleiben immer lokal),
- ihre aktuelle Version auf **allen** aktiven Zielen liegt (im Regelbetrieb),
- und eine Regel greift: älter als X Tage und nicht häufig genutzt, oder
  größer als die maximale Dateigröße.

Die Datei bleibt lokal als „Sparse“-Platzhalter mit gleicher Größe und
gleichem Änderungsdatum erhalten. Sie belegt keinen Platz, und Nextcloud sieht
sie unverändert (Größe, ETag, Freigaben, Suche). Ein Marker in
`storage_tiering/stubs` hält Größe, SHA-256, Version und die Ziele fest, die
sie enthalten.

### Hot-Tier voll: Modus „nur Cold-Tier“

Überschreitet der Hot-Tier das Limit (oder liegt der freie Platz unter 10 %,
mit Limit unter 5 %), wechselt `storage-sync` in den Modus **nur Cold-Tier**:

- Selten genutzte Dateien werden sofort ausgelagert, bis die untere Schwelle
  erreicht ist. Dafür genügt ausnahmsweise **ein** erreichbares Ziel mit
  aktueller Version.
- Neu geschriebene Dateien werden nach der Synchronisation (und der
  Wartezeit von 10 Minuten) ebenfalls ausgelagert.
- Der Adminbereich und das Dashboard zeigen eine Warnung.

Liegt die Belegung wieder unter der unteren Schwelle (mit Limit unter 90 %
des Limits und mindestens 10 % frei, ohne Limit mindestens 15 % frei), kehrt
der Dienst **automatisch** in den Normalbetrieb zurück. Dateien, die nach den
Regeln in den Hot-Tier gehören, werden dann schrittweise zurückgeholt, solange
Platz ist. Die Hysterese verhindert ein ständiges Hin- und Herschalten.

---

## 4. Anzeige im Adminbereich

Die Seite **Speicher (HA)** aktualisiert sich alle 5 Sekunden selbst
(`/admin/speicher-ha/status`, pausiert im Hintergrund-Tab). Sie zeigt:

- **HA-Status:** `ok` (alle Ziele erreichbar und synchron), `degraded`
  (ein Ziel fehlt, Abgleich noch nicht vollständig, Rückstand oder nur ein Ziel), `critical` (kein Ziel
  erreichbar oder `storage-sync` meldet sich nicht), `disabled`.
- **Synchronisation:** `in_sync`, `syncing`, `lagging`, `paused`, `error`,
  `blocked`, mit Anzahl und Größe ausstehender Dateien und Rückstand.
- **Hot-Tier:** Füllstand als Balken, Limit, Modus, MB/s und IOPS (Lesen/Schreiben).
- **Datenbestand:** Dateien gesamt, davon ausgelagert, laufende und
  fehlgeschlagene Rückholungen.
- **Cold-Tier:** je Ziel Zustand, Füllstand, MB/s, IOPS, Synchronität,
  ausstehende Dateien, letzter Fehler sowie Aktionen (Bearbeiten,
  Neu einbinden, Löschen).
- **Ereignisse** von `storage-sync`.

Die Statusübersicht steht über der grafisch verbundenen Hot-/Cold-Tier-Ansicht.
Jedes Speicherziel hat eine eigene Karte mit Füllstandsbalken und Aktionen;
auf schmalen Bildschirmen stehen die Karten untereinander. Die Anzeige
unterstützt das helle und dunkle Design. Einstellungen und Zugangsdaten sind
in beschriftete Gruppen gegliedert.

Auch Zielanzahl, letzter Abgleich, Datenbestand, freie Kapazität,
Fehlermeldungen, Rückstand und Hochrechnung werden live aktualisiert.
Ausfallhinweise und die Freigabe von blockierten Löschungen erscheinen bzw.
verschwinden beim nächsten Statusabruf. Ereignisse zeigen ausdrücklich den
Stand beim Laden und können über **Ereignisse neu laden** aktualisiert werden.
Bei unterbrochener Live-Verbindung bleiben die letzten Werte sichtbar; ein
Hinweis kennzeichnet die Unterbrechung. Ohne JavaScript ist ein Neuladen
für aktuelle Werte erforderlich.

Aktionen: **Jetzt synchronisieren**, **Vollständigen Abgleich starten**,
**Neu einbinden**, **Löschungen übernehmen**.

### Hochrechnung bei Ausfall des Cold-Tiers

Sind keine Ziele erreichbar, kann nichts ausgelagert werden. Neue Daten
bleiben im Hot-Tier. Aus dem Wachstum des gesamten Datenbestands (Hot- und
Cold-Tier, bis 7 Tage) rechnet
der Dienst hoch, wie lange der freie Platz noch reicht, z. B.
„Zuwachs ca. 3,2 GB/Tag – freier Platz reicht noch ca. 4,5 Tage
(bis ca. 12.03.2026 14:00)“. Ist ein Limit gesetzt und wird es früher
erreicht, wird das zusätzlich genannt. Die Meldung erscheint auf der Seite
**Speicher (HA)** und als Hinweis im Dashboard des Adminbereichs. Per SNMP
steht der Wert als `forecast_days` zur Verfügung.

### Schutz vor Massenlöschung

Verschwinden in einem Durchlauf mindestens 1 000 Dateien oder 25 % des
Bestands einer Quelle, etwa durch ein fehlerhaftes Volume oder ein
versehentliches `rm -rf`, übernimmt `storage-sync` diese Löschungen **nicht**.
Der Synchronisationsstatus wechselt auf `blocked` (SNMP: CRITICAL). Erst nach
Prüfung und Klick auf **Löschungen übernehmen** werden sie beim nächsten
vollständigen Abgleich auf die Ziele übertragen. Bis dahin bleiben die Kopien
im Cold-Tier vollständig erhalten.

### Schutz vor Ransomware und verdächtigem Überschreiben (Vorfälle)

`storage-sync` prüft bei jedem Abgleich, ob Dateien auffällig verändert
werden. Ein **Vorfall** entsteht, sobald innerhalb des Zeitfensters
(Standard 10 Minuten) eine der folgenden Regeln je Benutzer greift:

| Regel | Auslöser (Standard) |
| --- | --- |
| Ransomware-Dateiendung | ≥ 1 Datei mit bekannter Endung (`*.makop`, `*.crypt`, `*.locky`, …) |
| Verschlüsselter Inhalt | ≥ 20 überschriebene Dateien, deren Inhalt nicht mehr zum Typ passt (z. B. DOCX ohne ZIP-Kennung) oder sehr hohe Entropie (≥ 7,2 Bit/Byte) hat |
| Massenhaftes Überschreiben | ≥ 200 überschriebene Dateien |

Schwellwerte, Zeitfenster und die Liste der Dateiendungen (Platzhalter `*`,
`?`, z. B. `*.id-*.[*@*].*`) sind unter **Adminbereich → Vorfälle →
Einstellungen** änderbar; „Standardliste wiederherstellen“ setzt die Liste
zurück. Der Verursacher wird über das Schreibprotokoll der Nextcloud-App
`intranet_integration` ermittelt (Kennung, IP-Adresse, Client); fehlt es,
gilt der Eigentümer des Ordners.

**Maßnahmen bei einem Vorfall:**

1. **Benutzer schreibgeschützt:** Der betroffene Benutzer kann Dateien weiter
   öffnen und herunterladen, aber nicht mehr hochladen, ändern, umbenennen
   oder löschen (auch nicht per Desktop-Client/WebDAV). In Nextcloud erscheint
   ein roter Hinweis: „Der Zugriff auf Ihre Dateien wurde aus
   Sicherheitsgründen vorübergehend eingeschränkt. Bitte melden Sie sich beim
   Support.“ (optional mit Support-Kontakt aus den Einstellungen).
2. **Cold-Ziel geschützt** (abschaltbar, siehe unten): Ein Speicherziel des Cold-Tiers wird aus der
   Synchronisation genommen und **nur lesend** eingebunden. So bleibt ein
   unveränderter Stand der Daten erhalten. Gewählt wird das in den
   Einstellungen festgelegte Ziel, sonst ein synchrones, nicht primäres Ziel.
   Der HA-Status zeigt in dieser Zeit „eingeschränkt“.
3. **Meldung im Adminbereich:** Dashboard, Speicher (HA) und der Menüpunkt
   **Vorfälle** (mit Zähler) weisen auf den offenen Vorfall hin.

Unter **Einstellungen → Maßnahmen bei einem Vorfall** ist wählbar, ob
**nur der Benutzer** (auslösender Benutzer bzw. Eigentümer des Ordners)
eingeschränkt wird oder **zusätzlich ein Cold-Ziel** aus dem Sync genommen
wird (Standard, empfohlen; Einstellung `incident_freeze_target`). Bei „nur
Benutzer“ werden alle Speicherziele weiter synchronisiert; ein bei einem
offenen Vorfall bereits geschütztes Ziel wird beim nächsten Durchlauf wieder
freigegeben und synchronisiert.

**Vorfälle-Seite:** Tabelle aller Vorfälle mit Zeitpunkt, Status, Benutzer
(Wer), Regeln und Beispieldateien (Was), Anzahl Dateien/Datenmenge (Wie viel),
Quelle (IP-Adresse, Client), Maßnahmen und Details. Nach Prüfung und
Bereinigung wird ein Vorfall über **Erledigt** und die Rückfrage „Event
wirklich als erledigt markieren?“ → **Ja** abgeschlossen. Danach:

- wird die Einschränkung des Benutzers innerhalb weniger Sekunden aufgehoben
  (sofern kein weiterer offener Vorfall für ihn besteht),
- wird das geschützte Cold-Ziel wieder beschreibbar eingebunden und die
  Synchronisation fortgesetzt, sobald kein Vorfall mehr offen ist. Dabei wird
  der aktuelle Stand übertragen – Daten, die vom geschützten Ziel
  wiederhergestellt werden sollen, vorher sichern (siehe Abschnitt 7).

Aktivität vor dem Zeitpunkt der Erledigung löst keinen neuen Vorfall aus.
Die Erkennung arbeitet nur bei eingeschaltetem Speicher-Tiering.

Technik: Die Nextcloud-App schreibt Schreibzugriffe nach
`access/writes.log` (JSON-Zeilen, max. 64 MB); `storage-sync` veröffentlicht
eingeschränkte Kennungen in `config.json` (`restricted`,
`restriction_message`). Vorfälle liegen in der Tabelle `storage_incidents`.

---

## 5. Zurückholen in Nextcloud (Fortschrittsbalken)

Die Nextcloud-App `intranet_integration` hängt einen Storage-Wrapper vor den
lokalen Datenspeicher:

- **Öffnen, Herunterladen, Kopieren** einer ausgelagerten Datei legt einen
  Rückhol-Auftrag an und wartet (höchstens „Maximale Wartezeit beim
  Zurückholen“). `storage-sync` kopiert die Datei vom primären bzw. einem
  erreichbaren Ziel zurück, prüft den SHA-256 und ersetzt den Platzhalter
  atomar.
- **Fortschritt:** In der Nextcloud-Oberfläche erscheint unten rechts ein
  Hinweis mit Fortschrittsbalken je Datei (wartend, läuft mit Prozent,
  abgeschlossen, fehlgeschlagen). Quelle ist `GET
  /office/apps/intranet_integration/api/recall` (nur eigene Rückholungen).
- **Überschreiben** einer ausgelagerten Datei (Upload, Bearbeitung in
  Euro-Office) braucht keine Rückholung. Der Marker entfällt, die neue
  Version wird normal synchronisiert.
- **Umbenennen, Verschieben, Löschen, Papierkorb** arbeiten direkt auf dem
  Platzhalter und verschieben bzw. entfernen den Marker mit.
- **Vorschaubilder** lösen keine Rückholung aus. Nextcloud zeigt für
  ausgelagerte Dateien das Standardsymbol, bis die Datei einmal geöffnet
  wurde.
- Ist kein Ziel erreichbar, `storage-sync` gestoppt oder die Wartezeit
  überschritten, antwortet Nextcloud mit „Speicher nicht verfügbar“
  (WebDAV 503). Desktop- und Mobil-Clients versuchen es später erneut. Es
  werden nie leere oder unvollständige Dateien ausgeliefert.

---

## 6. Überwachung per SNMP

Die Werte stammen aus derselben Logik wie der Adminbereich. Der
`snmp`-Container ruft `scripts/storage_status.php` im `app`-Container auf.

| Index | Prüfung | Inhalt |
| --- | --- | --- |
| 13 | `storage_ha` | HA-Status, Exit 0/1/2/3 |
| 14 | `storage_sync` | Sync-Status mit ausstehenden Dateien und Rückstand |
| 15 | `storage_hot_fill` | Füllstand Hot-Tier (lokales Storage) in % (Volume oder Limit, der höhere Wert) und Modus |
| 16 | `storage_cold_fill` | Füllstand Cold-Tier (SMB-/S3-Tier): höchster Füllstand der erreichbaren Ziele, Anzahl nicht erreichbarer Ziele |

OIDs: `extResult` `.1.3.6.1.4.1.2021.8.1.100.<Index>`, `extOutput`
`.1.3.6.1.4.1.2021.8.1.101.<Index>`.

Zusätzlich gibt es zwei Einträge in `NET-SNMP-EXTEND-MIB::nsExtendOutLine`
mit mehreren Zeilen:

- **`storage_metrics`** liefert eine Zeile `schlüssel=wert` je Messwert:
  `ha_state`, `sync_state`, `mode`, `targets_active`, `targets_online`,
  `hot_fill_percent`, `hot_total_bytes`, `hot_used_bytes`, `hot_free_bytes`,
  `hot_limit_bytes`, `hot_data_bytes`, `hot_read_bps`, `hot_write_bps`,
  `hot_read_mbps`, `hot_write_mbps`, `hot_read_iops`, `hot_write_iops`,
  `cold_fill_percent`, `cold_total_bytes`, `cold_free_bytes`,
  `cold_read_bps`, `cold_write_bps`, `cold_read_mbps`, `cold_write_mbps`,
  `cold_read_iops`, `cold_write_iops`, `files_total`, `bytes_total`,
  `files_evicted`, `bytes_evicted`, `pending_files`, `pending_bytes`,
  `lag_seconds`, `recalls_active`, `recalls_total`, `recalls_failed`,
  `forecast_days` (`-1` = keine Hochrechnung).
- **`storage_targets`** liefert eine Zeile je Ziel: `id`, `label`, `state`,
  `active`, `primary`, `fill_percent`, `total_bytes`, `free_bytes`,
  `read_mbps`, `write_mbps`, `read_iops`, `write_iops`, `in_sync`,
  `pending_files`, `lag_seconds`, `kind` (`smb` oder `s3`).

```bash
snmpget  -v2c -c public localhost .1.3.6.1.4.1.2021.8.1.100.13 .1.3.6.1.4.1.2021.8.1.101.13
snmpwalk -v2c -c public localhost 'NET-SNMP-EXTEND-MIB::nsExtendOutLine."storage_metrics"'
snmpwalk -v2c -c public localhost 'NET-SNMP-EXTEND-MIB::nsExtendOutLine."storage_targets"'
```

Ist das Tiering nicht aktiviert oder Office nicht bereitgestellt, liefern die
Einträge `UNKNOWN` (3).

---

## 7. Sicherung und Wiederherstellung

### Sicherung (office-backup)

`office-backup` sichert das Nextcloud-Datenverzeichnis als „Sparse“-Archiv.
Ausgelagerte Platzhalter belegen darin keinen Platz. Die Marker
(`storage-tiering.tar`) werden mitgesichert. Nach einer Wiederherstellung aus
dem Archiv holt `storage-sync` ausgelagerte Dateien daher wie gewohnt aus dem
Cold-Tier. Die vollständigen Daten liegen ausschließlich im Cold-Tier. Die
Ziele sind die eigentliche Datensicherung und sollten selbst gesichert bzw.
mit Snapshots versehen werden.

### Wiederherstellung aus einem Speicherziel

Fällt der Docker-Host aus, lässt sich eine neue Installation vollständig aus
einem Ziel wiederherstellen:

```bash
./scripts/storage-restore.sh            # Ziele anzeigen
./scripts/storage-restore.sh 2          # aus Ziel 2 wiederherstellen (Platzhalter, nur Konfiguration lokal)
./scripts/storage-restore.sh 2 --full   # alle Dateien vollständig lokal zurückholen
```

Ablauf:

1. Nextcloud, Cron, KI-Worker und Euro-Office werden gestoppt, die
   Synchronisation bleibt angehalten.
2. Dateien und Konfiguration werden vom Ziel übernommen.
3. Der PostgreSQL-Abzug wird eingespielt.
4. Die Synchronisation wird fortgesetzt (`storage_sync.php resume`) und der
   Stack gestartet.

Ohne `--full` legt der Dienst Platzhalter an, die bei Bedarf zurückgeholt
werden. So ist die Installation schnell wieder nutzbar. Danach empfiehlt sich
`docker compose exec -u www-data nextcloud php occ files:scan --all`.
Auf einer neu aufgesetzten Installation das Speicherziel zuerst im
Adminbereich mit Zugangsdaten eintragen. Es wird dort als „ungültig“ (andere
Installation) angezeigt. Die Wiederherstellung bindet es trotzdem ein und
übernimmt die Instanz-ID aus `.lanpa-storage.json`. Danach gehören alle Ziele
wieder zu dieser Installation.

---

## 8. Sicherheit

- Nur `storage-sync` erhält `CAP_SYS_ADMIN` und `CAP_DAC_READ_SEARCH` (für
  `mount.cifs`) sowie Zugriff auf `/dev/fuse` (für `s3fs`). Nextcloud,
  Euro-Office und `app` bleiben unverändert.
- Kennwörter der Ziele werden verschlüsselt (Schlüssel `storage/keys/secrets.key`,
  wie die AD-Kennwörter) in `storage_targets` gespeichert und nie an den
  Browser zurückgegeben. Für
  `mount.cifs` bzw. `s3fs` entsteht eine Zugangsdatei mit Rechten `0600` unter
  `/run/storage-sync` (tmpfs, nur im Arbeitsspeicher des Containers), die beim
  Aushängen gelöscht wird.
- Der Rückhol-Status in Nextcloud zeigt jedem Benutzer nur seine eigenen
  Aufträge.
- Für die Freigaben ein eigenes Dienstkonto mit Schreibrechten nur auf den
  Zielpfad verwenden. SMB 3 mit Verschlüsselung wird empfohlen.
- Für S3 je Bucket einen eigenen Schlüssel mit den Mindestrechten aus
  Abschnitt 1a verwenden und den Endpunkt per HTTPS ansprechen. Versionierung
  bzw. Object Lock im Bucket schützt zusätzlich vor Ransomware, weil auch ein
  kompromittierter Schlüssel ältere Versionen nicht endgültig löschen kann.

---

## 9. Grenzen und Hinweise

- Der Hot-Tier benötigt ein Dateisystem mit Sparse-Dateien (ext4, xfs, btrfs
  …). Ohne diese Unterstützung wird nur gespiegelt, nicht ausgelagert.
- Ausgelagert werden nur **Nextcloud-Daten**. Euro-Office-Daten,
  Konfiguration und Datenbank bleiben lokal; Euro-Office-Daten und
  Konfiguration zählen aber zum Füllstand des Hot-Tiers (Limit).
- Änderungen direkt im Cold-Tier (am NAS bzw. im Bucket) werden nicht
  zurücksynchronisiert. Der Hot-Tier ist führend.
- S3 kennt kein Umbenennen: Umbenennungen und Verschiebungen werden im Bucket
  als Kopieren und Löschen ausgeführt und dauern bei großen Ordnern länger.
  Jeder Zugriff ist eine Anfrage; bei Cloud-Anbietern fallen ggf.
  Anfrage- und Egress-Kosten an (insbesondere beim Zurückholen).
- Mit Versionierung im Bucket bleiben gelöschte und überschriebene Objekte
  als ältere Versionen erhalten. Eine Lebenszyklusregel sollte diese nach
  einer Frist entfernen, sonst wächst der Speicherverbrauch stetig.
- Die erste Synchronisation eines großen Bestands dauert entsprechend der
  Bandbreite. Der Fortschritt ist unter „Synchronisation“ sichtbar.

---

## Vorteile des Storage-Tierings

- **Daten außerhalb der VM:** Nextcloud- und Euro-Office-Daten liegen
  vollständig im Cold-Tier (SMB-/S3-Tier) und überstehen den Ausfall des
  Docker-Hosts oder seiner Volumes.
- **Hochverfügbarkeit durch mehrere Ziele:** Jedes Speicherziel enthält eine
  vollständige Kopie. Fällt ein NAS aus, bleiben alle Daten verfügbar und
  werden später automatisch nachgezogen.
- **Kleiner, schneller Hot-Tier:** Nur häufig und kürzlich genutzte Dateien
  belegen lokalen (SSD-)Speicher. Der Datenbestand kann weit über die Größe
  der VM hinauswachsen.
- **Kein Datenverlust bei vollem lokalen Speicher:** Statt mit „Datenträger
  voll“ abzubrechen, schaltet der Dienst in den Modus „nur Cold-Tier“ und
  kehrt automatisch zurück, sobald wieder Platz ist.
- **Transparent für Benutzer:** Ordner, Freigaben, Suche und Versionen
  bleiben unverändert. Ausgelagerte Dateien öffnen sich mit sichtbarem
  Fortschrittsbalken.
- **Frühwarnung:** Füllstand, MB/s, IOPS, HA- und Sync-Status sowie die
  Hochrechnung der Restlaufzeit stehen im Adminbereich und per SNMP für das
  bestehende Monitoring bereit.
- **Schutz vor Fehlbedienung:** Massenlöschungen werden erst nach Bestätigung
  übertragen. Jede Kopie wird per SHA-256 geprüft.
- **Schnelle Wiederherstellung:** Eine neue Installation ist aus einem
  einzigen Speicherziel mit einem Befehl wiederhergestellt, auf Wunsch zuerst
  nur mit Platzhaltern.
- **Freie Wahl des Speichers:** SMB-Freigaben und S3-Buckets lassen sich
  beliebig kombinieren, z. B. ein NAS im Haus als schnelles primäres Ziel und
  ein Objektspeicher an einem zweiten Standort oder in der Cloud.
- **Kleinere Sicherungen:** Ausgelagerte Dateien belegen im
  `office-backup`-Archiv keinen Platz. Die vollständigen Daten liegen ohnehin
  redundant im Cold-Tier.

### Vorteile S3-kompatibler Objektspeicher als Speicherziel

- **Externe Kopie ohne VPN-Freigaben:** S3 läuft über HTTPS (Port 443). Ein
  Ziel an einem zweiten Standort, beim Rechenzentrumsdienstleister oder in
  der Cloud ist ohne SMB-Ports durch Firewalls erreichbar und erfüllt die
  3-2-1-Regel (eine Kopie außer Haus).
- **Praktisch unbegrenzt skalierbar:** Kein Volume, das voll laufen kann;
  der Bucket wächst mit dem Datenbestand. Eine optionale Kapazität dient nur
  der Überwachung bzw. dem Kostenrahmen.
- **Schutz vor Ransomware und Fehlbedienung:** Versionierung und Object Lock
  (WORM) halten frühere Stände unveränderlich vor – zusätzlich zum
  Vorfallschutz von `storage-sync`, der das Ziel bei Verdacht schreibgeschützt
  einbindet.
- **Hohe Haltbarkeit:** Objektspeicher verteilen Daten per Erasure Coding oder
  Replikation über mehrere Platten, Knoten oder Rechenzentren.
- **Günstige Speicherklassen:** Selten genutzte Daten – genau die, die der
  Cold-Tier hält – lassen sich kostengünstig ablegen.
- **Herstellerunabhängig:** Die S3-API wird von MinIO, Ceph RGW, NetApp
  StorageGRID, Dell ECS, TrueNAS, Synology/QNAP, AWS, Wasabi, Hetzner, IONOS
  u. v. m. angeboten. Ein Mix aus SMB und S3 verteilt das Risiko auf
  unterschiedliche Systeme und Medien.
- **Kein Domänenkonto nötig:** Zugriff über einen eng berechtigten
  Schlüssel je Bucket statt über ein Windows-/AD-Dienstkonto.
