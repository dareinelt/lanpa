# P1-Umsetzungsplan: Datenbank-Performance bei 1500 Mitarbeitenden + 750 Clients

**Status:** P1.1–P1.4 sind umgesetzt. Dieses Dokument beschreibt weiterhin die Abwägung; die
Umsetzung weicht an einer Stelle ab: Statt `DELETE FROM network_drives` (Empfehlung in P1.4) wurde
die Tabelle erhalten und die Migration `041_network_drives_uid_lowercase.sql` angelegt.
**Grundlage:** [`db-performance-bericht.md`](db-performance-bericht.md) (Abschnitte 7–9).
**Bezug:** P1.1–P1.4 des Berichts.

---

## 0. Geltungsbereich und Rahmenbedingungen

Dieser Plan beschreibt **nur die vier P1-Maßnahmen**. P2 (Dauerlast durch Queries/Caches) und P3
(Betrieb/Monitoring) bleiben ausgeklammert.

Rahmenbedingungen aus `AGENTS.md`, die die Umsetzung prägen:

| Regel | Folge für diesen Plan |
| --- | --- |
| Keine neuen Abhängigkeiten (Composer/npm/CDN) | Kein ProxySQL, kein Redis, keine Extension. Nur Boardmittel. |
| Bestehende Migrationsdateien nie ändern; nächste freie Nummer **041** | Schemaänderungen nur als `041_*.sql`. Ursprünglich war keine Migration vorgesehen; umgesetzt wurde dann doch `041_network_drives_uid_lowercase.sql` (siehe P1.4). |
| Vorhandene Muster nachahmen | `db`-Konfiguration folgt dem Muster von `storage-sync-catalog` (`command: --innodb-buffer-pool-size=${…}` + `docs/storage-stack.md` §6.5). |
| `declare(strict_types=1)`, `final`, `private readonly`, Namespace `App\…` | Gilt für alle neuen Methoden/Klassen. |
| UI-Texte und Kommentare deutsch, Bezeichner englisch | Neue Kommentare deutsch. |
| Tests über `php tests/run.php` (eigener Runner, Fakes + SQLite) | Interface-Änderungen erfordern Anpassung der Fakes in `tests/Support/Fakes.php`. |
| Kein Linter/Formatter/Typechecker hinzufügen | Validierung nur per Tests + `php -l`. |
| Doku mitpflegen | `agentsindex.md`, `docs/installation.md`, `.env.example` je nach Maßnahme. |

### Ein Hinweis vorab: Testbarkeit gegen SQLite

Die Unit-Tests laufen teils gegen **SQLite** mit handgeschriebenen Schemata
(`tests/Unit/NetworkDriveTest.php:23-39`), teils gegen **Fakes** ohne Datenbank
(`tests/Unit/IdentitySourcesTest.php` mit `FakePhonebookStore`).

SQLite vergleicht `TEXT` mit `BINARY`-Collation, also **case-sensitiv**, MySQL hier mit
`utf8mb4_unicode_ci`, also **case-insensitiv**. Jede Änderung an `LOWER(...)`-Ausdrücken
verändert damit das Verhalten in den Tests, auch wenn sie in MySQL wirkungsgleich ist.
Das ist in P1.4 berücksichtigt.

### Kommentar im Repository, der eine Lösung ausschließt

`app/Repositories/NetworkDriveRepository.php:10-12`:

> *Gemeldete Netzlaufwerke der Windows-Clients (je Benutzer und Buchstabe).
> Bewusst ohne MySQL-spezifisches Upsert (auch mit SQLite testbar).*

Daraus folgt: **kein `INSERT … ON DUPLICATE KEY UPDATE` in diesem Repository** und **kein
funktionaler Index** (`(LOWER(user_uid))`), auch wenn beides technisch möglich wäre. Diese
Entscheidung wird respektiert (Projektregel: bestehende Muster nicht überschreiben).

---

## Phase 0 — Messen (Voraussetzung, kein Code)

Der Bericht ist **aus dem Code abgeleitet, nicht gemessen**. Ohne Ausgangswerte ist der Erfolg
der P1-Maßnahmen nicht belegbar. Diese Werte gehören vorher auf dem Zielsystem erfasst.

```bash
# Verbindungen und Abbrüche
docker compose exec db mysql -uroot -p -e "
  SHOW VARIABLES LIKE 'max_connections';
  SHOW VARIABLES LIKE 'innodb_buffer_pool_size';
  SHOW VARIABLES LIKE 'innodb_autoinc_lock_mode';
  SHOW STATUS  LIKE 'Threads_connected';
  SHOW STATUS  LIKE 'Threads_running';
  SHOW STATUS  LIKE 'Max_used_connections';
  SHOW STATUS  LIKE 'Aborted_connects';
  SHOW STATUS  LIKE 'Innodb_row_lock_waits';
  SHOW STATUS  LIKE 'Innodb_buffer_pool_read%';"

# Ist-Zustand der Laufwerksmeldungen (Bezugsgröße für P1.4)
docker compose exec db mysql -uroot -p intranet -e "
  SELECT COUNT(*) AS zeilen, COUNT(DISTINCT user_uid) AS nutzer FROM network_drives;"

# Telefonbuch (Bezugsgröße für P1.3)
docker compose exec db mysql -uroot -p intranet -e "
  SELECT COUNT(*) AS eintraege, SUM(active) AS aktiv FROM phonebook;
  SELECT COUNT(*) AS gruppen FROM ad_groups WHERE active = 1;
  SELECT COUNT(*) AS mitgliedschaften FROM ad_group_members;"

# Apache: welches MPM, wie viele Kinder erlaubt?
docker compose exec app apachectl -V | grep -iE 'MPM|Server MPM'
docker compose exec app apache2ctl -M | grep -i mpm
docker compose exec app grep -rE 'MaxRequestWorkers|ServerLimit' /etc/apache2/ 2>/dev/null
```

Ergänzend für die Dauerbeobachtung sinnvoll (P3, hier nur als Vorbereitung):

```bash
docker compose exec db mysql -uroot -p -e "SET GLOBAL slow_query_log = ON; SET GLOBAL long_query_time = 0.5;"
```

**Zu klären und zu dokumentieren:**

1. Welches MPM läuft (`prefork` erwartet, weil mod_php) und welcher `MaxRequestWorkers` gilt im
   Basisimage? Daraus folgt die Zahl der gleichzeitigen PDO-Verbindungen des `app`-Containers.
2. Wie viel RAM hat der Host, wie viel belegt der `db`-Container tatsächlich
   (`docker stats --no-stream`)?
3. Wie viele Nutzer melden tatsächlich Netzlaufwerke (`COUNT(DISTINCT user_uid)` gegen 750)?

---

## P1.1 — Verbindungsbudget herstellen

### Ist-Zustand (belegt)

| Fakt | Fundstelle |
| --- | --- |
| `max_connections = 100` | `docker/mysql/my.cnf:5` |
| Keine Worker-Begrenzung für Apache | `docker/php/Dockerfile` (nur `a2enmod rewrite headers expires`, kein `MaxRequestWorkers`) |
| mod_php, kein PHP-FPM | `docker/php/Dockerfile:2` → `FROM php:8.5-apache` |
| Keine persistenten Verbindungen, kein Pooling | `app/Core/Database.php:37-46` |
| `memory_limit = 256M` | `docker/php/php.ini` |

Bei **prefork** (mod_php) gilt: 1 gleichzeitiger Request = 1 Apache-Kindprozess = 1 PHP-Prozess
= 1 PDO-Verbindung (kein `ATTR_PERSISTENT`). Die Zahl der Verbindungen ist damit **direkt** durch
`MaxRequestWorkers` bestimmt — heute durch keinen expliziten Wert.

### Verbindungsbedarf der Nebenverbraucher

| Verbraucher | Verbindungen | Beleg |
| --- | --- | --- |
| `app` (Apache-Kinder) | = gleichzeitige Requests | mod_php/prefork |
| `mail` (`mail_worker.php`) | 1 | `docker-compose.yml:132-148` |
| `mail-archive` (Supervisor + Worker) | 2–3 | `docker-compose.yml:152-174` |
| `sync` (`sync_worker.php`) | 1 | `docker-compose.yml:279-298` |
| `snmp` | 1–2 | `docker-compose.yml:359-386` |
| `office-backup` (Profil `office`) | 1–2 | `docker-compose.yml:542-580` |
| `phpmyadmin` (Profil `tools`) | 1–5 | `docker-compose.yml:300-320` |
| Healthchecks + `root`-Reserve | 2 | `docker-compose.yml:84-89` |
| **`auth`** | **0** | kein DB-Zugriff: keine `DB_*`-Variablen, nur HTTP auf `/internal/sso-config` |
| **Summe Nebenverbraucher** | **≈ 10–15** | |

### Maßnahme A (empfohlen) — Worker begrenzen

Neue Datei `docker/php/apache-prefork.conf`:

```apache
# Gleichzeitige Requests und damit gleichzeitige PDO-Verbindungen begrenzen.
# mod_php (prefork): ein Kindprozess je Request, je eine eigene DB-Verbindung
# (app/Core/Database.php ohne persistente Verbindungen). Der Wert muss zusammen
# mit max_connections in docker/mysql/my.cnf aufgehen - siehe docs/installation.md.
<IfModule mpm_prefork_module>
    StartServers             8
    MinSpareServers          8
    MaxSpareServers         16
    ServerLimit            176
    MaxRequestWorkers      176
    MaxConnectionsPerChild 10000
</IfModule>
```

In `docker/php/Dockerfile` neben den bestehenden Konfigurationen aktivieren (im Block ab `:26`):

```dockerfile
COPY docker/php/apache-prefork.conf /etc/apache2/conf-available/apache-prefork.conf
RUN a2enconf apache-prefork
```

`MaxConnectionsPerChild 10000` begrenzt die Speicherfragmentierung, die bei mod_php über
lange Laufzeiten entsteht; der Wert ist ein Startpunkt, kein gemessener Optimumswert.

### Maßnahme B (Absicherung) — `max_connections` anheben

`docker/mysql/my.cnf`:

```ini
max_connections = 240

# Viele Verbindungen kurzer Lebensdauer (eine je Request, kein Pooling):
# Threads werden wiederverwendet statt neu erzeugt.
thread_cache_size = 64
```

**Rechnung:** `MaxRequestWorkers 176` + Nebenverbraucher ≈ 15 + Reserve 20 → 211. `240` lässt
Luft für einen zweiten `app`-Container (Wartung/Rollout) und für Ad-hoc-Verbindungen.

**Warum A *und* B, nicht nur B:** Ohne Worker-Begrenzung ist die Verbindungszahl nicht
deterministisch; `max_connections` wird dann zur Zufallsgrenze, deren Überschreitung
`SQLSTATE[HY000] [1040] Too many connections` als HTTP 500 beim Nutzer zeigt. Mit
Worker-Begrenzung wartet der Request stattdessen in der Apache-Queue — die Spitze wird
geglättet, und die Fehlerart ist „langsamer" statt „kaputt".

### Konflikt zwischen Verbindungs- und Speicherbudget

`memory_limit = 256M` × 176 Worker = theoretisch 44 GiB. Der wirksame Grenzwert ist deshalb
**`RAM_verfügbar / typischer RSS eines Kindprozesses`**, nicht die Verbindungszahl. Vorgehen:

1. Unter Last `docker stats --no-stream app` beobachten.
2. `MaxRequestWorkers` auf den kleineren der beiden Werte setzen
   (Speichergrenze bzw. Verbindungsgrenze 240 − Reserve).
3. Erst danach P1.2 dimensionieren (Buffer Pool konkurriert um denselben Host-RAM).

### Bewusst nicht in P1.1

**`PDO::ATTR_PERSISTENT`.** Bei prefork ist die Prozesszahl stabil, persistente Verbindungen
könnten den Handshake-Aufwand sparen. Sie binden aber dauerhaft bis zu `MaxRequestWorkers`
Verbindungen (statt nur bei Last) und erfordern dann zwingend `max_connections` >
`MaxRequestWorkers` plus Sorgfalt bei Transaktionsresten. Das ist eine eigene Maßnahme mit
Messung — nicht Teil des Stabilitätspakets.

### Validierung P1.1

```bash
docker compose up -d --build app db
docker compose exec app apache2ctl -M | grep mpm_prefork
docker compose exec app apachectl -V | grep -i server
docker compose exec db mysql -uroot -p -e "SHOW VARIABLES LIKE 'max_connections'; SHOW VARIABLES LIKE 'thread_cache_size';"
docker compose exec db mysql -uroot -p -e "SHOW STATUS LIKE 'Max_used_connections'; SHOW STATUS LIKE 'Aborted_connects';"
```

**Erfolgskriterium:** `Max_used_connections` bleibt im Tagesverlauf deutlich unter 240;
`Aborted_connects` steigt nicht mehr; unter Last keine `1040`-Fehler im Anwendungslog
(`storage/logs/`).

### Doku P1.1

- `docs/installation.md`: neuer Abschnitt „Dimensionierung" (Verbindungs- und Speicherbudget,
  beide Budgets, Rechnung, wie man den Wert ändert).
- `.env.example`: kein neuer Wert (beide Werte sind Compose-/Image-Konfiguration).
- `agentsindex.md`: nur, falls dort Container-/Betriebswerte dokumentiert sind.

---

## P1.2 — InnoDB für die Hauptdatenbank konfigurieren

### Ist-Zustand (belegt)

`docker/mysql/my.cnf` enthält **nur** `character-set-server`, `collation-server`,
`skip-name-resolve`, `max_connections`. `innodb_buffer_pool_size` bleibt beim
MySQL-Standardwert. Zum Vergleich die Neben-Datenbank
(`docker/storage-sync-catalog/my.cnf:13-34`): Redo-Log 1 G, `O_DIRECT`, `io_capacity`
2000/4000, `READ-COMMITTED`, `innodb_print_all_deadlocks = ON`, `thread_cache_size`,
`table_open_cache` — und der Buffer Pool kommt über `command:` in `docker-compose.yml:593-594`.

**Die Hauptdatenbank ist heute schlechter konfiguriert als die Hilfs-Datenbank.**

### Maßnahme — dem etablierten Projektmuster folgen

**1. `docker/mysql/my.cnf` erweitern**

```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
skip-name-resolve
max_connections = 240
thread_cache_size = 64

# InnoDB. Die Puffergroesse setzt docker-compose.yml (DB_BUFFER_POOL) - wie beim
# storage-sync-Katalog (siehe docs/storage-stack.md, Abschnitt 6.5).
innodb_flush_method = O_DIRECT
innodb_redo_log_capacity = 1G
innodb_log_buffer_size = 64M
innodb_io_capacity = 1000
innodb_io_capacity_max = 2000
innodb_lock_wait_timeout = 30

# Jeder Deadlock landet im Container-Log. Kostet im Normalbetrieb nichts und ist
# Voraussetzung fuer die Fehlersuche bei AD-Sync und Laufwerksmeldungen.
innodb_print_all_deadlocks = ON

table_open_cache = 2000

[client]
default-character-set = utf8mb4
```

**2. `docker-compose.yml`, Service `db` (ab `:72`)** — Buffer Pool wie beim Katalog setzen:

```yaml
    command:
      - --innodb-buffer-pool-size=${DB_BUFFER_POOL:-1G}
```

**3. `.env.example`** ergänzen (bei den übrigen `DB_*`-Werten):

```
# InnoDB-Puffergroesse der Anwendungsdatenbank. Der db-Container hat kein
# Speicherlimit, der Wert konkurriert also mit app (mod_php) um den Host-RAM.
# Faustregel: so gross wie moeglich, solange der Host nicht swappt.
DB_BUFFER_POOL=1G
```

**Begründung der Einzelwerte:**

| Einstellung | Wert | Begründung |
| --- | --- | --- |
| `innodb_flush_method = O_DIRECT` | – | Kein doppeltes Puffern im Seitencache des Hosts (wie Katalog). |
| `innodb_redo_log_capacity = 1G` | – | Weniger Checkpoints bei den Schreibspitzen aus P1.3/P1.4. |
| `innodb_io_capacity(_max)` | 1000 / 2000 | Konservativer als der Katalog (2000/4000), weil hier Transaktionen mit Nutzerbezug laufen; auf SSD-Hosts kann erhöht werden. |
| `innodb_lock_wait_timeout = 30` | – | Kürzer als der Katalog (60), weil hier ein Nutzer am Request hängt, nicht ein Batch. |
| `innodb_print_all_deadlocks = ON` | – | Diagnosegrundlage für P1.3/P1.4. |
| `table_open_cache = 2000` | – | Mehrere hundert Tabellen, viele gleichzeitige Requests. `open_files_limit` des Containers gegenprüfen. |

### Bewusst **nicht** in P1.2

| Einstellung | Warum nicht |
| --- | --- |
| `transaction_isolation = READ-COMMITTED` | Fachliche Entscheidung mit Sichtbarkeitsfolgen, nicht eine Performance-Einstellung. Beim Katalog vertretbar, weil rekonstruierbar. Gehört in eine eigene Betrachtung (P2). |
| `disable_log_bin` | Die Hauptdatenbank ist führende Datenhaltung; Binlog ist Voraussetzung für Point-in-Time-Wiederherstellung. Nicht ohne Backup-Konzept abschalten. |
| `innodb_flush_log_at_trx_commit = 2` | Durability: ein Host-Absturz verlöre bis zu ~1 s committete Transaktionen (Klicks, Einstellungen, Laufwerksmeldungen). Beim Katalog vertretbar, hier nicht ohne ausdrückliche fachliche Freigabe. |
| `innodb_autoinc_lock_mode = 2` | Siehe P1.4, vierter Baustein — eigener Entscheidungspunkt, nicht im Paket verstecken. |

### Validierung P1.2

```bash
docker compose up -d db
docker compose exec db mysql -uroot -p -e "
  SELECT @@innodb_buffer_pool_size/1024/1024 AS pool_mb,
         @@innodb_redo_log_capacity/1024/1024 AS redo_mb,
         @@innodb_flush_method, @@innodb_print_all_deadlocks;"
docker compose exec db mysql -uroot -p -e "
  SHOW STATUS LIKE 'Innodb_buffer_pool_reads';
  SHOW STATUS LIKE 'Innodb_buffer_pool_read_requests';
  SHOW STATUS LIKE 'Innodb_row_lock_waits';"
```

**Erfolgskriterium:** `Innodb_buffer_pool_reads / Innodb_buffer_pool_read_requests` < 1 %
im Dauerbetrieb. **Voraussetzung:** `docker stats` zeigt keinen Swap und keinen Druck auf
den Host-RAM.

### Doku P1.2

- `docs/installation.md`: der neue Abschnitt „Dimensionierung" dokumentiert auch
  `DB_BUFFER_POOL` mit der Tabelle der Einzelwerte (Muster: `docs/storage-stack.md` §6.5).
- `.env.example`: `DB_BUFFER_POOL`.
- `agentsindex.md`: nur bei vorhandener Betriebswerteliste.

---

## P1.3 — AD-Sync entschärfen

### Ist-Zustand (belegt)

```
scripts/sync_worker.php:28     Intervall = ldap_sync_interval (Standard 3600 s, min 60 s)
scripts/sync_worker.php:35     Container::adSync()->run()

app/Services/AdSyncService.php:176-194
    beginTransaction()
    foreach ($users as $user) { $this->store->upsert($user, $syncedAt); }   // ~1500x Einzel-Upsert
    $this->store->deactivateStale($syncedAt, $sourceId)
    $this->groups->replaceAll($groups, $syncedAt, $sourceId)
    commit()

app/Repositories/PhonebookRepository.php:266-306   upsert(): Prepare + Execute je Aufruf
app/Repositories/AdGroupRepository.php:14-73       replaceAll(): je Gruppe
     1x INSERT..ON DUPLICATE KEY UPDATE
     1x SELECT id
     1x DELETE FROM ad_group_members WHERE group_id
     Nx INSERT (Chunks via insertMembers)
   am Ende: 1x UPDATE ad_groups ... active = 0, 1x DELETE m FROM ad_group_members m JOIN ...
```

### Wirkung

Eine einzige Transaktion hält Undo-Log, Redo-Log und **alle geänderten Zeilensperren** bis zum
`commit()`. Während dieser Zeit:

- `phonebook`-Zeilen sind gesperrt → `SsoAuth::resolveSession()`
  (`app/Security/SsoAuth.php:208-233`) läuft auf **jedem** authentifizierten Request → jede
  Anmeldung kann auf Sperren warten.
- `ad_groups` / `ad_group_members` sind gesperrt → `AdGroupRepository::namesForUser()` (in der
  Navigation) und `suggest()` (Admin) warten.
- Bei einem Fehler am Ende wird **alles** zurückgerollt — die komplette Arbeit ist verloren.
- 1500 × `prepare()` + `execute()` statt eines vorbereiteten Statements.

### Maßnahme 1 — Batch-Transaktionen statt einer Gesamttransaktion

`app/Services/AdSyncService.php`, Methode `syncSource()` (`:176-194`): Nutzer in Blöcke
(z. B. 200) aufteilen, je Block eine eigene Transaktion.

```php
// Bloecke statt einer Gesamttransaktion: die Sperren auf phonebook und
// ad_group_members werden nach jedem Block freigegeben, statt bis zum Ende des
// gesamten Laufs zu halten. Bei einem Fehler ist nur der laufende Block
// verloren, nicht der ganze Lauf.
$blockSize = 200;
$processed = 0;
$blocks = array_chunk($users, $blockSize);

try {
    foreach ($blocks as $block) {
        $this->store->beginTransaction();
        try {
            foreach ($block as $user) {
                // ... bestehende Filterung und $user['identity_source_id'] = $sourceId;
                $this->store->upsert($user, $syncedAt);
                $processed++;
            }
            $this->store->commit();
        } catch (Throwable $exception) {
            $this->store->rollBack();
            throw $exception;
        }
    }

    // Erst nach dem letzten erfolgreichen Block: deaktivieren und Gruppen.
    // $syncedAt ist fuer alle Bloecke identisch, deshalb erkennt
    // deactivateStale() Nutzer aus frueheren Bloecken korrekt als aktuell.
    $this->store->beginTransaction();
    try {
        $deactivated = $processed > 0 ? $this->store->deactivateStale($syncedAt, $sourceId) : 0;
        $groupCount  = $groups !== null && $this->groups !== null
            ? $this->groups->replaceAll($groups, $syncedAt, $sourceId)
            : null;
        $this->store->commit();
    } catch (Throwable $exception) {
        $this->store->rollBack();
        throw $exception;
    }
} catch (Throwable $exception) {
    // bestehende Fehlerbehandlung (:195-201)
}
```

**Kritischer Punkt:** `$syncedAt` muss für **alle** Blöcke identisch sein. Wird er je Block neu
gebildet, deaktiviert `deactivateStale()` (`PhonebookRepository.php:308-317`, Bedingung
`synced_at < :synced_at`) die Nutzer der vorherigen Blöcke. `$syncedAt` entsteht heute einmal in
`run()` — das muss so bleiben und **muss einen Test bekommen**.

**Nebeneffekt, der dokumentiert gehört:** Bei einem Abbruch in Block *k* sind die Blöcke 1…*k*−1
bereits geschrieben, und es wird **nicht** deaktiviert. Ergebnis: gemischter Stand, kein Nutzer
verliert seine Sichtbarkeit. Der Lauf meldet wie bisher `failure` über den bestehenden Pfad
(`:195-201`) und landet im Sync-Log. Das ist das gewünschte Verhalten — besser als ein
vollständiger Rollback.

### Maßnahme 2 — Mehrzeilen-Upsert statt Einzel-Upserts

**Interface-Erweiterung** in `app/Contracts/PhonebookStoreInterface.php`:

```php
/**
 * Legt mehrere Datensaetze an oder aktualisiert sie in einer Anweisung
 * (Schluessel: external_id). Entspricht upsert() fuer eine Liste.
 *
 * @param list<array<string,string|null>> $users
 */
public function upsertMany(array $users, string $syncedAt): void;
```

Betroffene Implementierungen:

| Datei | Anpassung |
| --- | --- |
| `app/Repositories/PhonebookRepository.php` | echte Implementierung (SQL unten) |
| `tests/Support/Fakes.php` → `FakePhonebookStore` | muss die Methode **inhaltlich** nachbilden (Einträge übernehmen, `external_id` als Schlüssel), nicht leer lassen — sonst verlieren `tests/Unit/IdentitySourcesTest.php` ihre Aussagekraft |

SQL im Repository, Statement **einmal** vorbereiten und je Block mit neuen Parametern ausführen:

```sql
INSERT INTO phonebook
    (external_id, identity_source_id, samaccount_name, display_name, first_name,
     last_name, title, phone, phone_digits, mobile, email, department, ad_modified,
     synced_at, active)
VALUES
    (:e0, :s0, ...), (:e1, :s1, ...), ...
ON DUPLICATE KEY UPDATE
    identity_source_id = VALUES(identity_source_id),
    samaccount_name    = VALUES(samaccount_name),
    /* ... identisch zu upsert() (:274-287) ... */
    active             = 1
```

**Blockgröße 200** — 15 Spalten × 200 = 3000 Platzhalter, deutlich unter dem MySQL-Limit
(65535) und konservativ gegenüber `max_allowed_packet`.

**Syntax-Hinweis:** `VALUES(col)` ist seit MySQL 8.0.20 als veraltet markiert (in 9.x weiterhin
funktionsfähig); modern ist die Zeilen-Alias-Form `INSERT … AS new ON DUPLICATE KEY UPDATE
col = new.col`. Empfehlung: **beim bestehenden `VALUES()` bleiben**, um `upsert()` und
`upsertMany()` nicht auseinanderlaufen zu lassen (Projektregel: bestehende Muster folgen) — die
Umstellung gehört als eigener, projektweiter Schritt in P3.

### Maßnahme 3 — Gruppen nur bei Änderung schreiben

`app/Repositories/AdGroupRepository.php`, `replaceAll()` (`:14-73`). Zwei Einsparungen:

**a) `$findId` je Gruppe (`:22,45`) durch eine Vorababfrage ersetzen** — portabel, ohne
MySQL-spezifische Tricks:

```php
// Einmal je Quelle statt eines SELECT je Gruppe.
$known = [];
$statement = $this->pdo->prepare('SELECT id, dn_hash FROM ad_groups WHERE identity_source_id = :source');
$statement->execute(['source' => $sourceId]);
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $known[(string) $row['dn_hash']] = (int) $row['id'];
}
```

Nach dem Upsert einer Gruppe steht die ID in `$known[$hash]` (bei neuen Gruppen aus
`$this->pdo->lastInsertId()` bzw. einem Nachladen des Hashs).

**b) Mitgliedschaften nur bei Abweichung schreiben** — heute immer `DELETE` + N `INSERT`:

```php
// Ist-Stand der Mitglieder lesen, mit der Soll-Menge vergleichen und nur bei
// Abweichung schreiben. Im Regelbetrieb aendern sich pro Lauf wenige Gruppen.
$current = $this->memberIds($groupId);          // SELECT phonebook_id ... WHERE group_id = ?
sort($current);
$target = array_keys($members);
sort($target);
if ($current !== $target) {
    $deleteMembers->execute(['group_id' => $groupId]);
    if ($target !== []) {
        $this->insertMembers($groupId, $target);
    }
}
```

Ebenso beim `UPDATE ad_groups … active = 1`: unveränderte Gruppen (`dn`, `name`,
`description`, `member_count` gleich) brauchen kein Update. Der Vergleich erfolgt in PHP über
die in `$known` zusätzlich gelesenen Spalten.

**Wirkung:** Aus ~1000 Schreibvorgängen je Lauf werden im Regelfall wenige.

### Maßnahme 4 — `phonebookIdsByExternalId()` aus der Transaktion holen

`AdGroupRepository::replaceAll()` ruft in `:24` `$this->phonebookIdsByExternalId($sourceId)` ab
(`:75-82`) — ein `SELECT` über die gesamte `phonebook`-Tabelle, und zwar **innerhalb** der von
`syncSource()` geöffneten Transaktion. Die Zuordnung sollte vor dem Transaktionsbeginn gebildet
und als Parameter übergeben werden.

### Maßnahme 5 — Sync-Fenster (Konfiguration, kein Code)

`ldap_sync_interval` (Standard 3600 s) ist einstellbar, aber es gibt keinen Ankerzeitpunkt: der
Lauf startet, wenn der Container gestartet ist. Bei 3600 s ist der Zeitpunkt stabil, aber
zufällig. **P1-Maßnahme:** den Wert so wählen, dass der Lauf außerhalb der Anmeldespitze liegt,
und das in der Doku als Betriebshinweis festhalten. Eine echte Fenstersteuerung
(`ldap_sync_window_start`) wäre eine neue Einstellung über `SettingsService` — sinnvoll, aber
P2-Umfang.

### Ausdrücklich nicht Teil von P1.3

`app/Repositories/PhonebookRepository.php:444` (`DELETE FROM phonebook` **ohne `WHERE`** im
Import-Pfad) ist ein Totalblockade-Risiko, aber eine eigene Maßnahme (P2.10). **Prüfpunkt beim
Umbau:** sicherstellen, dass der neue Batch-Pfad diesen Code nicht erreicht.

### Validierung P1.3

```bash
php -l app/Services/AdSyncService.php
php -l app/Repositories/PhonebookRepository.php
php -l app/Repositories/AdGroupRepository.php
php -l app/Contracts/PhonebookStoreInterface.php
php -l tests/Support/Fakes.php
php tests/run.php
```

**Neue Tests (erforderlich, nicht optional):**

| Test | Prüft |
| --- | --- |
| AD-Sync: Blockgrenzen | 450 Nutzer → 3 Blöcke, alle mit **demselben** `syncedAt` |
| AD-Sync: `deactivateStale` nach letztem Block | Nutzer aus Block 1 werden **nicht** deaktiviert |
| AD-Sync: Abbruch in Block 2 | Blöcke 1 committet, Block 2/3 nicht, `deactivateStale` **nicht** ausgeführt, Status `failure` |
| `upsertMany` im Fake | Inhaltlich gleiches Ergebnis wie N × `upsert` |
| Gruppen: unveränderter Lauf | `DELETE FROM ad_group_members` wird **nicht** ausgeführt (Zähler am Fake/PDO) |

**Messung auf dem Zielsystem:** Dauer des Sync-Laufs und `SHOW STATUS LIKE
'Innodb_row_lock_waits'` vor/nach; im Anwendungslog die Laufzeit des `sync_worker`-Durchlaufs
(`scripts/sync_worker.php:38-46` protokolliert bereits Zeitstempel und Zähler).

### Doku P1.3

- `agentsindex.md`: falls dort der Sync-Ablauf beschrieben ist (Batch-Verhalten,
  Teil-Commit-Semantik).
- `docs/installation.md`: Betriebshinweis zum Sync-Fenster.
- Kein Moduldokument betroffen (AD-Sync ist Kernfunktion, kein eigenes Modul).

---

## P1.4 — `/sso/laufwerke` entlasten

### Ist-Zustand (belegt)

`app/Services/Office/NetworkDriveService.php:172-225`:

```php
216:  $before = $this->fingerprint();                         // Voll-SELECT network_drives
217:  $this->repository->replaceForUser($uid, $displayName, array_values($drives), $domain, $computer);
                                                             // BEGIN; DELETE; N INSERT; COMMIT  ← immer
218:  $changed = !hash_equals($before, $this->fingerprint());  // Voll-SELECT network_drives erneut
222:  $push = $changed ? $this->pushIfEnabled() : null;
```

| Baustein | Fundstelle | Verhalten |
| --- | --- | --- |
| `fingerprint()` | `:302-305` | `fingerprintOf(isEnabled(), excludedLetters(), nextcloudDrives())` |
| `nextcloudDrives()` | `:271-300` | `$this->repository->all()` → **alle** Zeilen |
| `all()` | `NetworkDriveRepository.php:20-28` | `SELECT … FROM network_drives ORDER BY user_uid, drive_letter` — **ohne WHERE, ohne LIMIT** |
| `replaceForUser()` | `NetworkDriveRepository.php:49-73` | `DELETE … WHERE LOWER(user_uid) = LOWER(:uid)` + N Einzel-INSERTs, **immer** |
| `forUser()` | `NetworkDriveRepository.php:33-42` | vorhanden, **hat keinen Aufrufer** (toter Code, offenbar für genau diesen Vergleich gedacht) |

`fingerprintOf()` (`:311-316`) rechnet zusätzlich `json_encode` über alle Zeilen und `sha256` in
PHP — pro Aufruf, also **zweimal je Client-Anmeldung**.

`LOWER(user_uid)` verhindert die Nutzung von `uniq_network_drives_user_letter`
(`database/migrations/021_network_drives.sql:21`) → Full Scan + Sortierung für das `DELETE`.

### Wirkung bei 750 Clients im Morgenfenster

- 750 × `DELETE` mit Full Scan und Sortierung über die wachsende Tabelle
- 750 × 2 Full Scans für den Fingerprint, je mit `json_encode` + `sha256` über alle Zeilen
- 750 Schreibtransaktionen, davon praktisch alle unnötig (Normalfall: unveränderte Laufwerke —
  der Kommentar `:220-221` sagt das selbst)
- `innodb_autoinc_lock_mode` steht auf dem MySQL-Standard 1 (consecutive): parallele
  Mehrzeilen-Inserts in dieselbe Tabelle konkurrieren um Auto-Increment-Werte. Der Katalog
  nutzt deshalb bewusst `2`.

### Maßnahme 1 — Vor dem Schreiben vergleichen, auf Benutzerebene

Neuer Ablauf in `report()`: die **vorhandenen Zeilen dieses Nutzers** lesen, den Soll-Zustand in
PHP vergleichen, und nur bei Abweichung schreiben. Dafür wird das bereits vorhandene
`forUser()` genutzt.

```php
$existing = $this->repository->forUser($uid);
$unchanged = $this->sameState($existing, $drives, $displayName, $domain, $computer);

if ($unchanged) {
    // Nur die Meldezeit aktualisieren: ein indizierter UPDATE statt DELETE +
    // N INSERTs. Die Admin-Uebersicht zeigt reported_at ("letzte Meldung",
    // views/admin/network_drives.php:163) - die Bedeutung bleibt erhalten.
    $this->repository->touchForUser($uid, $displayName);
    $changed = false;
} else {
    $this->repository->replaceForUser($uid, $displayName, array_values($drives), $domain, $computer);
    $changed = true;
}

$push = $changed ? $this->pushIfEnabled() : null;
```

Neue Repository-Methode (`app/Repositories/NetworkDriveRepository.php`, Stil der bestehenden
Methoden):

```php
/**
 * Aktualisiert nur den Meldezeitpunkt und den Anzeigenamen eines Benutzers.
 * Fuer Meldungen ohne Aenderung - vermeidet DELETE und Neuaufbau.
 */
public function touchForUser(string $uid, string $displayName): void
{
    $statement = $this->pdo->prepare(
        'UPDATE network_drives SET reported_at = CURRENT_TIMESTAMP, display_name = :name
          WHERE user_uid = :uid'
    );
    $statement->execute(['uid' => $uid, 'name' => $displayName]);
}
```

**Warum nicht einfach gar nicht schreiben:** `views/admin/network_drives.php:163` zeigt
`reported_at` als „letzte Meldung". Ein reines Überspringen würde diese Anzeige altern lassen.
Der indizierte `UPDATE` kostet einen Bruchteil von `DELETE` + N `INSERT`s und erhält die
dokumentierte Semantik („Jede Meldung ersetzt den Stand des Benutzers", Migration 021, `:5-7`).

**Wichtig:** `fingerprint()` wird aus `report()` **entfernt**. Der Gesamt-Fingerprint bleibt
dort, wo er gebraucht wird — beim Nextcloud-Abgleich (`pushToNextcloud()`, `inSync()`,
`payload()`, `nextcloudDrives()` für die Admin-Übersicht) — und ist nicht mehr Bestandteil
jeder einzelnen Client-Anmeldung.

**Ergebnis im Normalfall (Anmeldung ohne Änderung):** 1 indizierter `SELECT` + 1 indizierter
`UPDATE` statt 2 Full Scans + `DELETE` + N `INSERT`s.

### Maßnahme 2 — `LOWER()` entfernen (mit UID-Normalisierung)

`NetworkDriveRepository.php:37, 53, 77`:

```sql
-- vorher
WHERE LOWER(user_uid) = LOWER(:uid)
-- nachher
WHERE user_uid = :uid
```

**In MySQL wirkungsgleich**, weil die Spalte `utf8mb4_unicode_ci` ist
(`021_network_drives.sql:23`) — aber `uniq_network_drives_user_letter` wird nutzbar.

**In den Tests nicht wirkungsgleich:** das SQLite-Schema in
`tests/Unit/NetworkDriveTest.php:26-36` deklariert `user_uid TEXT` ohne Collation → `BINARY`,
also case-sensitiv. Ohne `LOWER()` schlägt ein Test fehl, der mit anderer Schreibweise sucht.

**Deshalb gehört zur Maßnahme zwingend die Normalisierung beim Schreiben:**

```php
// Kennungen werden klein geschrieben gespeichert und gesucht. Der
// SamAccountName des Clients und der SSO-Header koennen unterschiedlich
// geschrieben sein (UID_PATTERN erlaubt Gross- und Kleinschreibung).
$uid = strtolower((string) $user['office_uid']);
```

Die Normalisierung gehört in die Service-Schicht (`NetworkDriveService::report()`,
`deleteUser()`, `nextcloudDrives()`), damit alle Aufrufer denselben Wert liefern.
`NetworkDriveService::UID_PATTERN` (`:43`) bleibt unverändert die Validierung.

**Einmalige Bereinigung beim Rollout.** Bestehende Zeilen in Großschreibung würden nach der
Änderung nicht mehr gefunden. `network_drives` ist ein **Cache der Client-Meldungen**, kein
führender Datenbestand — die Migration 021 beschreibt genau das („Jede Meldung ersetzt den
Stand des Benutzers"). Deshalb ist die einfachste korrekte Bereinigung:

```sql
DELETE FROM network_drives;
```

Kosten: eine kurze Lücke in der Admin-Übersicht und ein Nextcloud-Abgleich, bis sich die
Clients wieder gemeldet haben (bei der nächsten Anmeldung, also binnen eines Arbeitstags).

**Wenn die Tabelle erhalten bleiben soll**, ist eine Migration `041_*.sql` nötig — und zwar in
dieser Reihenfolge, weil `uniq_network_drives_user_letter` sonst kollidiert:

```sql
-- 041_normalize_network_drive_uids.sql
-- Kennungen einheitlich klein schreiben, damit WHERE user_uid = :uid den
-- Unique Key uniq_network_drives_user_letter nutzen kann (statt LOWER()).
-- Doppelte Schreibweisen zuerst entfernen - der zuletzt gemeldete Stand gilt.
DELETE d FROM network_drives d
  JOIN network_drives k
    ON k.id <> d.id
   AND k.user_uid = d.user_uid
   AND k.drive_letter = d.drive_letter
   AND k.reported_at > d.reported_at;

UPDATE network_drives SET user_uid = LOWER(user_uid) WHERE user_uid <> LOWER(user_uid);
```

**Empfehlung:** `DELETE FROM network_drives` — die Tabelle ist rekonstruierbar, das spart die
Migration und das Kollisionsrisiko. Wenn eine Migration entsteht, gilt: **Nummer 041**,
bestehende Migrationsdateien nicht anfassen.

### Maßnahme 3 — Prüfpunkt: Aufrufer von `deleteForUser()`

`deleteForUser()` (`:75-81`) wird nur aus `NetworkDriveService::deleteUser()` (`:260-262`)
aufgerufen. Zu prüfen ist, ob `$uid` dort validiert/normalisiert wird (Aufruf aus dem
Adminbereich). Falls nicht: dieselbe `strtolower()`-Normalisierung anwenden, statt sich auf
`LOWER()` zu verlassen.

### Maßnahme 4 — `innodb_autoinc_lock_mode` (eigener Entscheidungspunkt)

`2` (interleaved) beseitigt die Auto-Increment-Konkurrenz paralleler Mehrzeilen-Inserts — genau
die Situation aus Maßnahme 1 (geänderter Fall) und P1.3. **Aber:** `2` macht
Auto-Increment-Werte bei Bulk-Inserts nicht mehr lückenlos und ist bei **statement-basierter**
Replikation unsicher. Hier gibt es keine Replikation (siehe P1.2, `disable_log_bin` ohnehin
nicht gesetzt). Trotzdem: fachlich entscheiden und in `docker/mysql/my.cnf` mit Begründung
dokumentieren — nicht im Performance-Paket mitführen.

### Validierung P1.4

```bash
php -l app/Services/Office/NetworkDriveService.php
php -l app/Repositories/NetworkDriveRepository.php
php tests/run.php          # insbesondere tests/Unit/NetworkDriveTest.php
```

**Neue Tests (erforderlich):**

| Test | Prüft |
| --- | --- |
| Unveränderte Meldung | kein `DELETE`, kein `INSERT` (Zähler am PDO), `reported_at` aktualisiert, `changed === false` |
| Geänderte Meldung | `replaceForUser()` wird ausgeführt, `changed === true`, Push ausgelöst |
| UID-Schreibweise | `AMueller` und `amueller` treffen dieselben Zeilen (in SQLite **und** MySQL) |
| `report()` ohne `fingerprint()` | kein Aufruf von `all()` im Meldeweg (Zähler) |
| Leere Meldung (`''`) | bestehendes Verhalten aus `tests/Unit/NetworkDriveTest.php:145` bleibt erhalten |

**Messung auf dem Zielsystem:** `SELECT COUNT(*) FROM network_drives` vor/nach dem Morgenfenster;
`Innodb_row_lock_waits` und `Innodb_row_lock_time` vor/nach; `SHOW PROCESSLIST` während der
Anmeldespitze (erwartet: keine wartenden `network_drives`-Sperren).

### Doku P1.4

- `docs/office.md` / `docs/office-referenz.md`: das Meldeverhalten („ersetzt den Stand") bleibt
  fachlich gleich, aber der Mechanismus ändert sich (Vergleich vor dem Schreiben,
  `reported_at` wird auch ohne Änderung aktualisiert). Referenz vor der Umsetzung lesen
  (Projektregel).
- `agentsindex.md`: nur falls dort die Tabelle/der Endpunkt beschrieben ist.

---

## Reihenfolge und Aufteilung in Pull Requests

| Schritt | Inhalt | Abhängigkeit | Risiko | Aufwand |
| --- | --- | --- | --- | --- |
| 0 | Messen (Phase 0) | – | keins | klein |
| 1 | **P1.2** InnoDB-Konfiguration | Phase 0 (RAM) | niedrig, nur Container-Neustart | klein |
| 2 | **P1.1** Worker-/Verbindungsbudget | Phase 0 (MPM, RAM) | niedrig, aber Änderung an `MaxRequestWorkers` unter Last beobachten | klein |
| 3 | **P1.4** Laufwerksmeldung | – | mittel (Verhalten `reported_at`, Datenbereinigung) | mittel |
| 4 | **P1.3** AD-Sync | – | mittel (Transaktionsgrenzen, Interface-Erweiterung) | groß |

**Empfohlene PR-Zuschnitte** (jeweils ein Thema, nach Projektregel):

1. `InnoDB-Konfiguration der Anwendungsdatenbank (Pufferpool, Redo, Flush)`
   — `docker/mysql/my.cnf`, `docker-compose.yml`, `.env.example`, `docs/installation.md`
2. `Verbindungsbudget: Apache-Worker begrenzen, max_connections anheben`
   — `docker/php/apache-prefork.conf`, `docker/php/Dockerfile`, `docker/mysql/my.cnf`,
     `docs/installation.md`
3. `Netzlaufwerksmeldung: nur noch bei Änderung schreiben`
   — `NetworkDriveService.php`, `NetworkDriveRepository.php`, `tests/Unit/NetworkDriveTest.php`,
     Doku
4. `AD-Synchronisation: blockweise Transaktionen, Sammel-Upsert, Gruppen nur bei Änderung`
   — `AdSyncService.php`, `PhonebookStoreInterface.php`, `PhonebookRepository.php`,
     `AdGroupRepository.php`, `tests/Support/Fakes.php`, `tests/Unit/IdentitySourcesTest.php`

PR 1 und 2 betreffen dieselbe Datei (`docker/mysql/my.cnf`) — PR 2 nach PR 1 aufsetzen oder
beide zusammenführen, um Konflikte zu vermeiden.

**PR-Beschreibung** nach `.github/pull_request_template.md` (Warum / Was / Architektur /
Hinweise / Tests / Migration). Unter „Tests" jede ausgeführte Befehlszeile **mit Ergebnis**
aufführen (`php tests/run.php`, `php -l …`); die Messungen aus Phase 0 und die Vorher-/Nachher-
Werte gehören unter „Hinweise".

---

## Gesamtrisiko und Rollback

| Maßnahme | Rollback |
| --- | --- |
| P1.1 | `apache-prefork.conf` entfernen + `a2dismod`-Konfiguration zurücknehmen; `max_connections` in `my.cnf` zurücksetzen; `docker compose up -d app db`. |
| P1.2 | `my.cnf` und `command:`-Zeile in `docker-compose.yml` zurücksetzen; `docker compose up -d db`. **Keine Datenmigration** — reine Serverkonfiguration. |
| P1.3 | Reiner Code-Rollback. Teilweise committete Blöcke bleiben gültig (jeder Nutzer hat einen vollständigen Datensatz); `deactivateStale()` beim nächsten erfolgreichen Lauf korrigiert den Stand. |
| P1.4 | Reiner Code-Rollback. Bei `DELETE FROM network_drives` füllt sich die Tabelle bei der nächsten Client-Anmeldung wieder. |

**Gemeinsame Risiken:**

- **P1.2 ohne Messung** kann den Host in den Swap treiben (Buffer Pool konkurriert mit
  mod_php-Workern). Deshalb Schritt 0 vor Schritt 1.
- **P1.3 ändert Transaktionsgrenzen** — das ist die einzige Änderung im Paket, die bei einem
  Fehler einen *gemischten* Datenstand hinterlässt (bewusst, siehe oben). Sie braucht die
  genannten Tests, nicht nur `php -l`.
- **P1.4 ändert das Verhalten von `reported_at`** nur dann nicht, wenn `touchForUser()` wie
  beschrieben umgesetzt wird. Ein reines Überspringen des Schreibens wäre eine stille
  Verhaltensänderung in der Admin-Übersicht.

---

## Offene Punkte, die vor der Umsetzung zu klären sind

1. **Host-RAM und Swap-Verhalten** — Voraussetzung für P1.1 und P1.2 (Phase 0).
2. **Tatsächlicher `MaxRequestWorkers` des Basisimages** — Phase 0; daraus folgt die
   Verbindungsrechnung.
3. **Darf `transaction_isolation` / `innodb_flush_log_at_trx_commit` angefasst werden?**
   Fachliche Entscheidung (Durability, Backup-Konzept), ausdrücklich **nicht** im P1-Paket.
4. **`innodb_autoinc_lock_mode = 2`** — eigene Freigabe (Replikationsfreiheit bestätigen).
5. **`DELETE FROM network_drives` beim Rollout akzeptabel** oder Migration 041 gewünscht?
6. **Sync-Fenster:** reicht ein dokumentierter Betriebshinweis, oder soll `ldap_sync_interval`
   um einen Ankerzeitpunkt ergänzt werden (neue Einstellung → P2)?
7. **Polling-Intervalle** (Notfallplan/KAEP 10 s) sind laut Bericht ein eigener Lastfaktor —
   fachliche Anforderung klären, bevor P2 darauf aufsetzt.
