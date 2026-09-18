# agentsindex.md – Übersicht für Coding-Agenten

> **Zweck:** Dieser Index gibt Coding-Agenten (und neuen Entwicklern) einen vollständigen,
> strukturierten Überblick über Funktionen, Architektur und Workflow dieses Repositorys,
> damit sie schnell und korrekt Änderungen vornehmen können. Alle Pfadangaben sind relativ
> zum Repository-Root.

---

## 1. Was ist das Projekt?

**Intranet-Landingpage** – die zentrale Startseite eines Firmen-Intranets. Sie zeigt:

- **Kacheln** für interne Anwendungen (Navigation, hierarchisch mit Unterseiten und Textseiten)
- eine **Telefonliste**, gespeist aus dem Active Directory (LDAP)
- **Klickstatistiken** je Kachel
- einen **vollständigen Administrationsbereich** (CRUD, Design, AD-Konfiguration, Statistik, Benutzer)

Grundprinzip: **komplett ohne Frameworks, ohne CDNs, ohne externe Abhängigkeiten.**
Alles (Autoloader, Router, Container, View, Migrator, Testrunner) ist selbst geschrieben.

---

## 2. Technologie-Stack

| Ebene | Technologie |
| --- | --- |
| Backend | PHP 8.2+ (getestet mit 8.3), PDO/MySQL |
| Frontend | Vanilla JavaScript + handgeschriebenes CSS (keine Frameworks, keine externen Fonts/Icons) |
| Datenbank | MySQL 8 / MariaDB 11 (utf8mb4) |
| Web | Apache mit `mod_rewrite`, DocumentRoot `public/` |
| Betrieb | Docker Compose (Dienste `app`, `db`, `sync`, optional `phpmyadmin`) |
| Abhängigkeiten | **keine** – kein Composer, kein npm, kein CDN |

Benötigte PHP-Erweiterungen: `pdo_mysql`, `ldap`, `mbstring`, `json`, `openssl`.

---

## 3. Schnellstart (Build, Start, Test)

### Docker (empfohlener Weg)

```bash
cp .env.example .env        # mindestens DB_PASSWORD und DB_ROOT_PASSWORD setzen
docker compose up -d        # Seite unter http://<host>:8080
docker compose --profile tools up -d phpmyadmin   # optional, http://<host>:8081
```

Beim ersten Start führt der Container automatisch aus:
1. Warten auf die Datenbank
2. `scripts/migrate.php` – Schema anlegen
3. `scripts/seed.php` – Beispielnavigation (nur wenn Navigation leer, steuerbar über `SEED_ON_START`)
4. `scripts/create_admin.php` – erstes Adminkonto, falls keines existiert

### Tests ausführen

```bash
php tests/run.php
```

### Syntaxprüfung (alle PHP-Dateien)

```bash
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

### CLI-Werkzeuge (alle unter `scripts/`)

| Skript | Zweck |
| --- | --- |
| `migrate.php` | Führt ausstehende SQL-Migrationen aus (`schema_migrations`-Tabelle) |
| `seed.php` | Beispielnavigation + Standardeinstellungen (nur bei leerer Navigation) |
| `create_admin.php` | Legt ein Administrationskonto an |
| `sync_ad.php` | Einmaliger AD-Abgleich |
| `sync_worker.php` | Dauerlauf der AD-Synchronisation (Container `sync`) |
| `purge_clicks.php` | Löscht Klickdaten älter als N Tage (Standard `CLICK_RETENTION_DAYS=400`) |

---

## 4. Architektur im Überblick

```
HTTP-Anfrage
    │
    ▼
public/index.php (Front-Controller)
    │  bootstrap.php (Autoloader, Config, Fehlerbehandlung)
    ▼
Router (exakte Pfade, Methoden, Middleware-Gruppen)
    │  Middleware: $requireAuth / $requireAdmin
    ▼
Controller (app/Controllers/…)
    │  Validierung, CSRF-Prüfung, Service-Aufrufe
    ▼
Service (app/Services/…)          ← Geschäftslogik, Validierung
    │
    ▼
Repository (app/Repositories/…)   ← SQL-Zugriff über PDO
    │
    ▼
PDO (app/Core/Database.php)
```

**Zentraler Request-Lifecycle:**

1. `public/index.php` lädt `bootstrap.php` (Autoloader registrieren, `.env` laden, Config booten, Logger, Error-Handler).
2. `Request::fromGlobals()` normalisiert Methode, Pfad, Query/POST/Files.
3. `Session::start()` + CSP-Nonce erzeugen.
4. Der `Router` matcht die Route (exakte Pfade) und führt Gruppen-Middleware aus.
5. Der Controller rendert über `View` ein PHP-Template oder liefert eine `Response` (HTML/JSON/Redirect/Download).
6. Sicherheitsheader werden ergänzt, die `Response` wird gesendet.
7. `HttpException` → Fehlerseite (404/405/419/403…), sonst `Throwable` → 500 (mit Log).

---

## 5. Verzeichnisstruktur

```
app/            Anwendungscode
  Contracts/    Interfaces (Abstraktionen für Repositories/LDAP)
  Controllers/  HTTP-Controller (inkl. Unterordner Admin/)
  Core/         Autoloader, Config, Container, Database, Env, Logger,
                Request, Response, Router, View
  Exceptions/   HttpException, ValidationException
  Repositories/ Datenbankzugriff (PDO)
  Security/     Auth, Csrf, Session
  Services/     Geschäftslogik (19 Klassen)
  Support/      Dates, Html, Sanitizer, Validator
config/         Konfiguration aus Umgebungsvariablen (app, database, ldap)
database/
  migrations/   SQL-Migrationen (001…008)
docker/         Dockerfile, Entrypoint, PHP-/MySQL-Konfiguration
public/         DocumentRoot: index.php (Front-Controller), assets, .htaccess, manuals
scripts/        CLI-Werkzeuge (Migration, Seed, Admin, Sync, Bereinigung)
storage/        logs/ und uploads/ (außerhalb des DocumentRoot)
tests/          Dependency-freier Testrunner + Unit-Tests
views/          PHP-Templates (admin, errors, landing, layouts, pages, partials, phonebook)
```

---

## 6. Core-Schicht (`app/Core/`)

| Klasse | Verantwortung |
| --- | --- |
| `Autoloader` | Minimaler PSR-4-Autoloader (Namespace `App` → `app/`, bewusst ohne Composer) |
| `Config` | Lädt `config/*.php` und liefert Werte per Punktnotation (`app.debug`, `ldap.host`) |
| `Container` | Statischer Service-Container (Singleton pro Request), injiziert Abhängigkeiten |
| `Database` | Einziger Ort für den PDO-Verbindungsaufbau (Singleton) |
| `Env` | Umgebungsvariablen inkl. `.env`-Loader und `<VAR>_FILE`-Docker-Secret-Unterstützung |
| `Logger` | Zentraler Datei-Logger (`storage/logs/app.log`), maskiert Passwörter/Tokens |
| `Request` | Immutabler Wrapper für Methode, Pfad, Query, POST, Files, HTTPS-Erkennung |
| `Response` | Fabriken: `html`, `text`, `json`, `redirect`, `noContent`, `download`; `send()` |
| `Router` | Exakte Pfade, GET/POST, verschachtelte Middleware-Gruppen |
| `View` | Rendering reiner PHP-Templates mit Layout-Unterstützung (Dot-Notation → Pfad) |

**Wichtige Konventionen:**

- **Alles ist `final`** bzw. minimal gehalten – keine Vererbungshierarchien außer `Controller` und `Repository`.
- **Dependency Injection per Konstruktor** (`private readonly X $dep`); Auflösung ausschließlich über `Container`.
- `Container::reset()` existiert und wird in Tests verwendet.
- `bootstrap.php` wird **sowohl vom Webserver als auch von allen CLI-Skripten** geladen.
- Globale Hilfsfunktion `app_logger()` (in `bootstrap.php` definiert).

---

## 7. App-Schichten

### Controller (`app/Controllers/`)

- Basisklasse `Controller` stellt `view()`, `requireValidCsrf()`, `redirect()`, `assetVersion()` bereit.
- **Öffentlich:** `LandingController`, `PageController` (Unterseiten/Textseiten), `PhonebookController`, `ClickController`, `LogoController`, `BackgroundImageController`, `ImportantLinkIconController`, `HealthController`.
- **Admin (`app/Controllers/Admin/`):** `AuthController`, `DashboardController`, `NavigationController`, `ImportantLinkController`, `EmergencyNumberController`, `AnnouncementController`, `DescriptionController`, `DesignController`, `LdapController`, `StatisticsController`, `AdminUserController`, `ImportExportController`, plus Basis `AdminController`.

### Services (`app/Services/`) – Geschäftslogik

`AdSyncService`, `AdminUserService`, `AnnouncementService`, `BackgroundImageService`,
`BackupService`, `EmergencyNumberService`, `FaviconService`, `ImportService`,
`ImportantLinkService`, `LdapAttributeMapper`, `LdapClient`, `LogoService`,
`NavigationService`, `PhonebookService`, `SettingsService`, `StatisticsService`, `ThemeService`.

Muster: Service erhält Repositories per Konstruktor, validiert Eingaben
(`Validator`/`Sanitizer`) und wirft bei Fehlern `ValidationException` mit einem
`array<string,string>` an Feld→Fehlermeldung.

### Repositories (`app/Repositories/`)

`AdminUserRepository`, `AnnouncementRepository`, `ClickRepository`,
`EmergencyNumberRepository`, `ImportantLinkRepository`, `NavigationRepository`,
`PhonebookRepository`, `SettingsRepository`, `SyncLogRepository`, plus Basis `Repository`
(stellt `PDO $pdo` bereit; Test kann eine eigene `PDO`-Instanz injizieren).

### Security (`app/Security/`)

- `Auth` – Session-Authentifizierung, Rollen `admin`/`redaktion`, Login-Lockout (5 Versuche / 300 s), Idle-Timeout, Session-Regeneration.
- `Csrf` – Token-Erzeugung/-Validierung.
- `Session` – Session-Verwaltung (Start, Flash, Regenerate).

### Support (`app/Support/`)

- `Html::e()` – zentrales Escaping (htmlspecialchars, UTF-8).
- `Validator` – Eingabe-/URL-/Farb-/Typ-Prüfung.
- `Sanitizer` – HTML-Reinigung (Rich-Text).
- `Dates` – Datums-/Zeitraum-Helfer (u. a. für die Statistik).

### Contracts (`app/Contracts/`) & Exceptions (`app/Exceptions/`)

- Interfaces: `AdminUserStoreInterface`, `LdapClientInterface`, `PhonebookStoreInterface`, `SyncLogStoreInterface`.
- Exceptions: `HttpException` (mit `statusCode()`), `ValidationException` (mit `errors()`).

---

## 8. Routing-Tabelle

Definiert zentral in `public/index.php`.

### Öffentlich (keine Anmeldung)

| Methode | Pfad | Handler |
| --- | --- | --- |
| GET | `/` | `LandingController::index` |
| GET | `/unterseite` | `PageController::subpage` |
| GET | `/seite` | `PageController::page` |
| GET | `/telefonliste` | `PhonebookController::index` |
| GET | `/api/telefonliste` | `PhonebookController::search` |
| POST | `/api/klick` | `ClickController::store` |
| GET | `/logo` | `LogoController::show` |
| GET | `/hintergrundbild` | `BackgroundImageController::show` |
| GET | `/wichtige-links/icon` | `ImportantLinkIconController::show` |
| GET | `/health` | `HealthController::index` |
| GET/POST | `/admin/login` | `AuthController::showLogin` / `login` |

### Admin (Middleware `$requireAuth`, angemeldet)

`POST /admin/logout`, `GET /admin` (Dashboard) und alle `wichtige-links`-Routen.

### Admin (Middleware `$requireAdmin`, nur Rolle `admin`)

Alle übrigen Admin-Routen: `navigation`, `notfallnummern`, `mitteilungen`,
`beschreibungen`, `design`, `ad`, `statistik` (+ `admin/api/statistik`), `benutzer`, `sicherung`.

**Middleware-Verhalten:** `$requireAuth` → Redirect auf `/admin/login` (bzw. JSON 401 bei `/admin/api/*`);
`$requireAdmin` → HTTP 403 (bzw. JSON 403 bei `/admin/api/*`).

**Rollen:** `admin` (voller Zugriff inkl. Benutzerverwaltung) · `redaktion` (nur „Wichtige Links“).

---

## 9. Datenbankschema

Migrationen liegen in `database/migrations/` (numerisch sortiert, werden von `migrate.php` angewendet und in `schema_migrations` protokolliert).

| Tabelle | Zweck | Wichtige Spalten |
| --- | --- | --- |
| `navigation_items` | Kacheln/Unterseiten/Textseiten | `type` (external/internal/subpage/page), `parent_id`, `content`, `background_color`, `background_opacity`, `sort_order`, `active` |
| `settings` | Schlüssel-Wert-Einstellungen | `setting_key` (unique), `setting_value` |
| `phonebook` | AD-synchronisierte Telefonliste | `external_id` (unique), Name, `phone`, `phone_digits`, `mobile`, `department`, `active` |
| `click_events` | Klickstatistik | `navigation_id` (FK, SET NULL), `clicked_at` |
| `admin_users` | Administrationskonten | `username` (unique), `password_hash`, `role` (admin/redaktion), `active` |
| `sync_log` | Protokoll der AD-Läufe | `status` (running/success/error), `processed`, `deactivated`, `message` |
| `audit_log` | Änderungsprotokoll (optional) | `admin_user_id`, `action`, `subject` |
| `emergency_numbers` | Notfallnummern-Kacheln | `label`, `phone`, `sort_order`, `active` |
| `important_links` | „Wichtige Links“ | `title`, `url`, `icon_file`, `icon_mime`, `active` |
| `announcements` | Mitteilungs-Overlay | `title`, `message`, `active` |

**Konventionen:** `InnoDB`, `utf8mb4`/`utf8mb4_unicode_ci`, `TIMESTAMP`-Spalten `created_at`/`updated_at`, `TINYINT(1)` für Booleans (`active`), Fremdschlüssel mit `ON DELETE SET NULL`/`ON UPDATE CASCADE`.

---

## 10. Konfiguration

**Vorrangregel: Umgebungsvariablen liefern die Grundeinstellung, die Tabelle `settings` überschreibt sie** (außer dem LDAP-Bind-Passwort, das nur aus ENV/Docker-Secret kommt).

- `config/app.php`, `config/database.php`, `config/ldap.php` lesen die Werte über `Env::get()`.
- Jede Variable unterstützt die Datei-Variante `<NAME>_FILE` für Docker-Secrets.
- Vollständige Liste der Variablen: siehe `README.md` (Abschnitt „Konfiguration“) und `.env.example`.

**Wichtigste Variablen:** `APP_URL`, `APP_DEBUG`, `APP_FORCE_SECURE_COOKIES`, `APP_SESSION_IDLE_TIMEOUT`, `DB_*`, `LDAP_*` (inkl. `LDAP_ATTR_*`-Mapping), `SEED_ON_START`, `CLICK_RETENTION_DAYS`, `ADMIN_USERNAME`/`ADMIN_PASSWORD`.

---

## 11. Sicherheitsmodell

- **DB:** ausschließlich Prepared Statements (`ATTR_EMULATE_PREPARES = false`).
- **Ausgabe:** konsequent `Html::e()` (htmlspecialchars, UTF-8); kein ungefiltertes Echo.
- **CSRF:** jedes schreibende Formular; ungültiger Token → HTTP 419.
- **Header:** `Content-Security-Policy` (`script-src 'self'`, Nonce für das Farbschema), `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`.
- **Sessions:** `HttpOnly`, `SameSite=Lax`, optional `Secure`; Regeneration nach Login/Logout; Idle-Timeout.
- **Login:** Sperre nach 5 Fehlversuchen (300 s), Timing-Attacken-Schutz (Dummy-Hash), `password_hash`/`password_verify`.
- **Uploads:** außerhalb des DocumentRoot (`storage/uploads`), Auslieferung über `/logo`/`/hintergrundbild` mit MIME-Prüfung; Größenlimits `APP_MAX_LOGO_BYTES` / `APP_MAX_BACKGROUND_BYTES`.
- **URL-Validierung:** nur `http`, `https` und interne Pfade (kein `javascript:`, `data:`, `//host`).
- **Logging:** Passwörter/Tokens werden im Log maskiert.

---

## 12. Wichtige Workflows

### Container-Startup (`docker/php/entrypoint.sh`)

1. `storage/logs`, `storage/uploads` anlegen + Rechte.
2. Auf die Datenbank warten (bis 60 × 2 s).
3. Rolle `app`: `migrate.php` → ggf. `seed.php` (`SEED_ON_START`) → ggf. `create_admin.php`.
4. Rolle `sync`: kurz warten (Migrationen abwarten), dann `sync_worker.php` (Dauerschleife).
5. `exec "$@"` (App: `apache2-foreground`; Sync: `php scripts/sync_worker.php`).

### AD-Synchronisation

- Einzellauf: `php scripts/sync_ad.php` oder Schaltfläche im Adminbereich.
- Dauerlauf: `php scripts/sync_worker.php` (Intervall `LDAP_SYNC_INTERVAL`).
- **Datensicherheit:** nicht erreichbares/leeres AD → *kein* Schreiben, letzter Stand bleibt aktiv.
- Nicht mehr vorhandene Personen → `active = 0` (kein Löschen); deaktivierte AD-Konten (`ACCOUNTDISABLE`) werden übersprungen/deaktiviert.
- Jeder Lauf wird in `sync_log` protokolliert und im Adminbereich angezeigt.

### Tests

- Dependency-freier Testrunner: `php tests/run.php`.
- `tests/Support/Runner.php`, `Assert.php`, `Fakes.php` (Fake-Implementierungen der Contracts).
- Unit-Tests in `tests/Unit/*Test.php` (u. a. `AdSyncServiceTest`, `NavigationServiceTest`, `SecurityTest`, `StatisticsServiceTest`, `ValidatorTest`).
- Tests nutzen `Database::set()` mit einer SQLite-In-Memory-PDO bzw. Fake-Repositories.

---

## 13. Konventionen & Coding-Standards

- `declare(strict_types=1);` in jeder Datei.
- Namespace `App\…`, Klassen `final`, Abhängigkeiten `private readonly` per Konstruktor.
- PHP-Docblocks mit `@param`/`@return` (Array-Formen wie `list<array<string,mixed>>`).
- UI-Texte und Kommentare auf **Deutsch**; Code-Bezeichner auf Englisch.
- Ausgabe immer über `Html::e()`; keine SQL-String-Konkatenation für Werte.
- Neue Datenbankänderungen als **neue nummerierte Migration** (nicht vorhandene Migrationsdateien editieren).
- Neue Funktionen folgen dem Muster **Route → Controller → Service → Repository**.
- Kein Composer, kein npm, keine externen Ressourcen – alles selbst implementieren.

---

## 14. Häufige Änderungsaufgaben

| Aufgabe | Vorgehen |
| --- | --- |
| Neue Admin-Seite/CRUD | Route in `public/index.php` (in der richtigen Gruppe), Controller in `app/Controllers/Admin/`, Service, Repository, Migration, Views in `views/admin/`, Test in `tests/Unit/` |
| Neue Einstellung | `SettingsService`/`settings`-Tabelle nutzen (Key in `settings`), ggf. ENV-Default in `config/` + `.env.example` + README ergänzen |
| Schema-Änderung | Neue `database/migrations/0NN_*.sql`, `php scripts/migrate.php` |
| LDAP-Attribut-Mapping | `config/ldap.php` / `LDAP_ATTR_*` bzw. Adminbereich „Active Directory“ |
| Frontend-Styling | `public/assets/css/app.css` (handgeschrieben, Theme-Variablen über `ThemeService`) |

---

## 15. Referenzdokumente

- `README.md` – ausführliche Projektdokumentation (Funktionsumfang, Docker, Konfiguration, Betrieb, Sicherheit).
- `docs/manuals/anwenderhandbuch.pdf` / `administratorhandbuch.pdf` (Quellen als HTML unter `docs/manuals/`).
- `docs/screenshots/` – Screenshots der öffentlichen und Admin-Bereiche.
