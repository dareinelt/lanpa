# Intranet-Landingpage

Zentrale Startseite für das Intranet: Kacheln für interne Anwendungen, eine aus dem
Active Directory gespeiste Telefonliste, Klickstatistiken und ein vollständiger
Administrationsbereich – ohne Frameworks, ohne CDNs, ohne externe Abhängigkeiten.

- **Backend:** PHP 8.2+ (getestet mit 8.3), PDO/MySQL, eigene Autoloader-/Router-/View-Schicht
- **Frontend:** Vanilla JavaScript und handgeschriebenes CSS (keine Frameworks, keine externen Fonts/Icons)
- **Datenbank:** MySQL 8 / MariaDB 11 (utf8mb4)
- **Betrieb:** Docker Compose (App + Datenbank + Synchronisationsdienst, optional phpMyAdmin)

---

## 1. Funktionsumfang

| Bereich | Beschreibung |
| --- | --- |
| Landingpage | Kacheln aller aktiven Navigationselemente, Kurz- und Langbeschreibung, Klickzählung; aufklappbarer Bereich „Wichtige Links“ (automatisch alphabetisch sortiert, mit automatisch ermitteltem Favicon) |
| Telefonliste | Suche über Name, Vorname, Nachname, Abteilung und Telefonnummer (Live-Suche, Paginierung); Einträge ohne Telefon- und Mobilnummer sind nur für angemeldete Administratoren sichtbar |
| AD-Synchronisation | LDAP/LDAPS-Abgleich in die lokale Datenbank, konfigurierbares Intervall und Attribut-Mapping |
| Administration | Navigation (CRUD, Sortierung, Aktivierung), Beschreibungen, Design/Logo, AD-Konfiguration, Statistik |
| Statistik | Klicks je Element und Tag, Zeiträume 3/7/14/30/90/365 Tage, selbst gerendertes SVG-Liniendiagramm |
| Darstellung | Hell-/Dunkelmodus (System oder manuell), frei konfigurierbare Farben, eigenes Logo |
| Barrierefreiheit | Semantisches HTML, Tastaturbedienung, sichtbarer Fokus, ARIA-Beschriftungen, Kontrastwahl nach WCAG-Leuchtdichte |

---

## 2. Schnellstart mit Docker

Voraussetzung: Docker mit Compose-Plugin.

```bash
cp .env.example .env      # Werte anpassen (mindestens DB_PASSWORD und DB_ROOT_PASSWORD)
docker compose up -d
```

Danach ist die Seite unter `http://<host>:8080` erreichbar (Port über `APP_PORT` konfigurierbar).

Beim ersten Start führt der Container automatisch aus:

1. Warten auf die Datenbank
2. `scripts/migrate.php` – Schema anlegen
3. `scripts/seed.php` – Beispielnavigation und Standardeinstellungen (nur wenn die Navigation leer ist)
4. `scripts/create_admin.php` – erstes Administrationskonto, sofern noch keines existiert

Ist `ADMIN_PASSWORD` leer, wird ein Zufallspasswort erzeugt und **einmalig** im Containerlog ausgegeben:

```bash
docker compose logs app | grep "Generiertes Passwort"            # Linux
docker compose logs app | Select-String "Generiertes Passwort"   # PowerShell
```

Optionales Datenbankwerkzeug:

```bash
docker compose --profile tools up -d phpmyadmin   # http://<host>:8081
```

### Enthaltene Dienste

| Dienst | Zweck | Healthcheck |
| --- | --- | --- |
| `app` | PHP 8.3 + Apache, DocumentRoot `public/` | `GET /health` |
| `db` | MySQL 8, benanntes Volume `db_data` | `mysqladmin ping` |
| `sync` | Dauerlauf der AD-Synchronisation (`scripts/sync_worker.php`) | – |
| `phpmyadmin` | optional, Profil `tools` | – |

---

## 3. Installation ohne Docker

1. PHP 8.2+ mit den Erweiterungen `pdo_mysql`, `ldap`, `mbstring`, `json`, `openssl` bereitstellen.
2. Repository in das Zielverzeichnis kopieren, **DocumentRoot auf `public/`** setzen (`mod_rewrite` aktivieren).
3. `.env.example` nach `.env` kopieren und ausfüllen.
4. Datenbank und Benutzer anlegen (utf8mb4).
5. Einrichten:

```bash
php scripts/migrate.php
php scripts/seed.php
php scripts/create_admin.php admin
```

6. Schreibrechte für den Webserver auf `storage/logs` und `storage/uploads` vergeben.
7. Synchronisation per Cron einplanen, z. B. stündlich:

```
0 * * * * /usr/bin/php /var/www/intranet/scripts/sync_ad.php >> /var/log/intranet-sync.log 2>&1
```

---

## 4. Konfiguration

Es gilt: **Umgebungsvariablen liefern die Grundeinstellung, die Tabelle `settings` überschreibt sie.**
Alles außer dem LDAP-Bind-Passwort ist im Administrationsbereich pflegbar.

### Wichtige Umgebungsvariablen

| Variable | Bedeutung | Standard |
| --- | --- | --- |
| `APP_URL` | Öffentliche Adresse der Seite | `http://localhost:8080` |
| `APP_DEBUG` | Fehlerdetails anzeigen (nur Entwicklung) | `false` |
| `APP_FORCE_SECURE_COOKIES` | Session-Cookie nur über HTTPS | `false` |
| `APP_SESSION_IDLE_TIMEOUT` | Automatische Abmeldung nach Inaktivität (Sekunden) | `3600` |
| `APP_MAX_LOGO_BYTES` | Maximale Logogröße | `524288` |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Datenbankzugang | – |
| `LDAP_HOST`, `LDAP_PORT`, `LDAP_BASE_DN`, `LDAP_BIND_DN` | AD-Zugang | – |
| `LDAP_PASSWORD` / `LDAP_PASSWORD_FILE` | Bind-Passwort (nur ENV bzw. Docker-Secret) | – |
| `LDAP_USE_TLS`, `LDAP_VERIFY_CERT` | Transportverschlüsselung und Zertifikatsprüfung | `true` |
| `LDAP_SYNC_INTERVAL` | Intervall des Synchronisationsdienstes (Sekunden) | `3600` |
| `LDAP_ATTR_*` | Attributzuordnung (z. B. `LDAP_ATTR_PHONE=telephoneNumber`) | AD-Standardwerte |
| `SEED_ON_START` | Beispielnavigation beim Containerstart anlegen | `true` |
| `CLICK_RETENTION_DAYS` | Aufbewahrung der Klickdaten für `purge_clicks.php` | `400` |

Jede Variable unterstützt zusätzlich die Datei-Variante `<NAME>_FILE` für Docker-Secrets.

### Vor dem Produktivbetrieb zwingend anzupassen

- `DB_PASSWORD`, `DB_ROOT_PASSWORD` – eigene, starke Passwörter
- `ADMIN_USERNAME` / `ADMIN_PASSWORD` bzw. das automatisch erzeugte Passwort sofort ändern
- `APP_URL` auf die reale Adresse setzen, `APP_FORCE_SECURE_COOKIES=true` bei HTTPS
- alle `LDAP_*`-Werte auf das produktive Active Directory setzen (`LDAP_PASSWORD` niemals im Repository)
- die Platzhalter-URLs `https://*.example.internal` der Kacheln im Adminbereich durch die echten Anwendungsadressen ersetzen
- `SEED_ON_START=false` setzen, sobald die Navigation gepflegt ist

---

## 5. Administration

Aufruf: `/admin` (Anmeldung mit dem angelegten Konto).

| Menüpunkt | Funktion |
| --- | --- |
| Übersicht | Kennzahlen zu Navigation, Telefonliste, Klicks und letztem AD-Lauf |
| Navigation | Anlegen, Bearbeiten, Aktivieren/Deaktivieren, Sortieren, Löschen |
| Wichtige Links | Anlegen, Bearbeiten, Aktivieren/Deaktivieren, Löschen; Favicon wird automatisch geladen, Reihenfolge stets alphabetisch (keine manuelle Sortierung) |
| Beschreibungen | Seitentitel, Untertitel (ein-/ausblendbar), Footer-Text, Beschreibungstexte, Anzeigemodus (`hover`, `expand`, `both`) |
| Design | Farbschema (Hell/Dunkel), Logo hochladen oder entfernen |
| Active Directory | Server, Verschlüsselung, Base DN, Bind DN, Filter, Attributzuordnung, Intervall, manueller Testlauf |
| Statistik | Klickverlauf als SVG-Diagramm, Zeitraumauswahl, Summen je Element |
| Benutzer | Benutzerverwaltung: Konten anlegen/bearbeiten/deaktivieren/löschen, Rollenvergabe (nur für Administratoren) |

Rollen: **Administrator** darf den gesamten Adminbereich verwalten, inkl. Benutzerverwaltung. Die Gruppe
**Redaktion** darf ausschließlich die „Wichtigen Links“ bearbeiten; alle anderen Admin-Bereiche sind für sie
nicht sichtbar und nicht aufrufbar (HTTP 403). Die Landingpage und Telefonliste bleiben weiterhin ohne
Anmeldung erreichbar.

Sicherheitsmerkmale: CSRF-Token bei jedem Formular, Anmeldesperre nach fünf Fehlversuchen (300 s),
Sitzungserneuerung nach der Anmeldung, automatische Abmeldung bei Inaktivität, Passwörter als `password_hash`.

---

## 6. Betrieb

### AD-Synchronisation

- Einmaliger Lauf: `php scripts/sync_ad.php` (oder Schaltfläche im Adminbereich)
- Dauerlauf: `php scripts/sync_worker.php` (Container `sync`)
- **Datensicherheit:** Ist das AD nicht erreichbar oder liefert es keine Datensätze, wird
  *nichts* geschrieben und *nichts* deaktiviert – der letzte gültige Stand bleibt aktiv.
  Jeder Lauf wird in `sync_log` protokolliert und im Adminbereich angezeigt.
- Personen, die im AD nicht mehr enthalten sind, werden auf `active = 0` gesetzt (kein Löschen).
- Im AD deaktivierte Benutzerkonten (`userAccountControl`-Bit `ACCOUNTDISABLE`) werden beim
  Import übersprungen; bereits importierte, inzwischen deaktivierte Konten werden dadurch
  ebenfalls auf `active = 0` gesetzt.

### Protokolle

- Anwendungsprotokoll: `storage/logs/app.log` (Passwörter/Token werden maskiert)
- Container: `docker compose logs -f app` bzw. `docker compose logs -f sync`

### Sicherung

```bash
docker compose exec db mysqldump -u root -p intranet > backup-$(date +%F).sql
docker run --rm -v lanpa_app_storage:/data -v "$PWD":/backup alpine tar czf /backup/storage.tgz /data
```

Wiederherstellung:

```bash
docker compose exec -T db mysql -u root -p intranet < backup-2026-01-01.sql
```

### Datenpflege

```bash
php scripts/purge_clicks.php 400   # Klickdaten älter als 400 Tage löschen
```

---

## 7. Entwicklung

```
app/            Anwendungscode (Core, Controllers, Services, Repositories, Security, Support)
config/         Konfiguration aus Umgebungsvariablen
database/       SQL-Migrationen
docker/         Dockerfile, Entrypoint, PHP-/MySQL-Konfiguration
public/         DocumentRoot: Front-Controller und Assets
scripts/        CLI-Werkzeuge (Migration, Seed, Adminkonto, Synchronisation, Bereinigung)
storage/        Protokolle und Uploads (nicht im DocumentRoot)
tests/          Abhängigkeitsfreier Testrunner und Unit-Tests
views/          PHP-Templates
```

Architektur: `public/index.php` (Routing, Sicherheitsheader, CSP-Nonce) → Controller → Service → Repository → PDO.

Tests ausführen:

```bash
php tests/run.php
```

Syntaxprüfung:

```bash
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

---

## 8. Sicherheit

- Alle Datenbankzugriffe über vorbereitete Anweisungen (`ATTR_EMULATE_PREPARES = false`)
- Ausgabe konsequent über `Html::e()` (htmlspecialchars mit UTF-8)
- CSRF-Schutz für alle schreibenden Anfragen (HTTP 419 bei ungültigem Token)
- Sicherheitsheader: `Content-Security-Policy` (`script-src 'self'`, Nonce für das Farbschema),
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`
- Session-Cookies: `HttpOnly`, `SameSite=Lax`, optional `Secure`
- Uploads landen außerhalb des DocumentRoots und werden über `/logo` mit geprüftem MIME-Typ ausgeliefert
- URL-Prüfung erlaubt ausschließlich `http`, `https` und interne Pfade (kein `javascript:`, `data:` oder `//host`)
- Keine externen Ressourcen, kein Tracking, keine Cookies für Besucher außerhalb der Sitzung
