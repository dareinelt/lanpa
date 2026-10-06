# Datenbank-Performance-Bericht: 1500 Mitarbeitende + 750 Clients

**Auftrag:** Analyse der Datenbankstruktur bezüglich Performance bei gleichzeitigem Zugriff von
1500 Mitarbeitenden und 750 Clients.
**Status:** Analyse. Die vier P1-Maßnahmen sind inzwischen umgesetzt – Begründung und Abwägung
stehen in [`db-performance-p1-umsetzungsplan.md`](db-performance-p1-umsetzungsplan.md).
**Stand:** Branch `dareinelt-db-performance-analyse`.

---

## 1. Methodik

Ausgewertet wurden:

- alle 42 Migrationen in `database/migrations/` (Schema, Indizes, Constraints),
- der Request-Pfad `public/index.php` → `Router` → Controller → Service → Repository → PDO,
- alle relevanten Repositories und Services (`Navigation`, `Phonebook`, `Click`, `Settings`,
  `AdGroup`, `OrvantaArchive`, `NetworkDrive`, `MailProxy`, `Storage`, `EmergencyPlan`),
- die Hintergrundprozesse (`sync_worker`, `mail_worker`, `orvanta_archive_worker`, `storage_sync`),
- die Infrastruktur (`docker-compose.yml`, `docker/mysql/my.cnf`, `docker/php/*`, `.env.example`),
- die Frontend-Polling-Intervalle in `public/assets/js/`.

Nicht möglich in der Agentenumgebung: Ausführung von `docker compose`/MySQL, `EXPLAIN`-Analysen,
Lasttests. Alle Zahlenangaben sind abgeleitet, nicht gemessen (siehe Abschnitt 9).

---

## 2. Kernbefund

Die Datenbankstruktur ist **nicht** der primäre Engpass. Das Schema ist sauber normalisiert,
konsequent InnoDB/utf8mb4 und verfügt über passende Indizes auf den wichtigsten Such- und
Filterspalten. Die absehbaren Probleme bei 1500 + 750 gleichzeitigen Zugriffen liegen in vier
anderen Bereichen, in dieser Reihenfolge:

1. **Infrastruktur-Limitierung:** `max_connections=100` bei unbegrenzter Apache-Worker-Zahl und
   ohne Connection-Pooling oder persistente PDO-Verbindungen.
2. **Wiederkehrende, vermeidbare Query-Last pro Request** (Settings-Volles lesen, SSO-Telefonbuch-
   Lookup ohne Indexnutzung, Navigationsberechtigungen, Breadcrumb-N+1).
3. **Ein lang laufender Hintergrundjob** (AD-Sync) in einer einzigen Transaktion mit zeilenweisem
   Upsert über ~1500 Datensätze plus Gruppen-Rebuild.
4. **Fehlende Cross-Request-Caches** (Navigation, Einstellungen, Ankündigungen, Notfallnummern,
   Telefonbuch) — jede dieser Ressourcen wird bei jedem Seitenaufruf neu aus MySQL gelesen.

---

## 3. Lastannahmen und Kapazitätsrechnung

### 3.1 Annahmen

| Größe | Annahme |
|---|---|
| Mitarbeitende | 1500, davon ein Teil gleichzeitig aktiv |
| Windows-Clients | 750, melden Netzlaufwerke bei jeder Anmeldung |
| Gleichzeitige Web-Requests (Spitzenlast) | 150–250 |
| Anfragen pro Seitenaufruf | 5–8 SQL-Queries (Landingpage/Unterseite) |
| Apache-Worker | nicht begrenzt konfiguriert |
| MySQL-Verbindungen | **hart auf 100 begrenzt** (`docker/mysql/my.cnf:5`) |
| Persistente Verbindungen | nein — pro Request neuer `PDO`-Aufbau (`app/Core/Database.php:37-46`) |
| Connection-Pooling | nicht vorhanden |

### 3.2 Rechnung

- **Gleichzeitige Verbindungen:** 150–250 parallele Requests × 1 PDO-Verbindung = **150–250
  benötigte Verbindungen**. Zusätzlich halten `mail`, `mail-archive`, `storage-sync` und `snmp`
  eigene Verbindungen. Verfügbar sind 100. → **Überbuchung um Faktor 1,5–2,5 plus
  Hintergrundprozesse.**
- **Verbindungs-Churn:** Ohne `ATTR_PERSISTENT` und ohne Pooling entstehen pro Request
  TCP-Handshake, MySQL-Authentifizierung und Thread-Erzeugung/-Abbau. Bei 150–250 req/s sind das
  150–250 neue Verbindungen pro Sekunde gegen ein Limit von 100 gleichzeitigen.
- **Query-Volumen:** 150–250 req/s × 6 Queries ≈ **900–1500 Queries/s**. Für MySQL mit
  ausreichendem Buffer Pool unkritisch — **sofern** die Queries indexgestützt sind und der Buffer
  Pool groß genug ist.
- **Anmeldesturm (750 Clients):** `POST /sso/laufwerke` läuft je Client einmal. Pro Aufruf werden
  `network_drives` **zweimal vollständig gelesen** (Fingerprint vor und nach dem Schreiben,
  `NetworkDriveService.php:216-218`) und die Benutzerzeilen per DELETE + N×INSERT neu geschrieben.
  Bei ~3750 Zeilen sind das ~7500 gelesene Zeilen pro Client-Meldung, also ~5,6 Mio. Zeilen im
  Anmeldefenster — inklusive Sortierung (`ORDER BY user_uid, drive_letter`) und
  `LOWER(user_uid)`-Vergleich ohne Indexnutzung.
- **Polling:** Notfallplan-Dashboard 10 s (`emergency-plan.js:1754`), KAEP-Dashboard 10 s
  (`kaep-dashboard.js:585-586`), Orvanta-Erinnerungen 60 s, Orvanta-Keepalive 5 min. Jeder
  offene Tab belegt einen Apache-Worker **und** eine DB-Verbindung für die Dauer des Requests.

### 3.3 Fazit der Rechnung

Der begrenzende Faktor ist **nicht die Datenbankstruktur, sondern die Verbindungsanzahl und die
fehlende Wiederverwendung**. Bei 1500 + 750 Clients ist `max_connections=100` der erste harte
Fehlerpunkt (`SQLSTATE[HY000] [1040] Too many connections`), noch bevor Query-Performance
relevant wird.

---

## 4. Schema- und Indexbewertung

### 4.1 Positiv

- Durchgehend `ENGINE=InnoDB`, `utf8mb4`/`utf8mb4_unicode_ci`, `TINYINT(1)`-Booleans, FKs mit
  `ON DELETE SET NULL`/`CASCADE`.
- `phonebook`: `idx_phonebook_last_name (active, last_name)`, `idx_phonebook_first_name`,
  `idx_phonebook_display_name`, `idx_phonebook_department`, `idx_phonebook_phone
  (active, phone_digits)`, `idx_phonebook_source (identity_source_id, active)`,
  `idx_phonebook_source_samaccount (identity_source_id, samaccount_name)`
  (`001_create_core_tables.sql:44-49`, `017_identity_sources.sql:55-56`).
- `navigation_items`: `idx_navigation_active_sort (active, sort_order)`.
- `click_events`: `idx_click_events_time`, `idx_click_events_nav_time (navigation_id, clicked_at)`
  (`001_create_core_tables.sql:57-58`).
- `orvanta_reminders`: `idx_orvanta_reminders_due (user_uid, state, remind_at)`
  (`033_create_orvanta_tables.sql:28`).
- `ad_group_members`: `PRIMARY KEY (group_id, phonebook_id)` + `idx_ad_group_members_user`.
- `network_drives`: `uniq_network_drives_user_letter (user_uid, drive_letter)`.

### 4.2 Schwachstellen

| Befund | Ort | Wirkung |
|---|---|---|
| `LOWER(samaccount_name) = LOWER(:name)` in der SSO-Auflösung | `PhonebookRepository.php:180-190` | Der vorhandene Index `idx_phonebook_source_samaccount` ist nur über das Präfix `identity_source_id` nutzbar; `samaccount_name` wird nicht als Range genutzt. Bei nur einer Identitätsquelle bedeutet das einen Scan über praktisch die gesamte Tabelle — **bei jedem authentifizierten Request** (`SsoAuth::resolveSession`, `SsoAuth.php:208-233`). |
| `LOWER(user_uid) = LOWER(:uid)` beim Löschen von Netzlaufwerken | `NetworkDriveRepository.php:53`, `77` | `uniq_network_drives_user_letter` wird nicht genutzt → Scan. |
| `LOWER(p.group_name) IN (LOWER(:p0), …)` in der Kachel-Berechtigung | `NavigationRepository.php:139`, `103` | `idx_nav_perm_group (group_name)` wird nicht genutzt. Tabelle ist klein, daher moderat — aber es ist die häufigste Query der Anwendung. |
| `NOT IN (SELECT …) OR IN (SELECT …)` über `navigation_item_permissions` | `NavigationRepository.php:146-148` | Erzwingt Materialisierung der Subquery bzw. Semi-Join statt eines einfachen Index-Lookups. Läuft pro Navigationsebene und pro Request. |
| `phone_digits LIKE '%…%'` bei der Telefonnummernsuche | `PhonebookRepository.php:105` | Leading Wildcard → `idx_phonebook_phone` nicht nutzbar; Vollscan der `phonebook`-Tabelle pro Suchanfrage. |
| 7× `LOWER(...) LIKE '%…%'` über TEXT-Spalten der Archivsuche | `OrvantaArchiveRepository.php:~330-345` | Vollscan über `search_text`, `recipients`, `attachment_names`. Kein `FULLTEXT`-Index vorhanden. |
| Keine funktionalen Indizes | — | Für die `LOWER()`-Vergleiche existiert keine `(LOWER(col))`-Indexvariante. |

### 4.3 Hot Rows (Singleton-Zeilen)

| Tabelle | Muster | Bewertung |
|---|---|---|
| `settings` | `INSERT … ON DUPLICATE KEY UPDATE` je Schlüssel (`SettingsRepository.php:32-37`); `setMany()` in einer Transaktion | Im Normalbetrieb nur lesend; Schreibkonflikte nur bei parallelen Admin-Änderungen oder einmaliger Secret-Erzeugung. Niedrig. |
| `mail_proxy_state` | `UPDATE … WHERE id = 1` für `generation`, `last_success_at`, `last_error_at` (`MailProxyRepository.php:502-518`) | Echte Hot Row bei hoher paralleler Mail-Proxy-Nutzung. Mittel. |
| `storage_status` / `storage_snapshot_status` | `INSERT IGNORE … VALUES (1, …)` + `UPDATE` auf Zeile id=1 | Nur durch den Storage-Agent beschrieben. Niedrig. |
| `emergency_password_attempts` | `UPDATE … SET attempts = attempts + 1 WHERE actor = ?` (`EmergencyPlanRepository.php:414-422`) | X-Lock pro Versuch, PK auf `actor`. Nur im Notfallbetrieb relevant. Niedrig, aber im Ernstfall genau dann, wenn Lastspitzen auftreten. |

---

## 5. Query-Hotspots im Request-Pfad

### 5.1 Anfragen pro Seitenaufruf (ermittelt)

| Route | SQL-Queries | Anmerkung |
|---|---:|---|
| Middleware `requireUnlocked` (jeder GET) | 1 | `findActiveInternalByUrl` auf `navigation_items` |
| Landingpage `/` | ~5 Basis + bedingte Zusatzabfragen | Navigation inkl. Berechtigungen (1 set-based Query), wichtige Links, Einstellungen, Ankündigungen; zusätzlich je nach Konfiguration Notfallplan, KAEP, Orvanta-Erinnerungen, Office-Apps |
| Unterseite `/unterseite`, `/seite` | ~7 + d, d = Breadcrumb-Tiefe | **Breadcrumb ist ein echtes N+1** |
| `/telefonliste` | ~6 | inkl. `COUNT(*)` und `MAX(synced_at)` |
| `/api/telefonliste` (Suche) | 2 | `COUNT(*)` + Ergebnismenge; `LIMIT` 25/100 |
| `/api/klick` | 2 | `exists()`-Prüfung + INSERT |
| `/notfallplan/stand` (10-s-Poll) | ~6-8 | `access()` (SSO-Auflösung + `accessLevel()` + `triggerGroup()`) + `requireEvent()` + Middleware |
| `/api/orvanta/erinnerungen` (60-s-Poll) | ~4 | indexgestützt (`idx_orvanta_reminders_due`), kostengünstig |
| `/sso/laufwerke` (Client-Anmeldung) | 2 Vollscans + DELETE + N INSERTs | siehe Abschnitt 6.1 |

### 5.2 Konkrete Einzelbefunde

1. **Breadcrumb-N+1** — `NavigationService::breadcrumb()` (`NavigationService.php:193-208`) lädt in
   einer `while`-Schleife Ebene für Ebene per `find()` nach. Bei Tiefe 4 sind das 5 Round-Trips,
   obwohl die gesamte Kette in einer Query (oder aus einem Cache) verfügbar wäre.

2. **Doppelte SSO-Auflösung** — `Controller::ssoShared()` löst den SSO-Benutzer erneut auf, obwohl
   `LandingController`/`PageController` ihn im selben Request bereits ermittelt haben. Jede
   Auflösung ist ein Telefonbuch-Lookup (siehe 4.2).

3. **Settings werden pro Request vollständig gelesen** — `SettingsService::all()`
   (`SettingsService.php:114-125`) liest die komplette `settings`-Tabelle und merged sie mit dem
   `defaults()`-Array. Der Cache (`private ?array $cache`) gilt **nur request-lokal**; es gibt
   weder APCu noch Redis noch Datei-Cache. Bei 150–250 req/s sind das 150–250 vollständige
   Settings-Reads pro Sekunde.

4. **Keine Cross-Request-Caches** für Navigation, wichtige Links, Ankündigungen, Notfallnummern und
   Telefonbuch-Listen. Diese Daten ändern sich selten, werden aber pro Request neu gelesen.

5. **`COUNT(*)` ohne Notwendigkeit** — Telefonliste und Klick-Statistik führen jeweils ein
   `COUNT(*)` über die gefilterte Menge aus. Bei `click_events` ohne `LIMIT` über die gesamte
   Tabelle (`ClickRepository.php:43-60`).

6. **Statistik-Aggregation** — `GROUP BY DATE(clicked_at), navigation_id` ohne `LIMIT`
   (`ClickRepository.php:20-27`). `DATE(clicked_at)` ist ein Funktionsausdruck auf der
   indizierten Spalte → kein Range-Scan möglich.

7. **Ein INSERT pro Klick** — `click_events` (`ClickRepository.php:7-12`) plus vorgeschalteter
   `exists()`-Check (`StatisticsService.php:118-124`). Kein Zähler-Upsert. Append-only ist
   locktechnisch günstig, erzeugt aber dauerhaftes Tabellenwachstum (Aufbewahrung
   `CLICK_RETENTION_DAYS=400`).

8. **Sessions** — `app/Security/Session.php:27` verwendet `session_start()` mit PHP-Dateisessions
   (`docker/php/php.ini:22`, `session.gc_maxlifetime=3600`). Keine Session-Last in MySQL, aber
   Session-Datei-Locking kann bei parallelen Requests derselben Sitzung serialisieren.

---

## 6. Schreibpfade und Sperren

### 6.1 Netzlaufwerksmeldung `POST /sso/laufwerke` (höchste Client-Schreiblast)

`NetworkDriveService::report()` (`NetworkDriveService.php:172-224`):

```
fingerprint()            -> settings-Read (cached) + vollständiger network_drives-SELECT
replaceForUser()         -> BEGIN; DELETE ... WHERE LOWER(user_uid)=LOWER(:uid); INSERT je Laufwerk; COMMIT
fingerprint()            -> vollständiger network_drives-SELECT erneut
$changed = ...           -> steuert nur den anschließenden Nextcloud-Push
```

Bewertung:

- Das Schreiben erfolgt **auch bei unveränderten Laufwerken** — der Normalfall bei jeder
  Client-Anmeldung. `$changed` verhindert lediglich den Push, nicht den DB-Write.
- `network_drives` wird pro Meldung **zweimal vollständig** gelesen (`ORDER BY user_uid,
  drive_letter` → Sortierung/Temporärtabelle).
- Kein `INSERT … ON DUPLICATE KEY UPDATE`/`REPLACE`, keine Vorabprüfung auf Gleichheit.
- `LOWER(user_uid)` verhindert die Nutzung von `uniq_network_drives_user_letter`.
- Bei 750 Clients entsteht so ein Anmeldesturm aus 750 Schreibtransaktionen und ~1500 Vollscans.

### 6.2 Klicks und Alarme

`click_events` und `alarm_log` sind Append-only (INSERT je Ereignis). Kein gemeinsamer
Zähler-Hot-Row — locktechnisch unkritisch, aber Insert-, Index-, Undo- und Redo-Volumen.

### 6.3 Admin-Login

`password_verify()` mit `PASSWORD_DEFAULT` (bcrypt, Cost 12) blockiert einen PHP-Worker für die
Dauer der Prüfung. Kein MySQL-Lock, aber CPU-/Worker-Bindung. Für Windows-SSO irrelevant.

---

## 7. Hintergrundprozesse

| Job | Intervall | Risiko | Begründung |
|---|---|---|---|
| **AD-Sync** (`sync_worker.php`, Standard 3600 s) | stündlich | **Hoch** | `AdSyncService.php:155-180` speichert eine ganze Identitätsquelle in **einer Transaktion**: zeilenweiser Upsert für ~1500 Benutzer, danach `deactivateStale`, danach `AdGroupRepository::replaceAll()` (Upsert je Gruppe, `DELETE FROM ad_group_members WHERE group_id` je Gruppe, `INSERT IGNORE` in 500er-Chunks, Abschluss-`DELETE … JOIN`). Die Lockdauer entspricht praktisch der gesamten Speicherdauer des Laufs. |
| **Telefonbuch-Import** | manuell | **Hoch** | `PhonebookRepository.php:432-445` führt `DELETE FROM phonebook` **ohne WHERE** aus, gefolgt von Einzel-INSERTs. Bei Online-Ausführung blockiert das die gesamte Tabelle. |
| **Storage-Katalog** (Rebuild/Migration) | manuell | Mittel | `TRUNCATE`/`ALTER TABLE`/große Deletes in `Catalog.php` — betrifft nur die separate `storage_sync`-Datenbank, nicht die Hauptdatenbank. |
| **Orvanta-Archiv** | konfigurierbar | Mittel | Batchverarbeitung mit `LIMIT 500` (`OrvantaArchiveRepository.php:286-303`), aber zeilenweise Statusupdates innerhalb der Batch-Transaktion (`:256-269`). Job-Sperre über `locked_until` (`:385-406`) verhindert Paralleläufe. |
| **Storage-Aufräumvorgänge** | laufend | Niedrig-Mittel | Deletes ohne `LIMIT`, aber mit Zeit-/ID-Bedingung (`StorageRepository.php:281, 298, 341`). |
| **Mail-Queue** | 5 s, Limit 20 | Niedrig | Claim über `status`/`available_at`/`attempts` (`MailQueueService.php:25-59`), kein `FOR UPDATE`, SMTP-Versand außerhalb der Transaktion. |
| **BackupService** | manuell | Niedrig (DB) | Nur SELECTs, kein `mysqldump`, keine Transaktion, kein Tabellenlock. CPU-/I/O-intensiv, aber nicht DB-blockierend. |
| **Klick-Bereinigung** | geplant | Niedrig | `DELETE … WHERE clicked_at < NOW() - INTERVAL :days DAY` (`ClickRepository.php:66-72`). |

**Wichtigster Befund:** Der AD-Sync ist kein „Delete + Neuaufbau" des Telefonbuchs, sondern ein
Upsert-/Soft-Delete-Verfahren. Wegen der Kombination aus ~1500 Einzel-Upserts, einer einzigen
langen Transaktion und dem anschließenden Gruppen-Rebuild ist er trotzdem der wahrscheinlichste
Verursacher spürbarer Sperrzeiten im Onlinebetrieb.

---

## 8. Infrastruktur

### 8.1 Hauptdatenbank

| Einstellung | Wert | Fundstelle |
|---|---|---|
| Image | `mysql:9.7.2` | `docker-compose.yml:73-90` |
| **`max_connections`** | **100** | `docker/mysql/my.cnf:5` |
| `innodb_buffer_pool_size` | **nicht gesetzt** (MySQL-Default) | `docker/mysql/my.cnf` |
| `innodb_io_capacity` | nicht gesetzt (Default 200) | `docker/mysql/my.cnf` |
| `innodb_flush_log_at_trx_commit` | nicht gesetzt (Default 1) | `docker/mysql/my.cnf` |
| `character-set-server` / `collation-server` | `utf8mb4` / `utf8mb4_unicode_ci` | `docker/mysql/my.cnf:2-3` |
| `skip-name-resolve` | aktiv | `docker/mysql/my.cnf:4` |
| Read-Replica / ProxySQL / Pooling | **nicht vorhanden** | — |
| Anwendungs-Redis/APCu/Memcached | **nicht vorhanden** | — |

### 8.2 Webserver

- `FROM php:8.5-apache` (`docker/php/Dockerfile:2`) → **mod_php, kein PHP-FPM**.
  `pm.max_children`/`pm.max_requests` existieren nicht; es gibt keine `www.conf`.
- Eine `app`-Instanz ohne `deploy.replicas`.
- Apache-Worker-Limit nicht explizit konfiguriert → das Basisimage-Default kann die
  100-Verbindungsgrenze von MySQL deutlich überschreiten.
- `max_execution_time=30`, `memory_limit=256M` (`docker/php/php.ini:10-11`).
- OPcache aktiv (`:24-28`), 128 MiB, `validate_timestamps=1`, `revalidate_freq=60`.
- `session.gc_maxlifetime=3600` (`:22`).

### 8.3 Separate Dienste

Nextcloud nutzt PostgreSQL + Redis, der Storage-Katalog eine eigene MySQL-Instanz mit deutlich
besserer Dimensionierung (`docker/storage-sync-catalog/my.cnf:9-34`: `max_connections=64`,
`innodb_flush_log_at_trx_commit=2`, Redo-Log 1 GiB, `O_DIRECT`, `io_capacity` 2000/4000,
`READ-COMMITTED`, `innodb_autoinc_lock_mode=2`, Buffer Pool 512 MiB über
`docker-compose.yml:594`). **Die Hauptdatenbank ist schlechter konfiguriert als die
Neben-Datenbank** — es fehlen die dort bereits erprobten Tuningwerte.

---

## 9. Risikomatrix (priorisiert)

| # | Risiko | Eintrittswahrscheinlichkeit | Auswirkung | Priorität |
|---|---|---|---|---|
| 1 | Verbindungserschöpfung (`max_connections=100` vs. 150–250 parallele Requests, kein Pooling) | hoch | Totalausfall (HTTP 500) | **P1** |
| 2 | Fehlender InnoDB Buffer Pool für die Hauptdatenbank | hoch | I/O-Last, Lock-Wartezeiten, langsame Queries | **P1** |
| 3 | AD-Sync: eine lange Transaktion mit ~1500 Einzel-Upserts + Gruppen-Rebuild | mittel-hoch | Sperren auf `phonebook`/`ad_group_members` während des Laufs | **P1** |
| 4 | `POST /sso/laufwerke` schreibt bei jeder Client-Anmeldung und liest `network_drives` zweimal vollständig | sehr hoch (750×) | Anmeldesturm, Lock- und I/O-Spitze | **P1** |
| 5 | SSO-Telefonbuch-Lookup ohne Indexnutzung (`LOWER(samaccount_name)`) bei jedem Request | hoch | Dauerlast, die mit der Nutzerzahl linear steigt | **P2** |
| 6 | Settings-Vollread pro Request ohne Cross-Request-Cache | sehr hoch | vermeidbare Dauerlast | **P2** |
| 7 | Breadcrumb-N+1 und doppelte SSO-Auflösung | hoch | 2–8 zusätzliche Round-Trips pro Seitenaufruf | **P2** |
| 8 | Telefonnummernsuche mit Leading Wildcard | mittel | Vollscan `phonebook` pro Suchanfrage | **P2** |
| 9 | Archivsuche mit 7 Leading-Wildcard-LIKEs über TEXT-Spalten | mittel | Vollscan, hohe CPU | **P2** |
| 10 | Import-Pfad `DELETE FROM phonebook` ohne WHERE | niedrig (manuell) | Totalblockade, wenn online ausgeführt | **P2** |
| 11 | Klick-Statistik: `GROUP BY DATE(clicked_at)` ohne LIMIT, `COUNT(*)` über Gesamttabelle | mittel | langsame Admin-Statistik, mit 400 Tagen Retention wachsend | **P3** |
| 12 | Hot Row `mail_proxy_state` (id=1) | niedrig-mittel | Lock-Wartezeiten bei hoher Mail-Proxy-Parallelität | **P3** |
| 13 | Polling-Last durch offene Dashboards (10 s) | mittel | belegt Worker + DB-Verbindung | **P3** |
| 14 | `emergency_password_attempts` als Hot Row | niedrig | relevant genau im Notfallbetrieb | **P3** |

---

## 10. Empfehlungen

Alle Empfehlungen sind Vorschläge — **es wurde nichts umgesetzt**. Sie respektieren die
Projektprinzipien (Zero-Dependency, keine Migrationen ändern, nur neue `0NN_*.sql`).

### P1 — zuerst umsetzen (Stabilität unter Spitzenlast)

**P1.1 Verbindungsbudget herstellen**

- `max_connections` der Hauptdatenbank deutlich anheben (z. B. 300–400) **oder** die
  Apache-Worker-Zahl unter das Verbindungslimit begrenzen. Beides ohne Begrenzung ist der
  wahrscheinlichste Totalausfallpfad.
- Apache-Worker explizit dimensionieren (MPM-Konfiguration im `php:8.5-apache`-Image), damit die
  maximale Anzahl gleichzeitiger PHP-Prozesse bekannt und kleiner als `max_connections` ist
  (Reserve für `mail`, `mail-archive`, `storage-sync`, `snmp`, Admin-Sessions).
- Alternativ/ergänzend `PDO::ATTR_PERSISTENT` **prüfen** — reduziert Verbindungs-Churn, hat aber
  eigene Fallstricke (verwaiste Transaktionen, Zustandsreste). Bewusste Einzelentscheidung, nicht
  reflexartig.
- Alle Abfragen im Abschnitt 4.2 senken die Verbindungsbelegungsdauer und wirken daher ebenfalls
  entlastend.

**P1.2 InnoDB Buffer Pool dimensionieren**

- `innodb_buffer_pool_size` in `docker/mysql/my.cnf` explizit setzen (Richtwert: 50–70 % des für
  den DB-Container reservierten RAM; die Arbeitsmenge der Intranet-Datenbank ist klein, einige
  hundert MiB bis wenige GiB genügen).
- Die im Storage-Katalog bereits bewährten Werte prüfen und übernehmen, wo sinnvoll:
  `innodb_io_capacity`, `innodb_redo_log_capacity`, `innodb_flush_method=O_DIRECT`,
  `transaction_isolation=READ-COMMITTED`, `innodb_print_all_deadlocks=ON`.
- `innodb_flush_log_at_trx_commit=2` nur nach bewusster Abwägung (Kompromiss bei
  Crash-Sicherheit) — für dieses Intranet vermutlich vertretbar, aber nicht stillschweigend.
- Zielwert dokumentieren und per `SHOW VARIABLES` / Hit-Rate verifizieren.

**P1.3 AD-Sync entschärfen**

- Transaktion **pro Batch** (z. B. 200 Benutzer) statt pro Identitätsquelle; Fortschritt über
  `sync_log` festhalten, damit ein Abbruch wiederaufsetzbar ist.
- Upserts bündeln: `INSERT … ON DUPLICATE KEY UPDATE` mit Mehrfach-Values (z. B. 200–500 Zeilen pro
  Statement) statt eines Statements pro Benutzer.
- Gruppenmitgliedschaften nur schreiben, wenn sich die Mitgliedermenge tatsächlich geändert hat
  (Vergleich vor `DELETE`/`INSERT`), statt sie je Gruppe unbedingt neu aufzubauen.
- Sync-Fenster außerhalb der Kernarbeitszeit; Laufzeit im `sync_log` messen und protokollieren.

**P1.4 Netzlaufwerksmeldung entlasten**

- Vor dem Schreiben prüfen, ob sich der gemeldete Stand geändert hat (Vergleich gegen die
  vorhandenen Zeilen des Benutzers) und nur dann schreiben. Der Vergleich ist ohnehin schon
  implementiert (`$changed`) — nur die Reihenfolge ist falsch: erst schreiben, dann vergleichen.
- Fingerprint nicht über die gesamte Tabelle bilden, sondern über die Zeilen des gemeldeten
  Benutzers (oder einen zwischengespeicherten Gesamt-Fingerprint), um die zwei Vollscans pro
  Meldung zu vermeiden.
- `LOWER(user_uid)` entfernen, damit `uniq_network_drives_user_letter` greift — die Spalte ist per
  `UID_PATTERN` bereits auf `[a-zA-Z0-9._-]` beschränkt; alternativ Normalisierung beim Schreiben
  plus funktionaler Index.
- Statt DELETE + N×INSERT ein Upsert (`INSERT … ON DUPLICATE KEY UPDATE`) plus gezieltes Löschen
  nicht mehr gemeldeter Buchstaben.

### P2 — Query-Last senken (Dauerlast)

**P2.1 SSO-Lookup indexfähig machen**

- `LOWER(samaccount_name) = LOWER(:name)` durch einen Vergleich ohne Funktionswrapper ersetzen.
  Da `utf8mb4_unicode_ci` bereits case-insensitiv ist, ist `samaccount_name = :name` äquivalent und
  nutzt `idx_phonebook_source_samaccount (identity_source_id, samaccount_name)`.
- Falls die Normalisierung im Code bleiben soll: `samaccount_name` beim Schreiben normalisieren
  (klein) und einen funktionalen Index `((LOWER(samaccount_name)))` ergänzen — neue Migration
  `041_*.sql`.
- SSO-Auflösung **pro Request cachen** (der `Container` ist bereits ein Request-Singleton;
  `SsoAuth::resolve()` hat keinen solchen Cache). Das entfernt zugleich die doppelte Auflösung
  durch `Controller::ssoShared()`.

**P2.2 Settings und seltene Stammdaten cachen**

- `SettingsService::all()` um einen Cross-Request-Cache ergänzen. Ohne neue Abhängigkeit bietet
  sich APCu an (bereits in PHP verfügbar, kein Composer-Paket), mit Invalidierung beim Schreiben
  über `SettingsRepository::set()`/`setMany()`. Falls APCu nicht gewünscht ist: eine
  dateibasierte Cache-Schicht mit kurzer TTL (z. B. 30–60 s) und Invalidierung beim Schreiben.
- Dasselbe Muster für Navigation (inkl. Berechtigungen), wichtige Links, Ankündigungen und
  Notfallnummern — diese Daten ändern sich nur durch Admin-Aktionen, die den Cache invalidieren
  können.
- Damit sinkt die Query-Last pro Request um grob 3–4 von 5–8 Queries.

**P2.3 Navigationsberechtigungen vereinfachen**

- Die Kombination `NOT IN (SELECT …) OR IN (SELECT …)` durch einen `LEFT JOIN` mit
  `GROUP BY`/`EXISTS` ersetzen, damit kein Materialisierungsschritt nötig ist.
- `LOWER(p.group_name) IN (…)` entfernen (Collation ist bereits case-insensitiv) oder auf einen
  funktionalen Index stützen.
- Breadcrumb in **einer** Query per rekursivem CTE (`WITH RECURSIVE`) oder durch einen
  Navigations-Cache auflösen statt `d+1` Einzelabfragen.
- `isAccessible()` (`NavigationRepository.php:81-105`) prüft `exists()` → `hasPermissions()` →
  Berechtigungsmatch, also bis zu 3 Queries. Bei mehrfacher Verwendung pro Request bündeln.

**P2.4 Telefonbuchsuche**

- Die Telefonnummernsuche über `phone_digits LIKE '%…%'` ist strukturell nicht indizierbar.
  Optionen: (a) Suche auf Präfix umstellen, (b) normalisierte Ziffernfolge in einer separaten,
  indexierbaren Spalte mit Präfix-/Suffixsuche, (c) `FULLTEXT` mit `ngram`-Parser für
  Teilstringsuche. Die Namenssuche (Präfix-LIKE) ist bereits in Ordnung.
- `COUNT(*)` nur berechnen, wenn die Gesamtzahl tatsächlich angezeigt wird (oder gecacht).
- `allForAdmin()`/`visibleForExport()` ohne `LIMIT` prüfen — bei 1500+ Einträgen und wachsender
  Soft-Delete-Historie relevant.

**P2.5 Archivsuche**

- `FULLTEXT`-Index mit `ngram`-Parser über `search_text`/`recipients`/`attachment_names`
  (neue Migration) und `MATCH … AGAINST` statt 7 Leading-Wildcard-LIKEs.
- Alternativ serverseitig vorfiltern (Zeitraum, Postfach) und erst dann die LIKEs anwenden.

**P2.6 Importpfad absichern**

- `DELETE FROM phonebook` im Import (`PhonebookRepository.php:444`) vermeiden: auf Upsert +
  `deactivateStale()` umstellen (wie im AD-Sync) oder Import in Batches mit kurzen Transaktionen
  ausführen. Der unbedingte Delete ist bei 1500+ Einträgen ein Totalblockade-Risiko.

### P3 — Optimierung und Betrieb

**P3.1 Klick-Statistik**

- `GROUP BY DATE(clicked_at)` durch eine Range-Bedingung auf `clicked_at` ersetzen
  (`clicked_at >= :from AND clicked_at < :to`), damit `idx_click_events_time` als Range genutzt
  wird.
- Aggregation mit `LIMIT` bzw. seitenweise.
- Optional: Tages-Aggregat-Tabelle statt Live-Aggregation über Rohdaten; bei
  `CLICK_RETENTION_DAYS=400` wächst `click_events` sonst kontinuierlich.

**P3.2 Hot Rows entschärfen**

- `mail_proxy_state`: `last_success_at`/`last_error_at` nicht bei jedem Ereignis schreiben
  (Drosselung, z. B. höchstens einmal pro Minute).
- `emergency_password_attempts`: nur im Fehlerfall schreiben, nicht bei jedem Versuch.

**P3.3 Polling reduzieren**

- Poll-Intervalle an die tatsächliche Dringlichkeit anpassen (Notfallplan/KAEP 10 s → 30–60 s,
  wenn fachlich vertretbar), oder auf SSE/langes Polling umstellen — wobei SSE im mod_php-Betrieb
  Worker und DB-Verbindung dauerhaft belegt und die Verbindungsgrenze zusätzlich belastet
  (`docs/notfallplan.md:339` weist bereits darauf hin).

**P3.4 Betrieb und Messung**

- `max_connections`-Auslastung, `Threads_running`, `Innodb_buffer_pool_reads`,
  `Innodb_row_lock_waits`, `Aborted_connects` und die langsamsten Queries (Slow Query Log)
  regelmäßig beobachten. `innodb_print_all_deadlocks=ON` (wie im Storage-Katalog) hilft bei der
  Diagnose.
- `performance_schema` ist aktiv (MySQL-Default) — für Lasttests und `EXPLAIN ANALYZE` nutzen.
- `opcache.validate_timestamps=0` prüfen, wenn im Produktivbetrieb ohnehin nur per Deployment
  aktualisiert wird.

---

## 11. Empfohlene Reihenfolge

1. **P1.1 + P1.2** (Verbindungen und Buffer Pool) — ohne diese beiden Punkte ist jede weitere
   Optimierung nachrangig, weil die Grenze vorher erreicht wird.
2. **P1.3 + P1.4** (AD-Sync und Netzlaufwerksmeldung) — die beiden schreibintensivsten Pfade.
3. **P2.2** (Cross-Request-Cache) — größter Hebel bei der Dauerlast pro Request, geringes Risiko.
4. **P2.1 + P2.3** (SSO-Lookup, Navigationsberechtigungen) — betreffen jeden Request.
5. **P2.4 + P2.5 + P2.6** (Telefonbuch, Archiv, Import) — situativ hohe Einzelkosten.
6. **P3** nach Bedarf und Messergebnis.

---

## 12. Nicht geprüft / offene Punkte

- **Keine Messung.** Die Agentenumgebung erlaubt kein `docker compose`, kein MySQL und keine
  Lasttests. Alle Query-Zahlen sind aus dem Code abgeleitet; die tatsächlichen Ausführungspläne
  (`EXPLAIN`), Buffer-Pool-Hit-Raten und Lock-Wartezeiten sind ungeprüft.
- **Effektive MySQL-Defaults** (insbesondere `innodb_buffer_pool_size` und die Apache-Worker-Zahl
  des Basisimages) hängen von der Laufzeitumgebung ab und sollten auf dem Zielsystem per
  `SHOW VARIABLES` bzw. `apachectl -V`/`httpd -M` verifiziert werden.
- **Verhalten von `mysql:9.7.2`** in Bezug auf Default-Buffer-Pool-Dimensionierung wurde nicht
  gegen die laufende Instanz geprüft.
- **Externe Systeme** (LDAP-Antwortzeiten, Exchange/EWS, Nextcloud) wurden nicht bewertet, obwohl
  sie die Laufzeit der Hintergrundjobs mitbestimmen.
- **Fachliche Bewertung der Polling-Intervalle** (10 s für Notfallplan/KAEP) wurde nicht
  vorgenommen — sie kann bewusst gewählt sein.
