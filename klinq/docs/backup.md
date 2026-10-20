# Backup & Wiederherstellung

Die Anwendung hält ihren gesamten Zustand in drei Orten. Ein vollständiges Backup umfasst alle drei; die Datenbank allein reicht nicht, weil Dokumente und Logo als Dateien liegen.

| Was | Wo | Inhalt |
|---|---|---|
| Datenbank | Volume `assets_db` (MySQL) | Alle Stamm-, Bestands-, Bewegungs-, Einkaufs-, Lizenz- und Benutzerdaten, Audit-Log, Einstellungen, Dokument-Metadaten |
| Dateien | Volume `assets_uploads` (`storage/uploads/`) | Hochgeladene Dokumente (`documents/JJJJ/MM/…`), noch nicht abgeschlossene Importdateien (`imports/`) |
| Etikettenlogo | Volume `assets_labels` (`storage/labels/`) | Das im Etikettenlayout hochgeladene Logo |
| Konfiguration | `.env` im Projektverzeichnis | Datenbankzugang, Zeitzone, Session-Einstellungen, AD-Anbindung – enthält Geheimnisse, getrennt und verschlüsselt sichern |

Das Volume `assets_logs` (Anwendungslogs) ist für den Betrieb nicht erforderlich und kann optional gesichert werden.

## Backup erstellen

Alle Befehle im Projektverzeichnis (dort, wo `docker-compose.yml` liegt). `$BACKUP` ist ein Zielverzeichnis, z. B. `/backup/assets/$(date +%F)`.

### 1. Datenbank

```bash
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" \
  --single-transaction --quick --routines --triggers --set-gtid-purged=OFF \
  --default-character-set=utf8mb4 "$MYSQL_DATABASE"' | gzip > "$BACKUP/assets-db.sql.gz"
```

`--single-transaction` liefert einen konsistenten Stand aller InnoDB-Tabellen, ohne die Anwendung zu sperren; das Backup kann im laufenden Betrieb erstellt werden.

### 2. Dateien

```bash
docker compose exec -T app tar -C /var/www/html/storage -czf - uploads labels > "$BACKUP/assets-files.tar.gz"
```

### 3. Konfiguration

```bash
cp .env "$BACKUP/env"
```

### Reihenfolge und Konsistenz

Zuerst die Datenbank, direkt danach die Dateien sichern. Wird zwischen beiden Schritten ein Dokument hochgeladen, existiert im Datei-Backup eine Datei ohne Metadaten – das ist harmlos. Umgekehrt (Metadaten ohne Datei) würde beim Öffnen ein Fehler angezeigt; daher die Datenbank **nicht nach** den Dateien sichern. Für einen garantiert konsistenten Stand die Anwendung kurz anhalten (`docker compose stop app`), beide Schritte ausführen, `docker compose start app`.

### Automatisierung

Beispiel für einen täglichen Cronjob (Host), der 30 Tage aufbewahrt:

```bash
#!/bin/sh
set -eu
cd /opt/assets
BACKUP=/backup/assets/$(date +%F)
mkdir -p "$BACKUP"
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --set-gtid-purged=OFF "$MYSQL_DATABASE"' | gzip > "$BACKUP/assets-db.sql.gz"
docker compose exec -T app tar -C /var/www/html/storage -czf - uploads labels > "$BACKUP/assets-files.tar.gz"
cp .env "$BACKUP/env"
find /backup/assets -maxdepth 1 -type d -mtime +30 -exec rm -rf {} +
```

Empfehlungen: Backups auf ein anderes System oder Medium kopieren, Zugriff auf `env` und den Dump einschränken (sie enthalten Passwort-Hashes und Zugangsdaten), und **regelmäßig eine Testwiederherstellung** in einer separaten Umgebung durchführen.

## Wiederherstellen

Voraussetzung: Docker-Host mit dem Projektverzeichnis (Quellcode in derselben oder einer neueren Version als das Backup).

1. **Konfiguration**: `cp "$BACKUP/env" .env`. Bei einem Umzug auf einen anderen Host gegebenenfalls `APP_URL` und `SESSION_SECURE` anpassen; die Datenbankpasswörter müssen zum Dump nicht passen (MySQL-Benutzer werden beim ersten Start aus `.env` angelegt).
2. **Datenbank starten**, Anwendung noch nicht:
   ```bash
   docker compose up -d mysql
   docker compose exec mysql sh -c 'until mysqladmin ping -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent; do sleep 1; done'
   ```
3. **Dump einspielen** (ersetzt den Inhalt der Datenbank):
   ```bash
   gunzip -c "$BACKUP/assets-db.sql.gz" | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 "$MYSQL_DATABASE"'
   ```
4. **Dateien einspielen**:
   ```bash
   docker compose up -d --no-start app   # legt Container und Volumes an
   docker compose run --rm --no-deps --entrypoint sh app -c 'rm -rf storage/uploads/* storage/labels/*' 
   docker compose run --rm --no-deps --entrypoint sh -T app -c 'tar -C /var/www/html/storage -xzf -' < "$BACKUP/assets-files.tar.gz"
   docker compose run --rm --no-deps --entrypoint sh app -c 'chown -R www-data:www-data storage'
   ```
5. **Anwendung starten**: `docker compose up -d`. Der Entrypoint führt ausstehende Migrationen aus, falls der Quellcode neuer ist als der Dump (`schema_migrations` steuert, was noch fehlt).
6. **Prüfen**: `curl -fsS http://localhost:8080/health`, anmelden, ein Asset mit Dokument öffnen, Etikettenvorschau mit Logo aufrufen, Audit-Log ansehen.

### Einzelne Tabelle oder Zeitpunkt

Der Dump ist eine reine SQL-Datei; einzelne Tabellen lassen sich mit `sed -n '/^-- Table structure for table `assets`/,/^-- Table structure/p'` extrahieren und in eine Hilfsdatenbank laden, um Daten gezielt zu vergleichen. Ein Point-in-time-Recovery ist nur mit aktiviertem Binärlog möglich (Standard in `docker-compose.yml`: nicht konfiguriert); für die typischen Datenmengen dieser Anwendung genügen tägliche Dumps.

## Hinweise

- Alle Zeitstempel in der Datenbank sind UTC ([Datenmodell](datenmodell.md)) – bei Wiederherstellung auf einem Host mit anderer Zeitzone ändert sich nichts an den Daten.
- Nach einem Restore aus einem älteren Backup fehlen neu vergebene Inventarnummern in `inventory_sequences`; die Anwendung vergibt dann Nummern erneut, die auf bereits gedruckten Etiketten stehen können. Nach einem Restore daher zwischenzeitlich angelegte Assets abgleichen.
- Die Sessions liegen im PHP-Dateisystem des Containers und werden nicht gesichert; nach einem Restore müssen sich alle Benutzer neu anmelden.
- Bei AD-Anbindung: Mitarbeiterdaten werden beim nächsten Synchronisationslauf (`bin/sync-ad.php`) aktualisiert; ein Restore erfordert dafür keine Sonderbehandlung.
