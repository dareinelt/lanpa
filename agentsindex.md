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
- optionale **Alarmierungen** per SMS-Gateway (an Gruppen oder Einzelrufnummern) und einen per SMS-Code **geschützten Zugriffsmodus**
- einen **SNMP-Agenten** (Container `snmp`) zur Überwachung der Dienste, des AD-Synchronisations-Workflows und der Gültigkeit des HTTPS-Zertifikats
- eine **Zertifikatsverwaltung** (Admin → Zertifikate (HTTPS)): CSR erstellen, Zertifikat (PEM/CRT) mit Vorschau importieren, aktives Zertifikat wählen; der `auth`-Container liefert damit HTTPS aus (sonst selbstsigniertes Notfall-Zertifikat, HTTP nur aus freigegebenen Quellnetzen)
- optional **Office** (Profil `office`): Nextcloud + Euro-Office DocumentServer hinter dem `auth`-Container, Rechte über Benutzer/AD-Gruppen, Intranet-Fußzeile, lokale KI für alle Benutzer (Nextcloud-Assistent, KI-Plugin der Editoren; Audio/Bilder im Adminbereich schaltbar), Sicherung – Details in `docs/office.md`

Grundprinzip: **komplett ohne Frameworks, ohne CDNs, ohne externe Abhängigkeiten.**
Alles (Autoloader, Router, Container, View, Migrator, Testrunner) ist selbst geschrieben.

---

## 2. Technologie-Stack

| Ebene | Technologie |
| --- | --- |
| Backend | PHP 8.5 (mindestens 8.4), PDO/MySQL |
| Frontend | Vanilla JavaScript + handgeschriebenes CSS (keine Frameworks, keine externen Fonts/Icons) |
| Datenbank | MySQL 9.7 LTS (utf8mb4, Image `mysql:${DB_IMAGE_TAG:-9.7.2}`) |
| Web | Apache mit `mod_rewrite`, DocumentRoot `public/` |
| Betrieb | Docker Compose (Dienste `app`, `db`, `sync`, `snmp`, `auth` (Einstieg/Reverse-Proxy, optional NTLM), optional `phpmyadmin`; Profil `office`: `nextcloud`, `nextcloud-cron`, `nextcloud-ai-worker`, `nextcloud-db`, `nextcloud-redis`, `eurooffice`, `office-backup`) |
| Monitoring | net-snmp-Agent im Container `snmp` (UDP 161, read-only Docker-Socket + `sync_log`) |
| Abhängigkeiten | **keine** – kein Composer, kein npm, kein CDN |

Benötigte PHP-Erweiterungen: `pdo_mysql`, `ldap`, `mbstring`, `json`, `openssl`, `zip`.

---

## 3. Schnellstart (Build, Start, Test)

### Docker (empfohlener Weg)

Assistierte Komplettinstallation (Paketprüfung/-installation, `.env` inkl. Secrets,
Start, Abschlussbericht in `install-reports/`): `./scripts/install.sh` – siehe
`docs/installation.md`. Manuell:

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
| `credentials.php` | Schlüssel für gespeicherte Zugangsdaten anlegen (`--key`), Alt-Werte aus der `.env` verschlüsselt übernehmen (Standard, beim App-Start), `--set-primary` (stdin `NAME=base64`, von `install.sh`), `--ldap-password` (für `office-setup.sh`), `--sso-sources` (für `sso-domains.sh`) |
| `sso-domains.sh` | Erzeugt `docker-compose.sso.yml` mit je einer auth-Instanz `auth-<kennung>` pro weiterer Domäne mit Windows-Anmeldung (ohne Zugangsdaten) |
| `purge_clicks.php` | Löscht Klickdaten älter als N Tage (Standard `CLICK_RETENTION_DAYS=400`) |
| `install.sh` | Assistierte Komplettinstallation (whiptail/dialog/Text): prüft und installiert Abhängigkeiten, kopiert `.env.example`, fragt Passwörter/Secrets ab, startet und prüft die Container, schreibt Abschlussbericht nach `install-reports/` (Doku: `docs/installation.md`) |
| `mysql-upgrade.sh` | Hebt ein bestehendes MySQL-8.0-Volume über einen temporären `mysql:8.4`-Container an (LTS-Pfad 8.0 → 8.4 → 9.7), sichert vorher nach `backups/mysql/`, stellt `mysql_native_password`-Konten um; `--check` (Exit 10 = nötig). Neue Installationen brauchen es nicht; `install.sh` ruft es bei vorhandenem 8.0-Volume auf |
| `install-systemd-service.sh` | Installiert die Landingpage als systemd-Service (Ubuntu ≥ 22.04; Autostart beim Boot, `docker compose up/down`) |

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
  Services/     Geschäftslogik (21 Klassen)
  Support/      Dates, Html, Sanitizer, Validator
config/         Konfiguration aus Umgebungsvariablen (app, database, ldap)
database/
  migrations/   SQL-Migrationen (001…018)
docker/         Dockerfiles, Entrypoints, PHP-/MySQL-Konfiguration, SNMP-Agent
public/         DocumentRoot: index.php (Front-Controller), assets, .htaccess, manuals
scripts/        CLI-Werkzeuge (Migration, Seed, Admin, Sync, Bereinigung, systemd-Installation, MySQL-Upgrade)
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
- **Öffentlich:** `LandingController`, `PageController` (Unterseiten/Textseiten), `PhonebookController`, `ClickController`, `LogoController`, `BackgroundImageController`, `ImportantLinkIconController`, `HealthController`, `AlarmTriggerController` (Alarm-Kacheln), `ProtectedAccessController` (Zugangscode-Seite), `SmsCodeController` (Code-Versand/-Prüfung).
- **Admin (`app/Controllers/Admin/`):** `AuthController`, `DashboardController`, `NavigationController`, `ImportantLinkController`, `EmergencyNumberController`, `PhonebookAdminController`, `AnnouncementController`, `DescriptionController`, `DesignController`, `LdapController`, `AlarmController`, `AlarmGroupController`, `ActivationNumberController`, `SnmpController`, `CertificateController` (Zertifikate/HTTPS), `StatisticsController`, `AdminUserController`, `ImportExportController`, `OfficeController`, `OfficeAppsController`, plus Basis `AdminController`.

### Services (`app/Services/`) – Geschäftslogik

`ActivationNumberService`, `AdSyncService`, `AdminUserService`, `AlarmGroupService`,
`AlarmService`, `AnnouncementService`, `BackgroundImageService`, `BackupService`,
`Office\OfficeConfigService` (Einstellungen Fußzeile/Kachel), `Office\OfficeHealthService` (Status/Diagnose, Probe per `OfficeProbeInterface`), `Office\OfficeBackupService` (Steuerung des Containers `office-backup`), `Office\OfficeAiService` (lokale KI: Einstellungen inkl. Audio/Bilder, `runtime.json` für Euro-Office, signierte Übergabe an `intranet_integration/api/ai`),
`Office\OfficeAppService` + `Office\OfficeAppCatalog` (Office-Apps unter der Kachel: Euro-Office-Webapps, Dateien, OWA; Freigabe per AD-Gruppe/App-Paket, ohne Zuordnung/ohne SSO keine Apps),
`EmergencyNumberService`, `FaviconService`, `ImportService`, `ImportantLinkService`,
`LdapAttributeMapper`, `LdapClient`, `LogoService`, `NavigationService`,
`IdentitySourceService` (Identitätsquellen: Hauptquelle ID 0 aus `settings`, weitere aus `identity_sources`; Validierung, Ver-/Entschlüsselung der Zugangsdaten, SSO-Routen/Worker, `authEnvironment()` für `/internal/sso-config`),
`PhonebookService`, `SettingsService`, `SmsCodeService`, `StatisticsService`, `ThemeService`,
`Tls\TlsCertificateService` (CSR/Schlüssel erzeugen, Import-Vorschau und -Bestätigung, Aktivierung, Notfall-Zertifikat, HTTP-Quellnetze `tls_http_networks`, `authConfig()` für `/internal/tls-config`) + `Tls\CertificateInspector` (PEM/DER/Base64 lesen, Details, Kette ordnen, Status valid/expiring/expired/not_yet_valid, Hostname-Abdeckung).

Muster: Service erhält Repositories per Konstruktor, validiert Eingaben
(`Validator`/`Sanitizer`) und wirft bei Fehlern `ValidationException` mit einem
`array<string,string>` an Feld→Fehlermeldung.

### Repositories (`app/Repositories/`)

`ActivationNumberRepository`, `AdminUserRepository`, `AlarmGroupRepository`,
`AlarmLogRepository`, `AnnouncementRepository`, `ClickRepository`,
`EmergencyNumberRepository`, `ImportantLinkRepository`, `NavigationRepository`,
`PhonebookRepository`, `SettingsRepository`, `IdentitySourceRepository`, `SyncLogRepository`, `AdGroupRepository` (synchronisierte AD-Gruppen, Vorschläge), `OfficeAppRepository` (Office-App-Freigaben und App-Pakete), plus Basis `Repository`
(stellt `PDO $pdo` bereit; Test kann eine eigene `PDO`-Instanz injizieren).

### Security (`app/Security/`)

- `SsoAuth` – Windows-Benutzer aus dem Header des `auth`-Containers (nur von `SSO_TRUSTED_PROXY`) bzw. simulierte Anmeldung im Testmodus (`SSO_FAKE_USER`, nicht in `APP_ENV=production`); der erkannte Benutzer erscheint im Kopf (`views/layouts/base.php`, `.site-user`) und wird beim Wechsel nach Nextcloud per Einmal-Token (`OfficeJwt::ssoToken`, `OfficeConfigService::ssoEntryUrl`) an `apps/intranet_integration/sso` (`SsoController`) weitergereicht – siehe `docs/office.md`.
- `SecretBox` – libsodium-Verschlüsselung (`enc:v1:…`) der AD-Zugangsdaten; Schlüssel `SECRETS_KEY_FILE` (Standard `storage/keys/secrets.key`, 0600, wird automatisch erzeugt). Entschlüsselung liefert `null` bei falschem Schlüssel/Manipulation (Oberfläche: „ungültig“).
- `SsoAuth` ohne Anmeldepflicht: `resolve()` = Header (nur an `/sso/anmelden`) oder Sitzung (`sso_identity`, Ablauf `SSO_SESSION_LIFETIME`, bei jeder Anfrage gegen das Telefonbuch geprüft); `shouldAttempt()` steuert den automatischen Versuch einmal je Sitzung (Middleware `$ssoAttempt` für `/`, `/unterseite`, `/seite`, `/office-starten`, `/office-app`; `SSO_AUTO_LOGIN`); `safeTarget()` erlaubt nur lokale Rücksprungziele.
- `SsoAuth` bei mehreren Domänen: `SSO_TRUSTED_PROXY`-Einträge `host=KENNUNG` binden eine auth-Instanz an ihre Quelle; Benutzer werden nur in dieser Quelle gesucht (`name@kennung` für Nextcloud).
- `Auth` – Session-Authentifizierung, Rollen `admin`/`redaktion`, Login-Lockout (5 Versuche / 300 s), Idle-Timeout, Session-Regeneration.
- `Csrf` – Token-Erzeugung/-Validierung.
- `Session` – Session-Verwaltung (Start, Flash, Regenerate).

### Support (`app/Support/`)

- `Html::e()` – zentrales Escaping (htmlspecialchars, UTF-8).
- `Validator` – Eingabe-/URL-/Farb-/Typ-Prüfung.
- `Sanitizer` – HTML-Reinigung (Rich-Text).
- `Dates` – Datums-/Zeitraum-Helfer (u. a. für die Statistik).

### Contracts (`app/Contracts/`) & Exceptions (`app/Exceptions/`)

- Interfaces: `AdminUserStoreInterface`, `AdGroupStoreInterface`, `LdapClientInterface`, `OfficeProbeInterface`, `PhonebookStoreInterface`, `SyncLogStoreInterface`.
- Exceptions: `HttpException` (mit `statusCode()`), `ValidationException` (mit `errors()`).

---

## 8. Routing-Tabelle

Definiert zentral in `public/index.php`.

Vor den öffentlichen GET-Routen hängt eine Middleware-Gruppe `$requireUnlocked`:
Interne, per SMS-Code geschützte Elemente (`protected_access`) werden serverseitig
auf `/zugriff` umgeleitet, bis sie für die Sitzung freigeschaltet sind.

### Öffentlich (keine Anmeldung)

| Methode | Pfad | Handler |
| --- | --- | --- |
| GET | `/` | `LandingController::index` |
| GET | `/unterseite` | `PageController::subpage` |
| GET | `/seite` | `PageController::page` |
| GET | `/zugriff` | `ProtectedAccessController::show` |
| GET | `/telefonliste` | `PhonebookController::index` |
| GET | `/api/telefonliste` | `PhonebookController::search` |
| POST | `/api/klick` | `ClickController::store` |
| POST | `/api/alarm` | `AlarmTriggerController::store` |
| POST | `/api/sms-code/send` | `SmsCodeController::send` |
| POST | `/api/sms-code/verify` | `SmsCodeController::verify` |
| GET | `/logo` | `LogoController::show` |
| GET | `/hintergrundbild` | `BackgroundImageController::show` |
| GET | `/wichtige-links/icon` | `ImportantLinkIconController::show` |
| GET | `/health` | `HealthController::index` |
| GET | `/sso?ziel=…` | `SsoController::start` – merkt Ziel + Versuch in der Sitzung, leitet zu `/sso/anmelden` |
| GET | `/sso/anmelden` | `SsoController::login` – einziger Pfad mit NTLM im auth-Container; übernimmt den Header-Benutzer in die Sitzung (`SsoAuth::remember`) |
| GET | `/sso/nicht-erkannt` | `SsoController::notRecognized` – ErrorDocument 401/500 des Anmeldepunkts (Status 401, Meta-Refresh zum Ziel) |
| GET | `/internal/tls-config` | `InternalController::tlsConfig` – `TLS_MODE` (strict/fallback), `TLS_HTTP_NETWORKS`, `TLS_CERT` (inkl. Kette), `TLS_KEY`, `TLS_ID`, `TLS_LABEL` (je `NAME=base64`) für die Hauptinstanz `auth`; Token wie `sso-config`, markiert das ausgelieferte Zertifikat als verwendet (`first_used_at`/`last_used_at`) |
| GET | `/internal/sso-config?source=KEY` | `InternalController::ssoConfig` – Domänen-Konfiguration inkl. entschlüsselter Zugangsdaten für die auth-Container; nur mit Token (`X-Intranet-Sso-Token`, Datei im Volume `sso_token`), ohne `X-Forwarded-*` und nur vom passenden auth-Container; im auth-Proxy per 404 gesperrt |
| GET/POST | `/admin/login` | `AuthController::showLogin` / `login` |

### Admin (Middleware `$requireAuth`, angemeldet)

`POST /admin/logout`, `GET /admin` (Dashboard) und alle `wichtige-links`-Routen.

### Admin (Middleware `$requireAdmin`, nur Rolle `admin`)

Alle übrigen Admin-Routen: `navigation`, `notfallnummern`, `telefonliste`,
`mitteilungen`, `beschreibungen`, `design`, `ad`, `alarmierung`
(inkl. `alarmierung/gruppen`), `aktivierungs-rufnummern`, `zertifikate` (inkl. `zertifikate/csr` (POST erstellen, GET `?id=` herunterladen), `zertifikate/import/pruefen`, `…/import/bestaetigen`, `…/import/verwerfen`, `zertifikate/aktivieren`, `…/deaktivieren`, `…/loeschen`, `…/http-netze`), `snmp`, `statistik`
(+ `admin/api/statistik`), `benutzer`, `sicherung` (Export/Import), `office`
(inkl. `office/pruefen`, `office/sicherung`, `office/kachel`, `office/kachel/gestaltung`,
`office/kachel/vorschau`, `office/apps` inkl. `office/apps/owa`, `office/apps/freigaben`, `office/apps/paket`, `office/apps/paket/loeschen`, `office/ki`), `ad/gruppen` (JSON-Vorschläge aus dem synchronisierten Bestand).

Office öffentlich: `GET /office-starten` (Übersicht der freigegebenen Office-Apps), `GET /office-app?app=…` (Start einer App, prüft Freigabe), `GET /office-nicht-verfuegbar`,
`GET /api/office/footer` (Konfiguration der Fußzeile), `GET /api/office/status` (Verfügbarkeit für die Kachel).

**Middleware-Verhalten:** `$requireAuth` → Redirect auf `/admin/login` (bzw. JSON 401 bei `/admin/api/*`);
`$requireAdmin` → HTTP 403 (bzw. JSON 403 bei `/admin/api/*`).

**Rollen:** `admin` (voller Zugriff inkl. Benutzerverwaltung) · `redaktion` (nur „Wichtige Links“).

---

## 9. Datenbankschema

Migrationen liegen in `database/migrations/` (numerisch sortiert, werden von `migrate.php` angewendet und in `schema_migrations` protokolliert).

| Tabelle | Zweck | Wichtige Spalten |
| --- | --- | --- |
| `navigation_items` | Kacheln/Unterseiten/Textseiten/Alarmierungen | `type` (external/internal/subpage/page/alarm), `parent_id`, `content`, `alarm_text`, `alarm_group_id`, `protected_access`, `background_color`, `background_opacity`, `override_background`, `sort_order`, `active` |
| `settings` | Schlüssel-Wert-Einstellungen | `setting_key` (unique), `setting_value` |
| `phonebook` | AD-synchronisierte Telefonliste | `external_id` (unique), `identity_source_id` (0 = Hauptquelle), Name, `phone`, `phone_digits`, `mobile`, `department`, `active` |
| `click_events` | Klickstatistik | `navigation_id` (FK, SET NULL), `clicked_at` |
| `admin_users` | Administrationskonten | `username` (unique), `password_hash`, `role` (admin/redaktion), `active` |
| `sync_log` | Protokoll der AD-Läufe | `status` (running/success/error), `processed`, `deactivated`, `message` |
| `audit_log` | Änderungsprotokoll (optional) | `admin_user_id`, `action`, `subject` |
| `emergency_numbers` | Notfallnummern-Kacheln | `label`, `phone`, `sort_order`, `active` |
| `important_links` | „Wichtige Links“ | `title`, `url`, `icon_file`, `icon_mime`, `active` |
| `announcements` | Mitteilungs-Overlay | `title`, `message`, `active` |
| `alarm_groups` | Alarmierungsziele (Gruppen oder Einzelrufnummern) | `type` (group/number), `group_number`, `description`, `sort_order`, `active` |
| `alarm_log` | Verlauf ausgelöster Alarmierungen | `navigation_id`, `title`, `alarm_text`, `group_number`, `mode`, `status`, `message`, `triggered_at` |
| `activation_numbers` | Für den SMS-Zugangscode erlaubte Rufnummern | `phone`, `phone_digits` (unique), `sort_order`, `active` |
| `navigation_item_permissions` | Kachel-Berechtigungen (Benutzer oder AD-Gruppe) | `navigation_id`, `phonebook_id`, `group_name` |
| `ad_groups` | Synchronisierte AD-Gruppen (aus `ldap_group_base_dn`) | `dn_hash` (unique), `dn`, `name`, `description`, `member_count`, `active` |
| `ad_group_members` | Mitglieder (verschachtelt aufgelöst) | `group_id`, `phonebook_id` |
| `office_app_packages` | App-Pakete für Office-Apps | `name` (unique), `description` |
| `office_app_package_apps` | Apps eines Pakets | `package_id`, `app_key` |
| `identity_sources` | Weitere AD-Quellen (Zweigstellen, Tochtergesellschaften) | `source_key` (unique), `label`, `hosts` (je Zeile ein Server, Ausfallreserve), LDAP-Felder, `bind_password` (verschlüsselt), `sso_enabled`, `sso_domain`, `sso_dcs`, `sso_join_user`, `sso_join_password` (verschlüsselt), `sso_networks`, `sso_hostnames`, `sort_order`, `active` |
| `tls_certificates` | CSR-Requests mit Schlüssel (verschlüsselt) und importiertem Zertifikat; `kind='fallback'` = selbstsigniertes Notfall-Zertifikat | `kind` (csr/fallback), `common_name`, `san`, `key_type`, `private_key`, `public_key_hash`, `csr_pem`, `certificate_pem`, `chain_pem`, `cert_*` (Details, `cert_not_before`/`cert_not_after` als Unix-Zeit), `active` (genau eins), `activated_at`, `first_used_at`/`last_used_at` |
| `office_app_permissions` | Freigabe von Apps/Paketen für AD-Gruppen | `group_name`, `app_key` oder `package_id` |

**Konventionen:** `InnoDB`, `utf8mb4`/`utf8mb4_unicode_ci`, `TIMESTAMP`-Spalten `created_at`/`updated_at`, `TINYINT(1)` für Booleans (`active`), Fremdschlüssel mit `ON DELETE SET NULL`/`ON UPDATE CASCADE`.

---

## 10. Konfiguration

**Vorrangregel: Umgebungsvariablen liefern die Grundeinstellung, die Tabelle `settings` überschreibt sie** AD-Zugangsdaten (Bind-Passwörter, Domänenbeitritt) stehen ausschließlich verschlüsselt in der Datenbank (`settings`: `ldap_bind_password`, `sso_join_password` verschlüsselt, `sso_domain`, `sso_dcs`, `sso_join_user`; `identity_sources`) und werden im Adminbereich gepflegt – nicht in der `.env`. Alt-Werte aus der `.env` übernimmt `scripts/credentials.php` beim Start.

- `config/app.php`, `config/database.php`, `config/ldap.php`, `config/alarm.php`, `config/snmp.php` lesen die Werte über `Env::get()`.
- `config/snmp.php` liefert `community`, `sys_location`, `sys_contact` – alle im Adminbereich (Tabelle `settings`) pflegbar; der am Host veröffentlichte UDP-Port (`SNMP_PORT`) ist ausschließlich über die Umgebung (Docker-Port-Mapping) konfigurierbar.
- Jede Variable unterstützt die Datei-Variante `<NAME>_FILE` für Docker-Secrets.
- Vollständige Liste der Variablen: siehe `README.md` (Abschnitt „Konfiguration“) und `.env.example`.

**Wichtigste Variablen:** `APP_URL`, `APP_DEBUG`, `APP_FORCE_SECURE_COOKIES`, `APP_SESSION_IDLE_TIMEOUT`, `APP_MAX_LOGO_BYTES`, `APP_ALLOW_SVG_LOGO`, `APP_MAX_BACKGROUND_BYTES`, `DB_*`, `LDAP_*` (inkl. `LDAP_ATTR_*`-Mapping), `ALARM_*` (SMS-Gateway), `SNMP_COMMUNITY`/`SNMP_SYS_LOCATION`/`SNMP_SYS_CONTACT`/`SNMP_PORT`, `APP_PORT`/`APP_HTTPS_PORT`, `TLS_ENABLED`, `SEED_ON_START`, `CLICK_RETENTION_DAYS`, `ADMIN_USERNAME`/`ADMIN_PASSWORD`.

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
- **Zugangscode-Schutz:** geschützte Elemente (`protected_access`) verlangen einen täglich wechselnden, per SMS zugestellten Code (HMAC-Ableitung aus `sms_code_secret`, Hash-Vergleich, 5 Fehlversuche).
- **Logging:** Passwörter/Tokens werden im Log maskiert.
- **AD-Zugangsdaten:** verschlüsselt gespeichert, nie ausgegeben (Formular: leer = unverändert); nicht in Sicherungen (`BackupService`), beim Import bleiben lokale Werte erhalten.
- **Mehrere Quellen:** Gruppennamen bilden einen gemeinsamen Namensraum über alle Quellen (gleichnamige Gruppen erhalten dieselben Rechte).

---

## 12. Wichtige Workflows

### Container-Startup (`docker/php/entrypoint.sh`)

1. `storage/logs`, `storage/uploads` anlegen + Rechte.
2. Auf die Datenbank warten (bis 60 × 2 s).
3. Rolle `app`: `migrate.php` → `credentials.php` (Schlüssel, Alt-Import) → Token für `/internal/sso-config` in `/run/intranet-sso/token` (Volume `sso_token`) → ggf. `seed.php` (`SEED_ON_START`) → ggf. `create_admin.php`.
4. Rolle `sync`: kurz warten (Migrationen abwarten), `credentials.php --key`, dann `sync_worker.php` (Dauerschleife).
5. `exec "$@"` (App: `apache2-foreground`; Sync: `php scripts/sync_worker.php`).

### auth-Container (`docker/auth/entrypoint.sh`)

- Ruft beim Start `/internal/sso-config?source=<SSO_SOURCE>` von `app` ab (Token aus `sso_token`, Wiederholungen) und tritt damit der Domäne bei; Änderungen erfordern einen Neustart (`docker compose restart auth auth-<kennung>`).
- HTTPS (`TLS_ENABLED=true`, nur Hauptinstanz): `tls-sync.sh once` holt vor dem Start `/internal/tls-config` und schreibt `/etc/intranet-tls/` (`cert.pem`, `key.pem`, `server.pem`, `mode`, `networks`; prüft, dass Schlüssel und Zertifikat zusammenpassen; ohne Anwendung lokales selbstsigniertes Zertifikat). `tls-sync.sh loop` gleicht alle `TLS_SYNC_INTERVAL` (60) Sekunden ab und lädt nur bei Änderungen neu (`apache2ctl graceful` bzw. HAProxy `-sf`).
- Ohne Verteiler: Apache mit `-D TLS_APACHE` – HTTP-VirtualHost bindet `http-policy.conf` (Umleitung 302 auf `https://host:HTTPS_PUBLIC_PORT`, im Modus fallback nicht für die Quellnetze, nie für `/auth-health`) und den gemeinsamen Inhalt `common.conf` ein, HTTPS-VirtualHost `*:443` ebenso `common.conf`; `X-Forwarded-Proto` je nach `%{HTTPS}`. Mit Verteiler (`-D TLS_PROXY`): HAProxy terminiert TLS auf `:443` (`alpn http/1.1`), setzt `X-Forwarded-Proto` und leitet selbst um (`sso-routes.sh` liest `/etc/intranet-tls/`).
- Hauptinstanz `auth`: HAProxy verteilt per `SSO_ROUTES` (Hostname/Client-Netz) an die Worker `auth-<kennung>` (`sso-routes.sh`, Fallback auf die Hauptinstanz, wenn ein Worker ausfällt); Worker aus `docker-compose.sso.yml` (`scripts/sso-domains.sh`).

### SNMP-Container-Startup (`docker/snmp/entrypoint.sh`)

1. Liest `SNMP_COMMUNITY`, `SNMP_SYS_LOCATION`, `SNMP_SYS_CONTACT` aus der Umgebung.
2. Überschreibt diese mit den Werten aus der Tabelle `settings` (`snmp_community`, `snmp_sys_location`, `snmp_sys_contact`), falls die Datenbank erreichbar ist.
3. Erzeugt `snmpd.conf` und hängt die Status-Checks als `exec`-Einträge an (`UCD-SNMP-MIB::extTable`, Basis `.1.3.6.1.4.1.2021.8.1`): `app`, `db`, `sync`, `sync_workflow`, `phpmyadmin`, die Office-Dienste sowie `tls_certificate`/`tls_certificate_days`.
4. `exec snmpd -f -Lo -C -c /etc/snmp/snmpd.conf` (lauscht auf UDP 161).

### AD-Synchronisation

- Einzellauf: `php scripts/sync_ad.php` oder Schaltfläche im Adminbereich.
- Dauerlauf: `php scripts/sync_worker.php` (Intervall `LDAP_SYNC_INTERVAL`).
- **Datensicherheit:** nicht erreichbares/leeres AD → *kein* Schreiben, letzter Stand bleibt aktiv.
- Nicht mehr vorhandene Personen → `active = 0` (kein Löschen); deaktivierte AD-Konten (`ACCOUNTDISABLE`) werden übersprungen/deaktiviert.
- Jeder Lauf wird in `sync_log` protokolliert und im Adminbereich angezeigt.
- Mehrere Quellen: jede Quelle wird getrennt synchronisiert (`AdSyncService`), Server je Quelle der Reihe nach versucht; fällt eine Quelle aus, bleibt ihr Stand erhalten (Status `partial`, `sync_ad.php` Exit 4).
- Gruppen: `LdapClient::fetchGroups()` liest Gruppen unter `ldap_group_base_dn` und löst Mitglieder per `LDAP_MATCHING_RULE_IN_CHAIN` auf; `AdGroupRepository::replaceAll()` schreibt sie in derselben Transaktion. Fehler beim Gruppenabruf lassen den alten Gruppenstand unverändert. `SsoAuth` ergänzt die Gruppen des angemeldeten Benutzers aus diesem Bestand (keine Live-Abfrage des AD).

### Tests

- Dependency-freier Testrunner: `php tests/run.php`.
- `tests/Support/Runner.php`, `Assert.php`, `Fakes.php` (Fake-Implementierungen der Contracts).
- Unit-Tests in `tests/Unit/*Test.php` (u. a. `AdSyncServiceTest`, `NavigationServiceTest`, `SecurityTest`, `StatisticsServiceTest`, `ValidatorTest`).
- Tests nutzen `Database::set()` mit einer SQLite-In-Memory-PDO bzw. Fake-Repositories.

### Alarmierung & SMS-Zugangscode

- Alarm-Kacheln (`type=alarm`) lösen per `POST /api/alarm` eine SMS über das Gateway aus (`ALARM_*` bzw. Settings `alarm_*`), Ziel = Gruppe oder Einzelnummer (`alarm_groups.type`), protokolliert in `alarm_log`. Das Gateway-Passwort kommt nur aus der Umgebung bzw. einem Docker-Secret.
- Geschützte Elemente (`protected_access=1`) führen zu `/zugriff`; Freischaltung über `POST /api/sms-code/send` und `/api/sms-code/verify`. Der sechsstellige Tagescode wird per HMAC aus `sms_code_secret` abgeleitet, das Zeitfenster steht in `sms_code_timeout`.

### SNMP-Überwachung

- Container `snmp` liest den Zustand der Dienste über den (read-only gemounteten) Docker-Socket und den Zustand des AD-Workflows direkt aus `sync_log`.
- Ausgabe in `UCD-SNMP-MIB::extTable`: `extResult` (Exit-Code) unter `.1.3.6.1.4.1.2021.8.1.100.N`, `extOutput` (Text) unter `.1.3.6.1.4.1.2021.8.1.101.N`, Index `N` = 1 `app`, 2 `db`, 3 `sync`, 4 `sync_workflow`, 5 `phpmyadmin`, 6 `nextcloud`, 7 `nextcloud_db`, 8 `nextcloud_redis`, 9 `eurooffice`, 10 `office_workflow` (6–10 nur mit Profil `office`, sonst UNKNOWN), 11 `tls_certificate` (aktives HTTPS-Zertifikat: 0 > 30 Tage, 1 ≤ 30 Tage oder keins aktiv, 2 abgelaufen/noch nicht gültig), 12 `tls_certificate_days` (gleiche Exit-Codes, Text = Resttage, `-9999` = keins aktiv).
- Datenbankabfragen (`db_query`): direkt per MariaDB-Client, sonst über den Docker-Socket im `db`-Container (Alpine-Client ohne `caching_sha2_password`).
- Exit-Codes: `0` OK, `1` WARNING, `2` CRITICAL, `3` UNKNOWN; `sync_workflow` meldet `0`, wenn der letzte Lauf `success` und jünger als `2 × LDAP_SYNC_INTERVAL` ist.
- Community/Strings im Adminbereich unter **SNMP** pflegbar; werden erst nach Neustart des `snmp`-Containers wirksam.

### Systemdienst (Autostart)

- `scripts/install-systemd-service.sh` erzeugt eine systemd-Unit (`intranet.service`, konfigurierbar über `SERVICE_NAME`), die beim Boot `docker compose up -d` und bei `systemctl stop` `docker compose down` ausführt (Ubuntu ≥ 22.04).

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
| Alarmierung anlegen | Navigationselement vom Typ `alarm` anlegen; Ziel (Gruppe/Rufnummer) in `alarm_groups`; Gateway unter **Alarmierung** konfigurieren |
| Geschütztes Element | `protected_access` am Element setzen; erlaubte Rufnummern unter **Aktivierungs-Rufnummern** pflegen |
| Frontend-Styling | `public/assets/css/app.css` (handgeschrieben, Theme-Variablen über `ThemeService`) |

---

## 15. Referenzdokumente

- `README.md` – ausführliche Projektdokumentation (Funktionsumfang, Docker, Konfiguration, Betrieb, Sicherheit).
- `docs/manuals/anwenderhandbuch.pdf` / `administratorhandbuch.pdf` (Quellen als HTML unter `docs/manuals/`).
- `docs/projektdokumentation/` – IT-Projektdokumentation (HTML-Quelle, PDF, Abbildungen); PDF neu erzeugen mit `./docs/projektdokumentation/build.sh` (nur Docker nötig).
- `docs/screenshots/` – Screenshots der öffentlichen und Admin-Bereiche (30–56: Office, Office-Apps, AD-Gruppen und lokale KI).
- `docs/installation.md` – Assistierte Installation mit `scripts/install.sh`: Ablauf, Optionen, Bedienung, abgefragte Variablen, Abschlussbericht, Fehlerbehebung.
- `docs/office.md` – Office-Erweiterung: Einrichtung, Architektur, Updates, AD-Gruppen/SSO, Kachel, lokale KI, Sicherung, SNMP.
