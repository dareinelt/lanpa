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
- **Notfallpläne / KAEP-Team**: AD-gruppenberechtigter roter Kopfbutton, visueller Ablaufeditor im Office-Stil (Menüband, eigener Tab, Undo/Redo, Live-Vorschau) mit verbindlicher Vier-Augen-Freigabe, AD-Kennwortbestätigung beim Start, interaktive Maßnahmen/Checklisten, SMS-Einzelbestätigung, SMTP-Benachrichtigung und Ereignishistorie; KAEP-Dashboard (`KaepDashboardController`/`KaepDashboard`, SSE-Live-Lagebild, Koordination mit Zuständigkeit/Priorität/Zielzeit, Leitungen mit geplanter Ablösung, Zeitplan/Wiedervorlagen, Journal, Zeiteingabe in Gerätezeitzone); Technik in `docs/notfallplan.md`, bebilderte Einsatzanleitung in `docs/notfallplan-anleitung.md`, Editor-Referenz für Coding-Agenten in `docs/notfallplan-editor-referenz.md`
- einen **SNMP-Agenten** (Container `snmp`) zur Überwachung der Dienste, des AD-Synchronisations-Workflows und der Gültigkeit des HTTPS-Zertifikats
- eine **Zertifikatsverwaltung** (Admin → Zertifikate (HTTPS)): CSR erstellen, Zertifikat (PEM/CRT) mit Vorschau importieren, aktives Zertifikat wählen; der `auth`-Container liefert damit HTTPS aus (sonst selbstsigniertes Notfall-Zertifikat, HTTP nur aus freigegebenen Quellnetzen)
- optional **Office** (Profil `office`): Nextcloud + Euro-Office DocumentServer hinter dem `auth`-Container, Rechte über Benutzer/AD-Gruppen, Intranet-Fußzeile, lokale KI für alle Benutzer (Nextcloud-Assistent, KI-Plugin der Editoren; Audio/Bilder im Adminbereich schaltbar), Sicherung – Details in `docs/office.md`
- optional **LLMInt unter `/ki/`** (`LLMINT_ENABLED`): KI-Oberfläche aus eigenem Stack hinter dem `auth`-Container (Same-Origin, gleiches Zertifikat, Windows-Anmeldung an `/ki/sso.php`) – Details in `docs/llmint.md`

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
| Betrieb | Docker Compose (Dienste `app`, `db`, `sync`, `mail`, `mail-archive` (Orvanta-Langzeitarchiv-Worker: Python-Supervisor ruft `scripts/orvanta_archive_worker.php`, siehe `docs/orvanta-referenz.md` Abschnitt 17), `mail-proxy` (SMTP-/IMAP-Proxy für Orvanta ohne Exchange, Python-Standardbibliothek, nur internes Netz `mail_proxy` + ausgehend `mail_egress`, ohne DB, siehe `docs/mail-proxy.md`, technische Referenz `docs/mail-proxy-referenz.md`), `snmp`, `auth` (Einstieg/Reverse-Proxy, optional NTLM), optional `phpmyadmin`; Profil `office`: `nextcloud`, `nextcloud-cron`, `nextcloud-ai-worker`, `nextcloud-db`, `nextcloud-redis`, `eurooffice`, `office-backup`, `storage-sync` (Speicher-Tiering/HA, `docs/storage.md`, technische Referenz `docs/storage-referenz.md`), `storage-sync-catalog` (MySQL-Katalog des Agenten, Zugangsdaten = `DB_USER`/`DB_PASSWORD`/`DB_ROOT_PASSWORD`), `storage-sync-redis` (Sperren/I/O-Zähler, ohne Persistenz, Passwort = `DB_PASSWORD`) – beide nur im internen Netz `storage_catalog`, Container-Übersicht `docs/storage-stack.md`) |
| Monitoring | net-snmp-Agent im Container `snmp` (UDP 161, read-only Docker-Socket + `sync_log`) |
| Abhängigkeiten | **keine** – kein Composer, kein npm, kein CDN |

Benötigte PHP-Erweiterungen: `pdo_mysql`, `ldap`, `mbstring`, `json`, `openssl`, `zip`, `gd` (Signaturlogo skalieren).

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
docker compose --profile ki up -d ki              # optional: lokaler KI-Testendpunkt (llama.cpp, http://<host>:8089/v1)
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
| `mail_worker.php` | SMTP-Warteschlange abarbeiten (Container `mail`); `--once` für einen Durchlauf |
| `orvanta_archive_worker.php` | Orvanta-Langzeitarchiv: prüft registrierte Postfächer gegen die Archivrichtlinie und archiviert (Container `mail-archive`); `--once` für einen Durchlauf |
| `credentials.php` | Schlüssel für gespeicherte Zugangsdaten anlegen (`--key`), Alt-Werte aus der `.env` verschlüsselt übernehmen (Standard, beim App-Start), `--set-primary` (stdin `NAME=base64`, von `install.sh`), `--ldap-password` (für `office-setup.sh`), `--sso-sources` (für `sso-domains.sh`) |
| `sso-domains.sh` | Erzeugt `docker-compose.sso.yml` mit je einer auth-Instanz `auth-<kennung>` pro weiterer Domäne mit Windows-Anmeldung (ohne Zugangsdaten) |
| `purge_clicks.php` | Löscht Klickdaten älter als N Tage (Standard `CLICK_RETENTION_DAYS=400`) |
| `spellcheck_dictionary.php` | Lädt das deutsche Wörterbuch für Orvanta einmalig herunter und übersetzt es (Entrypoint des `app`-Containers); `--force` erzwingt einen neuen Lauf, `--quiet`; ohne Netz oder bei `ORVANTA_SPELLCHECK=false` nur eine Warnung |
| `install.sh` | Assistierte Komplettinstallation (whiptail/dialog/Text): prüft und installiert Abhängigkeiten, kopiert `.env.example`, fragt Passwörter/Secrets ab, startet und prüft die Container, schreibt Abschlussbericht nach `install-reports/` (Doku: `docs/installation.md`) |
| `mysql-upgrade.sh` | Hebt ein bestehendes MySQL-8.0-Volume über einen temporären `mysql:8.4`-Container an (LTS-Pfad 8.0 → 8.4 → 9.7), sichert vorher nach `backups/mysql/`, stellt `mysql_native_password`-Konten um; `--check` (Exit 10 = nötig). Neue Installationen brauchen es nicht; `install.sh` ruft es bei vorhandenem 8.0-Volume auf |
| `storage_sync.php` | Agent des Containers `storage-sync`: `monitor` | `sync` | `recall` | `restore --target=<id> [--full] [--keep-paused]` | `resume` | `catalog-init` (Schema anlegen, alten SQLite-Katalog einmalig übernehmen; Entrypoint) | `catalog-status` (Exit 0 ok, 1 Katalog, 2 Redis nicht erreichbar) | Snapshot-Speicher: `snapshots [--path=] [--limit=]`, `snapshot-status`, `snapshot-restore --id=<uid>`, `snapshot-retry`, `snapshot-prune`, `snapshot-rebuild` |
| `storage_status.php` | Werte des Speicher-Tierings für SNMP (`storage_ha`, `storage_sync`, `storage_hot_fill`, `storage_cold_fill`, `storage_snapshot`, `storage_metrics`, `storage_targets`) |
| `storage-restore.sh` | Wiederherstellung der Office-Daten aus einem Speicherziel des Cold-Tiers inkl. Nextcloud-Datenbank (`docs/storage.md`) |
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
  Services/     Geschäftslogik (Unterordner Office/, Orvanta/, Tls/, …)
  Support/      Color, Dates, Html, Sanitizer, TileColors, Validator
config/         Konfiguration aus Umgebungsvariablen (app, database, ldap)
database/
  migrations/   SQL-Migrationen (001…033)
docker/         Dockerfiles, Entrypoints, PHP-/MySQL-Konfiguration, SNMP-Agent
public/         DocumentRoot: index.php (Front-Controller), assets, .htaccess, manuals
scripts/        CLI-Werkzeuge (Migration, Seed, Admin, Sync, Bereinigung, Wörterbuch der Rechtschreibprüfung, systemd-Installation, MySQL-Upgrade)
storage/        logs/ und uploads/ (außerhalb des DocumentRoot)
tests/          Dependency-freier Testrunner + Unit-Tests
views/          PHP-Templates (admin, errors, ki, landing, layouts, orvanta, pages, partials, phonebook)
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

### Notfallplan und SMTP

`EmergencyPlanController`, `EmergencyPlanService`, `EmergencyPlanDefinition` und
`EmergencyPlanRepository` implementieren `/notfallplan` und `/admin/notfallplan`.
Migrationen 025–027 ergänzen Rolle `kaep`, Pläne, Ereignisse, Audit,
Kennwortbegrenzung, SMTP-Warteschlange und Freigabehistorie.
`public/assets/js/emergency-plan.js` zeichnet das Diagramm ohne Bibliotheken;
Vorlagen stehen unter `views/emergency/`.
Aufbau, Datenformat, Validierung, Live-Vorschau, Freigabeworkflow und Invarianten des
Planeditors: `docs/notfallplan-editor-referenz.md` (**vor Änderungen am Editor lesen**).

KAEP wird zentral im Front-Controller auf Notfallplan-Routen beschränkt.
`AdminGroupService` kennt zusätzlich das Ziel `kaep`. Mitglieder dieser
AD-Gruppen sehen den Notfallplan-Editor zusätzlich als App unter der
Office-Kachel (`OfficeAppService::allowedFor()`, Start per SSO-Anmeldung in
`OfficeController::launch` → `/admin/notfallplan/bearbeiten`). Benutzerzugriff ist davon
getrennt und benötigt aktivierte Funktion plus ausgewählte AD-Gruppe.
Auch Administratoren dürfen eigene/mitbearbeitete Entwürfe nicht freigeben.
Veröffentlichte Definitionen und Ereignissnapshots bleiben von Entwurfsänderungen
unberührt; Freigabemetadaten gehören zum Snapshot. Migration 027 zieht alte
Veröffentlichungen ohne zweiten Freigabenachweis zurück.
Export/Import (Rollen `admin` und `kaep`; Übersicht und Editor-Reiter Start →
Exportieren): `EmergencyPlanTransfer` erzeugt Export-Sätze (`format`
`lanpa-notfallplaene`, `version` 3, Teildateien ≤ 15 MB mit `set{id,part,parts}`,
Inhaltsverzeichnis in Teil 1, Anhänge als base64-Abschnitte) in
`storage/emergency-transfer`; Download (`GET …/plaene/export/datei`) oder Ablage in den
eigenen Nextcloud-Dateien (`POST …/plaene/export/nextcloud` →
`NextcloudFilesService` → NC-App `POST /api/files`, Ordner `Notfallpläne/…`).
Import: Teile einzeln hochladen (`POST …/plaene/import`, Stand fehlender Teile),
`POST …/plaene/import/abschluss` prüft Vollständigkeit und Prüfsummen und ruft
`EmergencyPlanService::importDefinitions()` (Einzeldateien v1/v2 über `importPlans()`),
das SMS-Elemente per Alarmtitel lokalen Vorlagen zuordnet
(SMS an einzelne Rufnummern werden unverändert übernommen) und per
`EmergencyPlanRepository::importPlans()` in einer Transaktion neue
Entwürfe an (Freigabeprotokoll `imported`, Importierende als Autor).
Schritt-Anhänge (Maßnahme/Kontakt/Entscheidung/Hinweis, PDF/Bilder ≤ 20 MB, ≤ 10 je
Schritt): Tabelle `emergency_plan_attachments` (Migration 032, SHA-256-ID, base64,
unveränderlich), Regeln in `EmergencyPlanAttachments`, Upload
`POST /admin/notfallplan/anhang`, Auslieferung `GET /notfallplan/anhang` bzw.
`/admin/notfallplan/anhang`; Büroklammer-Overlay in `emergency-plan.js`
(`showAttachments`) und `views/emergency/attachment-button.php`.
SMS-Schritte gehen an eine Alarmvorlage oder an einzelne Rufnummern
(`sms_mode`/`sms_numbers`/`sms_text`); `EmergencyPlanSms` bündelt Textbausteine
(`{Notfallplan}`, `{Datum}`, `{Uhrzeit}`, `{Schritt}`, beim Ereignisstart ersetzt) und
das 255-Zeichen-Limit, `AlarmService::triggerEmergency()` versendet je Rufnummer.

`SmtpController`, `SmtpService`, `MailQueueService` und `scripts/mail_worker.php`
verwalten SMTP und die dauerhafte Versandwarteschlange. SMTP-Konfiguration nur
für Administratoren; Kennwort verschlüsselt, kein Versand innerhalb des
Ereignisstart-Requests. Vollständige Notfallhistorie über MySQL sichern, nicht
über den Konfigurations-ZIP-Export.

### Controller (`app/Controllers/`)

- Basisklasse `Controller` stellt `view()`, `requireValidCsrf()`, `redirect()`, `assetVersion()` bereit.
- **Öffentlich:** `LandingController`, `PageController` (Unterseiten/Textseiten), `PhonebookController`, `ClickController`, `LogoController`, `BackgroundImageController`, `ImportantLinkIconController`, `HealthController`, `KiController` (`/ki-nicht-verfuegbar`: Fehlerseite des auth-Proxys für LLMInt), `AlarmTriggerController` (Alarm-Kacheln), `ProtectedAccessController` (Zugangscode-Seite), `SmsCodeController` (Code-Versand/-Prüfung), `NetworkDriveController` (`POST /sso/laufwerke`: Meldung der Netzlaufwerke durch das Anmeldeskript, nur mit Windows-Anmeldung), `OrvantaController` (Mail- und Kalender-App `/office/orvanta`, Anhang-Links für Euro-Office/Browser/Download) und `OrvantaApiController` (JSON-API `/api/orvanta/*` für Mail, Kalender, Kontakte, Aufgaben, Notizen, Anhänge, Zwischenspeicher, Erinnerungen, KI-Textunterstützung `ki/verbessern`, Rechtschreibprüfung `rechtschreibung/*`, Langzeitarchiv `archiv/*`, Abwesenheitsnotiz `abwesenheit`; siehe `docs/orvanta.md`).
- **Admin (`app/Controllers/Admin/`):** `AuthController`, `DashboardController`, `NavigationController`, `ImportantLinkController`, `EmergencyNumberController`, `PhonebookAdminController`, `AnnouncementController`, `DescriptionController`, `DesignController`, `LdapController`, `AlarmController`, `AlarmGroupController`, `ActivationNumberController`, `SnmpController`, `CertificateController` (Zertifikate/HTTPS), `StatisticsController`, `AdminUserController`, `ImportExportController`, `OfficeController`, `OfficeAppsController`, `StorageQuotaController` (Speicherplatz/Quota), `NetworkDriveController` (Netzlaufwerke: Ausschlussliste, Übersicht, Anmeldeskript), `StorageController` (Speicher (HA): Speicherziele, Cold-Tier-Erweiterungen, Einstellungen, Live-Status, Aufträge, Snapshot-Speicher-Einstellungen, Dateiversionen mit Filtern und Wiederherstellung), `IncidentController` (Vorfälle: Ransomware/verdächtiges Überschreiben, Liste, „Erledigt“ mit Bestätigung, Einstellungen), plus Basis `AdminController`.

### Services (`app/Services/`) – Geschäftslogik

`ActivationNumberService`, `AdSyncService`, `AdminUserService`, `AlarmGroupService`,
`AlarmService`, `AnnouncementService`, `BackgroundImageService`, `BackupService`,
`Office\OfficeConfigService` (Einstellungen Fußzeile/Kachel), `Office\OfficeHealthService` (Status/Diagnose, Probe per `OfficeProbeInterface`), `Office\OfficeBackupService` (Steuerung des Containers `office-backup`), `Office\OfficeAiService` (lokale KI: Einstellungen inkl. Audio/Bilder, `runtime.json` für Euro-Office, signierte Übergabe an `intranet_integration/api/ai`), `Office\OfficeTrustedDomainsService` (vertrauenswürdige Hostnamen von Nextcloud aus `APP_URL`, `SSO_SPN_HOSTS`, Domänenbeitritt (`IdentitySourceService::ssoHostnames`) und HTTPS-Zertifikat; signierte Übergabe an `intranet_integration/api/hosts`, Abgleich in der Diagnose),
`Office\StorageQuotaService` (Speicherplatz-Kontingente in Nextcloud: Standard `office_quota_default_mb` (500 MB), Regeln je AD-Gruppe (größtes gilt), individuelle Kontingente mit Pflicht-Begründung und Verlauf; signierte Übergabe an `intranet_integration/api/quota`, Abgleich per Fingerabdruck in der Diagnose), `AdminGroupService` (Administratoren aus AD-Gruppen: Ziele `intranet` (Rolle admin per Windows-Anmeldung) und `nextcloud`; Mitglieder/Kennungen), `Office\NextcloudAdminService` (signierte Übergabe der Nextcloud-Administratoren an `intranet_integration/api/admins`, Gruppe `admin`, Abgleich per Fingerabdruck in der Diagnose),
`Office\NetworkDriveService` (Netzlaufwerke der Windows-Clients: Meldung per `scripts/network-drives-report.ps1` an `/sso/laufwerke`, Vergleich mit dem gespeicherten Stand vor dem Schreiben (`sameState()`: Laufwerke, Domäne und Computername; unverändert → nur `reported_at` per `touchForUser()`), Ausschlussliste `office_network_drives_excluded` (Standard B, G; `none` = leer), Schalter `office_network_drives_enabled`; signierte Übergabe an `intranet_integration/api/drives` nur bei echter Änderung, Abgleich per Fingerabdruck in der Diagnose; Nextcloud bindet sie als SMB-Speicher nur für Benutzer mit „Netzlaufwerke anzeigen“ ein), `Office\NextcloudAppStoreService` (App-Store in Nextcloud ein-/ausblenden: Schalter `office_appstore_enabled` (Standard an); signierte Übergabe an `intranet_integration/api/appstore`, setzt `appstoreenabled`, Abgleich in der Diagnose),
`Office\OfficeAppService` + `Office\OfficeAppCatalog` (Office-Apps unter der Kachel: Euro-Office-Webapps, Dateien, OWA, Orvanta (kind `intranet`); Freigabe per AD-Gruppe/App-Paket, ohne Zuordnung/ohne SSO keine Apps),
`Orvanta\OrvantaConfigService` (Einstellungen `orvanta_settings`, Demo-Erkennung `exchange_host = demo`), `Orvanta\OrvantaMailboxResolver` (Postfachadresse für die Impersonation: primäre SMTP-Adresse des Postfachs aus dem AD, sonst die Konfiguration), `Orvanta\OrvantaExchangeService` (EWS-Fachlogik mit Impersonation des SSO-Benutzers; Transport per `ExchangeTransportInterface`: `CurlExchangeTransport` (SOAP via cURL, Negotiate/NTLM/Basic) oder `DemoExchangeTransport`; `EwsXml`, `MailHtmlSanitizer`), `Orvanta\OrvantaExchangePool` (Lastverteilung und Ausfallsicherung über die Hosts einer Exchange-DAG aus `orvanta_exchange_hosts`/`orvanta_exchange_sessions`: Sitzungsaffinität je Benutzer und Client (`affinityKey()`, nicht je PHP-Sitzung – ein Postfach nie auf zwei DAG-Hosts), Verteilung nach Fair-use → wenigsten Sitzungen → mittlerer Antwortzeit, transparentes Failover bei Transportfehlern/HTTP ≥ 500 ohne fachlichen SOAP-Fehler (nie bei 401/403; ändernde EWS-Operationen nach Zustellung ohne Wiederholung), Kennzahlen für das Dashboard unter `/admin/office/orvanta/hosts` durch `Admin\OrvantaHostController`; ohne Migration 043 arbeitet er allein mit dem konfigurierten Server – technische Referenz `docs/orvanta-referenz.md` Abschnitt 20), `Orvanta\OrvantaHostHealthService` (hält den Host-Status aktuell: `refresh()` prüft beim Aufbau der statusanzeigenden Ansichten gestörte Hosts mit veraltetem Zustand (`OrvantaExchangePool::staleHosts()`, `last_ok = 0` und letzte Prüfung älter als `AUTO_CHECK_INTERVAL` = 120 s, höchstens `MAX_CHECKS` = 4 je Durchgang) über denselben Weg wie „Verbindung testen“ (`OrvantaExchangeService::testHost()`, umgeht die Sitzungsaffinität) und schreibt das Ergebnis in `orvanta_exchange_hosts` zurück – ohne Zutun eines Administrators, siehe `docs/orvanta-referenz.md` Abschnitt 20.5), `Orvanta\OrvantaAttachmentService` (signierte Kurzzeit-Links, Öffnungsmodus office/browser/download, Zwischenspeicher im Nextcloud-Bereich des Benutzers mit Quota `cache_quota_mb` und FIFO-Bereinigung, „In Nextcloud speichern“), `Orvanta\OrvantaNotificationService` (Terminerinnerungen: Abgleich mit `orvanta_reminders`, Zustellung an App/Browser-Notification/Kopfzeile, später/erledigt), `Orvanta\OrvantaAiService` (KI-Textunterstützung in den Editoren: Erreichbarkeit der global in `OfficeAiService` konfigurierten KI, `POST /chat/completions`, Entfernen der hellblauen Markierungen vor dem Senden per `stripMarkers()`, Nutzungszähler ohne Texte; Transport `AiTransportInterface`/`CurlAiTransport`), `Orvanta\OrvantaAiCharts` (anonymisierter Nutzungsbericht mit serverseitigen SVG-Diagrammen für den Adminbereich), `Orvanta\OrvantaSignatureService` (Signaturvorlagen aus `orvanta_signatures`: Zuordnung per AD-Gruppe, Name/Position/Abteilung/Rufnummer aus der Telefonliste, Adresse aus der Vorlage, Schrift-/Trennzeichenfarbe je Vorlage aus den Designfarben wählbar, Logo als data-URI in Höhe der Textzeilen, per GD bzw. SVG-Attributen auf diese Pixelgröße verkleinert; `forUser()` für den Editor, `append()` serverseitig beim Senden/Entwurf/Antworten – Benutzer können die Signatur nicht entfernen; Pflege unter `/admin/office/signaturen` durch `Admin\OrvantaSignatureController`) – Referenz `docs/orvanta.md`, `Orvanta\OrvantaOofService` (Abwesenheitsnotiz: Vorlagen aus `orvanta_oof_templates` per AD-Gruppe zugeordnet, fester schreibgeschützter Text + benutzerangepasster dynamischer Text + zugewiesene Signatur, Übertragung per `SetUserOofSettings` (nur intern oder auch extern, Zeitraum von–bis oder bis zum Abschalten; den Versand übernimmt Exchange), Zustand für den Banner aus `GetUserOofSettings`; Pflege unter `/admin/office/abwesenheit` durch `Admin\OrvantaOofController` – technische Referenz `docs/orvanta-referenz.md` Abschnitt 21), `Orvanta\OrvantaDelegateDirectory` (per Auto-Mapping eingebundene Postfächer des Benutzers aus dem AD: `msExchDelegateListBL` über `LdapClient::delegatedMailboxes()`, 15 min je Sitzung zwischengespeichert; null im Demo-Modus/ohne LDAP/bei AD-Fehler), `Orvanta\OrvantaSharedMailboxService` (zusätzliche Postfächer per Vollzugriff/„Senden als“: Abgleich mit dem AD beim Öffnen der App als einziger Quelle (`syncFromDirectory()`; der Adminbereich zeigt den Bestand nur an), Prüfung des Vollzugriffs mit den Rechten des Benutzers über `OrvantaExchangeService::probeMailbox()` mit zwölfstündiger Gültigkeit (`VERIFY_TTL`), Auflösung der Postfachkennung je Anfrage, Absenderauswahl für den Maileditor, Sichtbarkeit der Kalender; archiviert, abwesend und Kontingent bleiben immer das primäre Benutzerpostfach; Pflege unter `/admin/office/orvanta/postfaecher` durch `Admin\OrvantaSharedMailboxController` – technische Referenz `docs/orvanta-referenz.md` Abschnitt 22), `Orvanta\OrvantaArchiveService` (Langzeitarchiv: richtliniengesteuerte Archivierung alter Mails in komprimierte, integritätsgesicherte Container im Nextcloud-Bereich des Benutzers mit Copy-Verify-Commit-Delete, Journal/Suchindex in `orvanta_archive_*`, Worker-Container `mail-archive`; Bausteine `OrvantaArchiveRepository`, `ArchiveStorageInterface`/`NextcloudArchiveStorage`, `MimeMessageParser` – technische Referenz `docs/orvanta-referenz.md` Abschnitt 17), , `Orvanta\OrvantaPresenceService` (aktive Orvanta-Nutzer: `touch()` schreibt die Aktivität des angemeldeten Benutzers mit Backend fort, `sample()` legt je Zeitraster genau eine Probe an, `stats()` liefert aktuell/min/max/Ø der letzten 24 Stunden, `history()` die Tagesmaxima der Zeiträume 14/30/90/180/365 Tage, `purge()` räumt Aktivität nach 24 Stunden und Proben nach 400 Tagen; aufgerufen aus `OrvantaApiController` und dem Archivierungs-Worker), `Orvanta\OrvantaFlowService` (Nachrichtenfluss-Dashboard: sammelt Identitätsquellen, Proxy, Exchange-Hosts mit Clients, Speicher-Tiers, Zwischenspeicher und KI-Endpunkt zu Knoten, Kanten, Spuren, Kennzahlen, Störungen und Gesamtstatus; prüft vor dem Einsammeln gestörte Exchange-Hosts mit veraltetem Zustand automatisch nach, damit der angezeigte Status aktuell ist (`OrvantaHostHealthService`); reine Auswertung `evaluate()` ohne Datenbank für Tests; `OrvantaFlowCloud` und `OrvantaFlowCharts` zeichnen Wolken und den Verlauf als serverseitiges SVG – Konzept `docs/orvanta-nachrichtenfluss.md`),
`MailProxy\*` (SMTP-/IMAP-Proxy für Orvanta-Benutzer ohne Exchange-Postfach: `MailProxyService` (Admin-Logik, Validierung inkl. SSRF-Schutz, Verbindungstest, Diagnose, `invalidate()`, `updateUserPassword()` für das vom Benutzer in Orvanta eingegebene geänderte Kennwort), `MailProxyResolver` (Exchange/Proxy/gesperrt je Benutzer, frische Zugangsdaten), `MailProxyCache` (TTL + Generation), `OrvantaMailRouter` (Backend-Auswahl), `ProxyMailBackend` (`OrvantaMailBackendInterface` über den Proxy), `HttpMailProxyTransport` (HMAC-signiert zum Container `mail-proxy`) – Doku `docs/mail-proxy.md`, technische Referenz `docs/mail-proxy-referenz.md`),
`EmergencyNumberService`, `FaviconService`, `ImportService`, `ImportantLinkService`,
`LdapAttributeMapper`, `LdapClient`, `LogoService`, `NavigationService`,
`IdentitySourceService` (Identitätsquellen: Hauptquelle ID 0 aus `settings`, weitere aus `identity_sources`; Validierung, Ver-/Entschlüsselung der Zugangsdaten, SSO-Routen/Worker, `authEnvironment()` für `/internal/sso-config`),
`PhonebookService`, `SettingsService`, `SmsCodeService`, `StatisticsService`, `ThemeService` (Designfarben → geprüfte CSS-Variablen je Modus; Kachel-Textfarben werden bei zu geringem Kontrast automatisch angepasst),
`Tls\TlsCertificateService` (CSR/Schlüssel erzeugen, Import-Vorschau und -Bestätigung, Aktivierung, Notfall-Zertifikat, HTTP-Quellnetze `tls_http_networks`, `authConfig()` für `/internal/tls-config`) + `Tls\CertificateInspector` (PEM/DER/Base64 lesen, Details, Kette ordnen, Status valid/expiring/expired/not_yet_valid, Hostname-Abdeckung).

Muster: Service erhält Repositories per Konstruktor, validiert Eingaben
(`Validator`/`Sanitizer`) und wirft bei Fehlern `ValidationException` mit einem
`array<string,string>` an Feld→Fehlermeldung.

### Repositories (`app/Repositories/`)

`ActivationNumberRepository`, `AdminUserRepository`, `AlarmGroupRepository`,
`AlarmLogRepository`, `AnnouncementRepository`, `ClickRepository`,
`EmergencyNumberRepository`, `ImportantLinkRepository`, `NavigationRepository`,
`PhonebookRepository`, `SettingsRepository`, `IdentitySourceRepository`, `SyncLogRepository`, `AdGroupRepository` (synchronisierte AD-Gruppen, Vorschläge), `OfficeAppRepository` (Office-App-Freigaben und App-Pakete), `OrvantaSignatureRepository` (Signaturvorlagen), `OrvantaOofRepository` (Vorlagen und Einstellungen der Abwesenheitsnotiz), `OrvantaSharedMailboxRepository` (zusätzlich berechtigte Postfächer, Suche nach Benutzern über die Telefonliste), `OrvantaExchangeHostRepository` (Hosts und Sitzungszuordnungen der Exchange-DAG), `StorageQuotaRepository` (Kontingent-Regeln, Overrides, Verlauf), `AdminGroupRepository` (AD-Gruppen für Intranet-/Nextcloud-Administratoren, Mitglieder), `NetworkDriveRepository` (gemeldete Netzlaufwerke je Benutzer), `StorageRepository` (Speicherziele, Status, Messwerte, Ereignisse, Aufträge, Spiegel der Dateiversionen `storage_snapshots` des Speicher-Tierings), `IncidentRepository` (Vorfälle des Speicher-Tierings, geschütztes Cold-Ziel, Erledigung), `OrvantaRepository` (Einstellungen, Erinnerungen, Zwischenspeicher-Bestand und KI-Nutzungszähler der Mail-App Orvanta), `MailProxyRepository` (SMTP-/IMAP-Proxy: Mailserver, Postfächer, Zuordnungen, Zustand; Chiffrat nur für den Verbindungsaufbau), plus Basis `Repository`
(stellt `PDO $pdo` bereit; Test kann eine eigene `PDO`-Instanz injizieren).

### Security (`app/Security/`)

- `SsoAuth` – Windows-Benutzer aus dem Header des `auth`-Containers (nur von `SSO_TRUSTED_PROXY`) bzw. simulierte Anmeldung im Testmodus (`SSO_FAKE_USER`, nicht in `APP_ENV=production`); der erkannte Benutzer erscheint im Kopf (`views/layouts/base.php`, `.site-user`) und wird beim Wechsel nach Nextcloud per Einmal-Token (`OfficeJwt::ssoToken`, `OfficeConfigService::ssoEntryUrl`) an `apps/intranet_integration/sso` (`SsoController`) weitergereicht – siehe `docs/office.md`.
- `SecretBox` – libsodium-Verschlüsselung (`enc:v1:…`) der AD-Zugangsdaten; Schlüssel `SECRETS_KEY_FILE` (Standard `storage/keys/secrets.key`, 0600, wird automatisch erzeugt). Entschlüsselung liefert `null` bei falschem Schlüssel/Manipulation (Oberfläche: „ungültig“).
- `SsoAuth` ohne Anmeldepflicht: `resolve()` = Header (nur an `/sso/anmelden`) oder Sitzung (`sso_identity`, Ablauf `SSO_SESSION_LIFETIME`, bei jeder Anfrage gegen das Telefonbuch geprüft); `shouldAttempt()` steuert den automatischen Versuch einmal je Sitzung (Middleware `$ssoAttempt` für `/`, `/unterseite`, `/seite`, `/office-starten`, `/office-app`; `SSO_AUTO_LOGIN`); `safeTarget()` erlaubt nur lokale Rücksprungziele.
- `SsoAuth` bei mehreren Domänen: `SSO_TRUSTED_PROXY`-Einträge `host=KENNUNG` binden eine auth-Instanz an ihre Quelle; Benutzer werden nur in dieser Quelle gesucht (`name@kennung` für Nextcloud).
- `Auth` – Session-Authentifizierung, Rollen `admin`/`redaktion`, Login-Lockout (5 Versuche / 300 s), Idle-Timeout, Session-Regeneration. Zusätzlich Administratoren aus AD-Gruppen ohne lokales Konto (`loginDirectory()`, per Windows-Anmeldung); deren Rolle wird bei jeder Anfrage über `AdminGroupService::intranetRole()` und die aktuelle SSO-Identität neu geprüft (`id()` ist dann `null`, `username()` = Nextcloud-Kennung).
- `Csrf` – Token-Erzeugung/-Validierung.
- `Session` – Session-Verwaltung (Start, Flash, Regenerate).

### Support (`app/Support/`)

- `Html::e()` – zentrales Escaping (htmlspecialchars, UTF-8).
- `Html::safeUrl()` – prüft ein Ziel (`javascript:`/`data:` gesperrt) und gibt es unmaskiert zurück; `Html::url()` liefert dieselbe Prüfung maskiert für die direkte Ausgabe. In Templates, die einen Wert mehrfach ausgeben (z. B. `href` und `data-nav-href`), `safeUrl()` verwenden und erst an der Ausgabestelle maskieren.
- `Validator` – Eingabe-/URL-/Farb-/Typ-Prüfung.
- `Sanitizer` – HTML-Reinigung (Rich-Text).
- `Color` – WCAG-Farbrechnung (`blend`, `relativeLuminance`, `contrastRatio`, `bestTextColor`, `ensureContrast`, `MIN_CONTRAST = 4.5`).
- `Dates` – Datums-/Zeitraum-Helfer (u. a. für die Statistik).
- `TileColors::variables()` – kontrastsichere Textfarben einer Kachel (`--tile-text`, `--tile-muted`, `--tile-accent`, `--tile-hover-bg`, `--tile-details-text`) aus Designfarben, Kachelfarbe und Deckkraft; genutzt von `ThemeService` (Vorgaben) und `views/partials/tiles.php` (je Kachel).

### Contracts (`app/Contracts/`) & Exceptions (`app/Exceptions/`)

- Interfaces: `AdminUserStoreInterface`, `AdGroupStoreInterface`, `LdapClientInterface`, `OfficeProbeInterface`, `PhonebookStoreInterface`, `SyncLogStoreInterface`, `OrvantaMailBackendInterface` (Mail-Backend von Orvanta: `OrvantaExchangeService` bzw. `ProxyMailBackend`, Capabilities), `MailProxyTransportInterface` (Transport zum Container `mail-proxy`).
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
| POST | `/internal/auth-metrics` | `InternalController::authMetrics` – Kennzahlen der Hauptinstanz `auth` (CPU-Auslastung im Container, im Messfenster aufgebaute Client-Verbindungen, Anfragen je Quellnetz; `docker/auth/metrics.py`), Ablage in `auth_metrics` für die Kachel „Reverse-Proxy“ auf dem Admin-Dashboard; Token wie `sso-config`, ohne `X-Forwarded-*`, ungültige Werte → 422 |
| GET | `/internal/sso-config?source=KEY` | `InternalController::ssoConfig` – Domänen-Konfiguration inkl. entschlüsselter Zugangsdaten für die auth-Container; nur mit Token (`X-Intranet-Sso-Token`, Datei im Volume `sso_token`), ohne `X-Forwarded-*` und nur vom passenden auth-Container; im auth-Proxy per 404 gesperrt |
| GET | `/internal/nav-proxy-config` | `InternalController::navProxyConfig` – Weiterleitungsziele externer Navigationskacheln (aktive Kacheln mit `proxy_enabled`, je `NAV_PROXY_<n>_PATH/_TARGET/_TITLE` als `NAME=base64`) für `docker/auth/weiterleitung-sync.sh`; Token wie `sso-config`, ohne `X-Forwarded-*`, `SSO_SOURCE` wählt die Instanz |
| GET | `/weiterleitung-nicht-verfuegbar` | `WeiterleitungController::unavailable` – Hinweisseite, wenn der auth-Container ein Weiterleitungsziel nicht erreicht (ErrorDocument 500/502/503 des Adressraums `/weiterleitung/<id>/`) |
| GET/POST | `/admin/login` | `AuthController::showLogin` / `login` |
| GET | `/admin/login/windows` | `AuthController::windowsLogin` – Anmeldung per Windows-Anmeldung (SSO) für Mitglieder der Intranet-Admin-AD-Gruppen; ohne erkannte Identität einmal Umweg über `/sso/anmelden` (`?versucht=1`) |

### Admin (Middleware `$requireAuth`, angemeldet)

`POST /admin/logout`, `GET /admin` (Dashboard) und alle `wichtige-links`-Routen.

### Admin (Middleware `$requireAdmin`, nur Rolle `admin`)

Alle übrigen Admin-Routen: `navigation`, `notfallnummern`, `telefonliste`,
`mitteilungen`, `beschreibungen`, `design`, `ad`, `alarmierung`
(inkl. `alarmierung/gruppen`), `aktivierungs-rufnummern`, `zertifikate` (inkl. `zertifikate/csr` (POST erstellen, GET `?id=` herunterladen), `zertifikate/import/pruefen`, `…/import/bestaetigen`, `…/import/verwerfen`, `zertifikate/aktivieren`, `…/deaktivieren`, `…/loeschen`, `…/http-netze`), `snmp`, `statistik`
(+ `admin/api/statistik`), `benutzer` (inkl. `benutzer/ad-gruppen`, `…/ad-gruppen/loeschen`, `…/nextcloud-uebertragen`), `sicherung` (Export/Import), `office`
(eigene Unterseiten je Bereich, GET: `office` (Status & Diagnose), `office/fusszeile`, `office/ki`, `office/app-store`, `office/orvanta`, `office/kachel`, `office/sicherung` – jeweils `Admin\OfficeController::render()` mit `$section`, Zuordnung in `OfficeController::PAGES`; POST der Fußzeile an `office/fusszeile`;
inkl. `office/pruefen`, `office/sicherung`, `office/kachel`, `office/kachel/gestaltung`,
`office/kachel/vorschau`, `office/apps` inkl. `office/apps/owa`, `office/apps/freigaben`, `office/apps/paket`, `office/apps/paket/loeschen`, `office/ki`, `office/ki/testen` (JSON: Verbindungstest der KI mit Testnachricht „Wer bist du?“ für das Overlay), `office/app-store`, `office/orvanta`, `office/orvanta/pruefen`, Exchange-DAG (`Admin\OrvantaHostController`): `office/orvanta/hosts` (Dashboard, GET) inkl. GET `office/orvanta/hosts/daten` (Kachelwerte als JSON) und POST `office/orvanta/hosts` (Hosts ergänzen, DAG bestätigt), `office/orvanta/hosts/status` (Wartung/aktivieren), `office/orvanta/hosts/loeschen`, `office/orvanta/hosts/pruefen` (Verbindungstest mit Prüfpostfach), `office/orvanta/hosts/pruefpostfach` (Prüfpostfach `exchange_test_mailbox` speichern), `office/signaturen` inkl. `office/signaturen/vorlage` (GET/POST), `office/signaturen/loeschen`, `office/signaturen/vorschau` (iframe mit eigener CSP), `office/abwesenheit` (Abwesenheitsnotiz-Vorlagen, `Admin\OrvantaOofController`) inkl. `office/abwesenheit/vorlage` (GET/POST), `office/abwesenheit/loeschen`, `office/abwesenheit/vorschau` (iframe mit eigener CSP), `office/orvanta/postfaecher` (Übersicht der aus Exchange übernommenen zusätzlichen Postfächer je Benutzer, nur lesend, `Admin\OrvantaSharedMailboxController`) inkl. `office/orvanta/postfaecher/pruefen`, Nachrichtenfluss-Dashboard (`Admin\OrvantaFlowController`, Konzept `docs/orvanta-nachrichtenfluss.md`): `office/orvanta/nachrichtenfluss` (Dashboard, GET) inkl. GET `office/orvanta/nachrichtenfluss/daten` (Kennzahlen und Knoten als JSON, ohne Caching), GET `office/orvanta/nachrichtenfluss/topologie` (Topologie-Ansicht als animiertes Canvas-Netz im eigenen Tab, Layout `layouts.editor`) und POST `office/orvanta/nachrichtenfluss/quellen/pruefen` (Verbindungstest je Identitätsquelle, CSRF zuerst, höchstens 16 aktive Quellen je Durchgang), `office/mail-proxy` (SMTP-/IMAP-Proxy, `Admin\MailProxyController`) inkl. GET-Overlays `…/server/neu`, `…/server/bearbeiten?id=`, `…/postfach/neu?quelle=`, `…/postfach/bearbeiten?id=`, POST `…/server`, `…/server/status`, `…/server/loeschen`, `…/postfach`, `…/postfach/loeschen`, `…/postfach/test`, `…/zuordnung`, `…/zuordnung/loeschen` und JSON-Vorschläge GET `…/users`, `…/mailboxes`), `speicherplatz` (inkl. `speicherplatz/standard`, `…/gruppen`, `…/gruppen/loeschen`, `…/benutzer` (GET `?id=`/POST), `…/benutzer/entfernen`, `…/uebertragen`, `…/verlauf`), `netzlaufwerke` (inkl. `…/einstellungen`, `…/benutzer/entfernen`, `…/uebertragen`, `…/skript`), `speicher-ha` (inkl. `…/status` (JSON, Live-Anzeige), `…/einstellungen`, `…/ziel` (GET `?id=`/POST), `…/ziel/loeschen`, `…/erweitern` (GET/POST: alle Cold-Tiers gemeinsam um je ein Ziel derselben Art erweitern), `…/auftrag`), `ad/gruppen` (JSON-Vorschläge aus dem synchronisierten Bestand).

Office öffentlich: `GET /office-starten` (Übersicht der freigegebenen Office-Apps), `GET /office-app?app=…` (Start einer App, prüft Freigabe), `GET /office-nicht-verfuegbar`,
`GET /api/office/footer` (Konfiguration der Fußzeile), `GET /api/office/status` (Verfügbarkeit für die Kachel).
LLMInt öffentlich: `GET /ki-nicht-verfuegbar` (`KiController::unavailable`, Status 503) – ErrorDocument 500/502/503 des auth-Proxys für `/ki/` (`docker/auth/llmint.conf`); `/ki/…` selbst gehört nicht zur Anwendung, sondern wird im `auth`-Container an LLMInt weitergeleitet.
Orvanta (SSO-Benutzer mit App-Freigabe, Prüfung in `OrvantaController::authorize`): `GET /office/orvanta`, `GET /office/orvanta/anhang/oeffnen`, `GET /office/orvanta/anhang/datei` (Token-Zugriff des DocumentServers), JSON-API `GET/POST /api/orvanta/…` (`status`, `sitzung` (Keep-alive/CSRF-Token und aktueller Exchange-Host), `mail/*`, `anhang/*`, `zwischenspeicher*`, `kalender/*` (zusätzlich `kalender/postfach`: Kalender eines weiteren Postfachs ein-/ausblenden), `kontakte/*`, `aufgaben/*`, `notizen/*`, `erinnerungen/*`, `ki/verbessern`, `rechtschreibung/pruefen`, `rechtschreibung/vorschlaege`, `rechtschreibung/woerterbuch` (GET/POST, persönliches Wörterbuch), `rechtschreibung/woerterbuch/entfernen`, Abwesenheitsnotiz `abwesenheit` (GET/POST), Langzeitarchiv lesend `archiv/status`, `archiv/ordner`, `archiv/mail`, `archiv/mail/detail`, `archiv/suche`; vollständige Liste in `docs/orvanta.md`).

**Middleware-Verhalten:** `$requireAuth` → Redirect auf `/admin/login` (bzw. JSON 401 bei `/admin/api/*`);
`$requireAdmin` → HTTP 403 (bzw. JSON 403 bei `/admin/api/*`).

**Rollen:** `admin` (voller Zugriff inkl. Benutzerverwaltung) · `redaktion` (nur „Wichtige Links“).

---

## 9. Datenbankschema

Migrationen liegen in `database/migrations/` (numerisch sortiert, werden von `migrate.php` angewendet und in `schema_migrations` protokolliert).

| Tabelle | Zweck | Wichtige Spalten |
| --- | --- | --- |
| `navigation_items` | Kacheln/Unterseiten/Textseiten/Alarmierungen | `type` (external/internal/subpage/page/alarm), `parent_id`, `content`, `alarm_text`, `alarm_group_id`, `protected_access`, `proxy_enabled` (Migration 051: externes Ziel über den Reverse-Proxy des auth-Containers aufrufen), `proxy_bypass_networks` (Migration 051: Quellnetze in CIDR, leerzeichengetrennt, die das Ziel direkt aufrufen), `background_color`, `background_opacity`, `override_background`, `sort_order`, `active` |
| `settings` | Schlüssel-Wert-Einstellungen | `setting_key` (unique), `setting_value` |
| `phonebook` | AD-synchronisierte Telefonliste | `external_id` (unique), `identity_source_id` (0 = Hauptquelle), Name, `title` (Position, Migration 035, `LDAP_ATTR_TITLE`), `phone`, `phone_digits`, `mobile`, `department`, `active` |
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
| `identity_sources` | Weitere AD-Quellen (Zweigstellen, Tochtergesellschaften) | `source_key` (unique), `label`, `hosts` (je Zeile ein Server, Ausfallreserve), LDAP-Felder, `bind_password` (verschlüsselt), `sso_enabled`, `sso_domain`, `sso_dcs`, `sso_ntp_servers`, `sso_join_user`, `sso_join_password` (verschlüsselt), `sso_networks`, `sso_hostnames`, `sort_order`, `active` |
| `tls_certificates` | CSR-Requests mit Schlüssel (verschlüsselt) und importiertem Zertifikat; `kind='fallback'` = selbstsigniertes Notfall-Zertifikat | `kind` (csr/fallback), `common_name`, `san`, `key_type`, `private_key`, `public_key_hash`, `csr_pem`, `certificate_pem`, `chain_pem`, `cert_*` (Details, `cert_not_before`/`cert_not_after` als Unix-Zeit), `active` (genau eins), `activated_at`, `first_used_at`/`last_used_at` |
| `office_app_permissions` | Freigabe von Apps/Paketen für AD-Gruppen | `group_name`, `app_key` oder `package_id` |
| `admin_group_rules` | AD-Gruppen, deren Mitglieder Intranet- bzw. Nextcloud-Administratoren sind | `target` (intranet/nextcloud), `group_name` (unique je Ziel), `created_by` |
| `storage_quota_groups` | Speicherplatz-Kontingent je AD-Gruppe | `group_name` (unique), `quota_mb`, `reason`, `updated_by` |
| `storage_quota_overrides` | Individuelles Kontingent je Nextcloud-Kennung | `user_uid` (unique), `display_name`, `quota_mb`, `reason` (Pflicht), `created_by`, `updated_by` |
| `storage_quota_history` | Verlauf aller Kontingentänderungen | `subject_type` (user/group/default), `subject`, `action` (set/change/remove), `old_quota_mb`, `new_quota_mb`, `reason`, `admin_username` |
| `network_drives` | Von den Windows-Clients gemeldete Netzlaufwerke | `user_uid` (immer klein geschrieben, `normalizeUid()`), `drive_letter` (unique je `user_uid`), `display_name`, `unc_path`, `domain`, `computer_name`, `reported_at` |
| `storage_targets` | Speicherziele des Cold-Tiers (SMB-/S3-Tier) | `label`, `kind` (`smb`/`s3`), `unc_path` (bei S3 kanonischer Ort `s3://host/bucket/präfix`), `username` (bei S3 Access Key), `domain`, `password` (verschlüsselt; bei S3 Secret Key), `smb_version`, `s3_endpoint`, `s3_region`, `s3_bucket`, `s3_prefix`, `s3_path_style`, `s3_verify_tls`, `capacity_bytes` (S3-Kontingent, 0 = ohne Grenze), `is_primary`, `active`, `parent_id` (Migration 031: `NULL` = Basisziel eines Cold-Tiers, sonst Erweiterung dieses Basisziels; gleiche Art, eine Kopie je Tier, Verteilung per `Agent/TierLayout`) |
| `storage_target_status`, `storage_status` | Zustand je Ziel bzw. gesamt (von `storage-sync` geschrieben) | Füllstand, MB/s, IOPS, Synchronität, Rückstand, Modus, Zähler |
| `storage_incidents` | Sicherheitsvorfälle (Ransomware/verdächtiges Überschreiben) | `status` (open/resolved), `uid`, `rules`, `files_*`, `bytes`, `source`, `details` (JSON), `user_restricted`, `frozen_target_id`, `resolved_at`, `resolved_by` |
| `storage_usage_samples`, `storage_events`, `storage_requests` | Verlauf (Diagramme, Hochrechnung), Ereignisse, Aufträge aus dem Adminbereich (`sync_now`, `full_scan`, `remount`, `confirm_deletes`, `snapshot_remount`, `snapshot_restore` mit `detail` = Kennung) | |
| `storage_snapshots`, `storage_snapshot_status` | Dateiversionen des Snapshot-Speichers (Spiegel des Agent-Katalogs) und Zustand der Snapshot-Freigabe | `uid` (sha1, unique), `source`, `path`, `path_hash`, `user`, `version`, `size`, `file_mtime`, `sha256`, `status` (complete/pending/failed/unavailable), `file_deleted`, `created_at`, `stored_at`, `restored_at`, `restored_by`; Status: `state`, Füllstand, Raten, Zähler |
| `orvanta_settings` | Einstellungen der Mail-App Orvanta (Migration 033) | `setting_key` (unique), `setting_value` (Dienstkonto-Kennwort verschlüsselt) |
| `orvanta_reminders` | Lokal zwischengespeicherte Terminerinnerungen je Benutzer | `user_uid` + `item_hash` (unique), `subject`, `location`, `starts_at`, `remind_at`, `state` (pending/delivered/dismissed/snoozed) |
| `orvanta_cache_items` | Bestand des Orvanta-Zwischenspeichers im Nextcloud-Bereich des Benutzers (eigenes Quota) | `user_uid` + `item_hash` (unique), `kind` (attachment/message), `name`, `path`, `content_type`, `size_bytes` |
| `orvanta_signatures` | Orvanta-Signaturvorlagen (Migrationen 035–037; Zuordnung per AD-Gruppe, serverseitig beim Senden angefügt) | `name`, `greeting`, `name_format` (first_last/last_first), `street`, `postal_city`, `phone_mode` (prefix/full), `phone_prefix`, `text_color`/`separator_color` (Schlüssel einer Designfarbe), `ad_groups` (JSON), `sort_order`, `active` |
| `orvanta_ai_usage` | Nutzungszähler der KI-Textunterstützung in Orvanta (Migration 034), nur Zahlen, keine Texte | `user_uid`, `kind` (mail_compose/mail_reply/mail_forward/event/reminder), `model`, `input_tokens`, `output_tokens`, `created_at` |
| `orvanta_spellcheck_words` | Persönliches Wörterbuch der Orvanta-Rechtschreibprüfung (Migration 042, höchstens 1000 Wörter je Benutzer) | `user_uid` (klein geschrieben), `word` (`utf8mb4_bin`, unique je Benutzer), `created_at` |
| `orvanta_exchange_hosts` | Hosts der Exchange-DAG für die Lastverteilung in Orvanta (Migration 043); der in den Einstellungen eingetragene Server ist immer `is_primary`; gestörte Hosts mit veraltetem `last_check_at` werden automatisch nachgeprüft (Abschnitt 20.5) – siehe `docs/orvanta-referenz.md` Abschnitt 20 | `host` (unique), `ews_url` (leer = `https://<host>/EWS/Exchange.asmx`), `is_primary`, `active` (0 = Wartung), `sort_order`, `latency_ms` (gleitendes Mittel), `latency_samples`, `last_latency_ms`, `last_session_at`, `last_check_at`, `last_ok`, `last_error`, `failures` |
| `orvanta_exchange_sessions` | Zuordnung laufender Orvanta-Sitzungen zu einem Exchange-Host (Sitzungsaffinität je Benutzer und Client, Umleitungen; Clientangaben aus Migration 044) | `session_hash` (sha1 von Benutzer + Client-IP, unique), `user_uid`, `client_ip`/`client_host` (IP und Reverse-DNS-Name des Clients beim Sitzungsbeginn), `host`, `failovers`, `requests`, `started_at`, `last_seen_at` |
| `orvanta_oof_templates` | Vorlagen der Orvanta-Abwesenheitsnotiz (Migration 045; Zuordnung per AD-Gruppe) – siehe `docs/orvanta-referenz.md` Abschnitt 21 | `name`, `fixed_text` (fester, für den Benutzer schreibgeschützter Teil), `example_text` (dynamischer Beispieltext), `ad_groups` (JSON), `sort_order`, `active` |
| `orvanta_oof_settings` | Letzter Stand der Abwesenheitsnotiz je Benutzer (Migration 045); maßgeblich ist der Zustand auf dem Exchange-Server | `user_uid` (Primary Key), `template_id`, `dynamic_text`, `external_audience` (none/all), `schedule_mode` (range/until_off), `start_date`/`end_date`, `active` |
| `orvanta_shared_mailboxes` | Zusätzlich per Vollzugriff berechtigte Postfächer je Benutzer (Migration 046; ausschließlich aus dem Auto-Mapping des AD übernommen, Vollzugriff per EWS als Benutzer geprüft – Migration 047 setzt bestehende Prüfungen zurück, nicht Teil der Archivierung) – siehe `docs/orvanta-referenz.md` Abschnitt 22 | `user_uid`, `email` (unique je Benutzer), `display_name`, `send_as` („Senden als“), `active`, `verified_at`/`verify_error`/`checked_at` (letzte Prüfung), `calendar_visible` (Checkbox der Kalenderansicht), `sort_order` |
| `orvanta_archives`, `orvanta_archive_folders`, `orvanta_archive_items`, `orvanta_archive_jobs` | Orvanta-Langzeitarchiv (Migration 038): Archiv je Benutzer, Ordnerabbild, Journal/Suchindex je Nachricht (Inhalte liegen nur in Containern im Nextcloud-Bereich), Läufe inkl. Sperre – siehe `docs/orvanta-referenz.md` Abschnitt 17 | `user_uid` (unique), `archive_id` + `folder_hash`/`item_hash` (unique), `internet_message_id`, `content_hash`, `chunk_name`/`chunk_offset`/`chunk_length`, `search_text`, `status` (pending/committed/deleted/failed bzw. running/completed/failed), `locked_until` |
| `mail_proxy_servers` | SMTP-/IMAP-Mailserver für Orvanta-Benutzer ohne Exchange (Migration 039), einer je Identitätsquelle – siehe `docs/mail-proxy.md` | `identity_source_id` (unique, 0 = Hauptquelle, ohne FK), `smtp_host`/`smtp_port`/`smtp_security` (starttls/tls/none)/`smtp_auth`, `imap_host`/`imap_port`/`imap_security` (tls/starttls), `verify_tls`, `timeout_seconds`, `active` |
| `mail_proxy_mailboxes` | Postfächer eines Proxy-Mailservers | `server_id` (FK), `username`, `email_address` (unique je Server), `display_name`, `quota_mb` (feste Postfachgröße für die Belegungsanzeige, 0 = ohne Grenze), `password_encrypted` (nur SecretBox-Chiffrat), `active` |
| `mail_proxy_mappings` | Zuordnung AD-Benutzer → Postfach (je Benutzer und je Postfach höchstens eine) | `identity_source_id`, `phonebook_id` (unique, FK `phonebook`), `mailbox_id` (unique, FK) |
| `mail_proxy_state` | Genau eine Zeile: Cache-Generation (bei jeder Änderung +1) und Diagnose | `generation`, `last_success_at`, `last_error_at`, `last_error` |
| `mail_proxy_source_state` | Zustand je Identitätsquelle im Mailpfad (Migration 048; Erfolg/Fehler/Prüfzeitpunkt je Quelle, Grundlage des Nachrichtenfluss-Dashboards) – siehe `docs/orvanta-nachrichtenfluss.md` | `identity_source_id` (Primary Key, 0 = Hauptquelle), `last_success_at`, `last_error_at`, `last_error` (auf 500 Zeichen begrenzt), `failures`, `checked_at` |
| `orvanta_activity` | Aktive Orvanta-Nutzer der letzten Minuten (Migration 048/049; je Benutzer eine Zeile, ohne Inhalte) | `user_uid` (Primary Key), `backend` (exchange/proxy), `source_id` (Identitätsquelle, 0 = Hauptquelle; Migration 049), `first_seen_at`, `last_seen_at`, `requests` |
| `orvanta_user_samples` | Minutenproben der aktiven Nutzer (Migration 048; genau eine Zeile je Zeitraster, Grundlage der Kennzahlen und des Verlaufs) | `sampled_at` (unique), `active_users`, `exchange_users`, `proxy_users`, `ai_users` |
| `orvanta_source_samples` | Minutenproben der aktiven Nutzer je Identitätsquelle (Migration 049; gleicher Zeitstempel wie `orvanta_user_samples`, Grundlage der Kennzahlen an den Quellen in der Topologie; Summe je Probe = Gesamtwert) | `sampled_at` + `source_id` (unique), `active_users` |
| `auth_metrics` | Proben der Hauptinstanz `auth` für die Kachel „Reverse-Proxy“ auf dem Admin-Dashboard (Migration 050, Messwerte des Fensters ab 051; geschrieben von `POST /internal/auth-metrics`, gelesen von `AuthMetricsService`; Aufbewahrung 48 h, Aufräumen beim Schreiben) | `recorded_at`, `cpu_percent` (0–100, bezogen auf `cpu_limit`), `cpu_limit` (Kerne aus `cpu.max`, ohne Limit 1), `connections` (im Messfenster aufgebaute Client-Verbindungen), `sources` (JSON: Quellnetz → Anfragen, `NULL` ohne Anfragen) |

**Konventionen:** `InnoDB`, `utf8mb4`/`utf8mb4_unicode_ci`, `TIMESTAMP`-Spalten `created_at`/`updated_at`, `TINYINT(1)` für Booleans (`active`), Fremdschlüssel mit `ON DELETE SET NULL`/`ON UPDATE CASCADE`.

---

## 10. Konfiguration

**Vorrangregel: Umgebungsvariablen liefern die Grundeinstellung, die Tabelle `settings` überschreibt sie** AD-Zugangsdaten (Bind-Passwörter, Domänenbeitritt) stehen ausschließlich verschlüsselt in der Datenbank (`settings`: `ldap_bind_password`, `sso_join_password` verschlüsselt, `sso_domain`, `sso_dcs`, `sso_ntp_servers`, `sso_join_user`; `identity_sources`) und werden im Adminbereich gepflegt – nicht in der `.env`. Alt-Werte aus der `.env` übernimmt `scripts/credentials.php` beim Start.

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
3. Rolle `app`: `migrate.php` → `credentials.php` (Schlüssel, Alt-Import) → Token für `/internal/sso-config` in `/run/intranet-sso/token` (Volume `sso_token`) → ggf. `seed.php` (`SEED_ON_START`) → ggf. `create_admin.php` → `spellcheck_dictionary.php` (lädt das Wörterbuch der Orvanta-Rechtschreibprüfung einmalig herunter und übersetzt es nach `storage/dictionaries/de_DE`; idempotent, Fehler nur Warnung).
4. Rolle `sync`: kurz warten (Migrationen abwarten), `credentials.php --key`, dann `sync_worker.php` (Dauerschleife).
5. `exec "$@"` (App: `apache2-foreground`; Sync: `php scripts/sync_worker.php`).

### auth-Container (`docker/auth/entrypoint.sh`)

- Ruft beim Start `/internal/sso-config?source=<SSO_SOURCE>` von `app` ab (Token aus `sso_token`, Wiederholungen) und tritt damit der Domäne bei; Änderungen erfordern einen Neustart (`docker compose restart auth auth-<kennung>`).
- Zeitabgleich: chrony gegen `SSO_NTP` (Einstellung `sso_ntp_servers`, sonst die DCs); mit `cap_add: SYS_TIME` (Compose, `sso-domains.sh`) `chronyd -q` zum Stellen + Daemon, sonst `chronyd -Q` nur messen, Warnung ab 300 s.
- Samba: Realm per `net ads lookup` (CLDAP) vom DC bzw. `SSO_REALM`; damit `security = ads`, `kerberos method = secrets and keytab`, `/etc/krb5.conf` mit fester KDC-Liste, Beitritt per `net ads join` (ein alter RPC-Beitritt liefert keine passenden Kerberos-Schlüssel – Prüfung per `kinit -k` mit dem Computerkonto – und wird mit dem Beitrittskonto erneuert), SPNs `HTTP/<Hostname aus APP_URL>` + `SSO_SPN_HOSTS` per `net ads setspn add`, Keytab `/etc/krb5.keytab` (root:www-data 0640) → Apache `-D SSO_KRB5` (`GssapiAllowedMech krb5` + `GssapiCredStore keytab:`). Ohne Realm Rückfall auf `security = domain` + `winbind rpc only` (`net rpc join`, nur NTLM). Fester Computername `SSO_NETBIOS_NAME` (Compose-`hostname`, Standard `lanpa-sso`); der Beitritt (`/var/lib/samba`) liegt im Volume `sso_samba` bzw. `auth_<kennung>_samba`.
- gss-ntlmssp wird im Dockerfile aus den Debian-Quellen mit `docker/auth/patches/` gebaut: NTLM-NEGOTIATE ohne Versionsfeld (32 Byte, Chromium/Firefox-internes NTLM) akzeptieren; Server-Credential auch bei `GssapiCredStore` ohne Acceptor-Namen.
- HTTPS (`TLS_ENABLED=true`, nur Hauptinstanz): `tls-sync.sh once` holt vor dem Start `/internal/tls-config` und schreibt `/etc/intranet-tls/` (`cert.pem`, `key.pem`, `server.pem`, `mode`, `networks`; prüft, dass Schlüssel und Zertifikat zusammenpassen; ohne Anwendung lokales selbstsigniertes Zertifikat). `tls-sync.sh loop` gleicht alle `TLS_SYNC_INTERVAL` (60) Sekunden ab und lädt nur bei Änderungen neu (`apache2ctl graceful` bzw. HAProxy `-sf`).
- Ohne Verteiler: Apache mit `-D TLS_APACHE` – HTTP-VirtualHost bindet `http-policy.conf` (Umleitung 302 auf `https://host:HTTPS_PUBLIC_PORT`, im Modus fallback nicht für die Quellnetze, nie für `/auth-health`) und den gemeinsamen Inhalt `common.conf` ein, HTTPS-VirtualHost `*:443` ebenso `common.conf`; `X-Forwarded-Proto` je nach `%{HTTPS}`. Mit Verteiler (`-D TLS_PROXY`): HAProxy terminiert TLS auf `:443` (`alpn http/1.1`), setzt `X-Forwarded-Proto` und leitet selbst um (`sso-routes.sh` liest `/etc/intranet-tls/`).
- Hauptinstanz `auth`: HAProxy verteilt per `SSO_ROUTES` (Hostname/Client-Netz) an die Worker `auth-<kennung>` (`sso-routes.sh`, Fallback auf die Hauptinstanz, wenn ein Worker ausfällt); Worker aus `docker-compose.sso.yml` (`scripts/sso-domains.sh`).
- Kennzahlen (nur Hauptinstanz, ohne `SSO_SOURCE`): `metrics.py loop` misst alle `AUTH_METRICS_INTERVAL` (Standard 60) Sekunden die CPU-Auslastung im Container (cgroup `cpu.stat`/`cpu.max`, ohne Limit bezogen auf einen Kern), die im Messfenster aufgebauten Client-Verbindungen und die Anfragen je Quellnetz (beides aus dem Zugriffsprotokoll `intranet-metrics.log`, das `common.conf` nur mit `-D AUTH_METRICS` schreibt: eine Zeile je Anfrage mit Client-Adresse und Quellport, der eigene Healthcheck bleibt aussen vor, und `metrics.py` leert die Datei bei jeder Probe) und meldet sie mit dem Token aus `sso_token` an `POST /internal/auth-metrics` (Ziel `AUTH_METRICS_URL`, Standard `http://app/internal/auth-metrics`). Gemessen wird bewusst das Fenster statt einer Momentaufnahme: der Reverse-Proxy schließt eine Verbindung schon wenige Sekunden nach der letzten Anfrage (`KeepAliveTimeout`), eine Momentaufnahme offener Verbindungen träfe die Proben daher praktisch nie; ein Zähler des Betriebssystems (`PassiveOpens`) würde zudem die Healthchecks des Containers mitzählen. Die Werte erscheinen als Kachel „Reverse-Proxy“ auf dem Admin-Dashboard (`AuthMetricsService`, Tabelle `auth_metrics`, gelb ab 75 %, rot ab 90 % CPU; Klick auf die Kachel öffnet Details und Verlaufsgrafiken). Nur Zähler, keine Kennungen; ohne erreichbare Anwendung wird die Probe verworfen und erneut versucht.
- Ressourcen (Schalter in der `.env`): Zugewiesen = `AUTH_CPUS`/`AUTH_MEMORY_LIMIT`, Reserviert = `AUTH_MEMORY_RESERVED`/`AUTH_CPU_SHARES`; `docker-compose.yml` übergibt sie als `cpus`/`mem_limit`/`mem_reservation`/`cpu_shares` (Standard 0 = keine Grenze, `cpu_shares` 1024 = Docker-Standard). Nur die Hauptinstanz; die Worker aus `scripts/sso-domains.sh` haben eigene Werte. Änderungen greifen erst mit `docker compose up -d auth` (Neuanlegen), siehe [installation.md](docs/installation.md), Abschnitt „Dimensionierung“.
- Weiterleitung externer Navigationskacheln (`docs/weiterleitung.md`): `weiterleitung-sync.sh once` holt vor dem Start, `weiterleitung-sync.sh loop` alle `NAV_PROXY_INTERVAL` (Standard 60) Sekunden die Ziele aus `GET /internal/nav-proxy-config` (Token aus `sso_token`, `SSO_SOURCE` wählt die Instanz) und erzeugt daraus `/etc/apache2/intranet/weiterleitung.conf` (Adressraum `/weiterleitung/<id>/`, `<Location>` je Kachel, `ProxyPass`/`ProxyPassReverse`, Cookie- und `Location`-Umschreibung, Host-Tarnung, `ProxyHTMLURLMap`/`Substitute`, `no-gzip`, ErrorDocument → `/weiterleitung-nicht-verfuegbar`; bei `https`-Zielen `SSLProxyEngine` mit `NAV_PROXY_SSL_VERIFY`). Die Datei wird bedingungslos vor dem Auffang-`ProxyPass /` eingebunden und ist auch leer vorhanden (leerer Adressraum bleibt beim Proxy). Übernommen wird nur, was `apache2ctl -t` akzeptiert; sonst bleibt der bisherige Stand aktiv (Warnung). Die Quellnetz-Ausnahmen wertet die Anwendung aus, nicht der Proxy.
- LLMInt (`LLMINT_ENABLED=true`): `entrypoint.sh` prüft `LLMINT_PATH` (Standard `/ki`, Format `^/[a-z0-9][a-z0-9_-]*$`, belegte Pfade abgelehnt) und `LLMINT_UPSTREAM` (`http(s)://host[:port]`); ungültig → Warnung, Proxy aus. Sonst `-D LLMINT` (+ `-D LLMINT_TLS` bei `https://`) → `common.conf` bindet `llmint.conf` ein: `/ki` → 301 `/ki/`; `<Location /ki/>` `ProxyPass ${LLMINT_UPSTREAM}/ timeout=3600 flushpackets=on retry=5`, `ProxyPassReverse`, `ProxyPassReverseCookiePath / /ki/`, `no-gzip`, X-Forwarded-For/-Host/-Server des Clients verworfen (mod_proxy setzt neu), `X-Forwarded-Prefix`, `X-Forwarded-Proto` wie Office, X-Remote-User/-Source entfernt, ErrorDocument 500/502/503 → `/ki-nicht-verfuegbar`. Danach (Reihenfolge wichtig: Header unset → set) `<Location /ki/sso.php>` nur mit `SSO_NTLM` und ohne `SSO_WORKER`: GSSAPI wie `/sso/anmelden`, `X-Remote-User`, ErrorDocument 401/500 → `/ki/sso_fallback.php` (von LLMInt, muss mit 401 antworten). Hostname-Upstreams überwacht `backend-watch.sh`. Netz `llmint-proxy` per `docker-compose.llmint.yml` (auch für Worker, wenn in `COMPOSE_FILE`; `sso-domains.sh`).

### SNMP-Container-Startup (`docker/snmp/entrypoint.sh`)

1. Liest `SNMP_COMMUNITY`, `SNMP_SYS_LOCATION`, `SNMP_SYS_CONTACT` aus der Umgebung.
2. Überschreibt diese mit den Werten aus der Tabelle `settings` (`snmp_community`, `snmp_sys_location`, `snmp_sys_contact`), falls die Datenbank erreichbar ist.
3. Erzeugt `snmpd.conf` und hängt die Status-Checks als `exec`-Einträge an (`UCD-SNMP-MIB::extTable`, Basis `.1.3.6.1.4.1.2021.8.1`): `app`, `db`, `sync`, `sync_workflow`, `phpmyadmin`, die Office-Dienste sowie `tls_certificate`/`tls_certificate_days` und das Speicher-Tiering (13–17, Extends `storage_metrics`/`storage_targets`).
4. `exec snmpd -f -Lo -C -c /etc/snmp/snmpd.conf` (lauscht auf UDP 161).

### AD-Synchronisation

- Adminbereich „Active Directory“ (Navigationsgruppe): Unterpunkt „Identitätsquellen“ (`/admin/ad`) listet die Quellen; „Bearbeiten“/„Identitätsquelle hinzufügen“ öffnen ein Overlay (`views/admin/ldap/source.php`, serverseitig gerendert über `/admin/ad/hauptquelle`, `/admin/ad/quellen/bearbeiten?id=…`, `/admin/ad/quellen/neu`, modal geöffnet von `admin-ldap.js`; Escape/Abbrechen → `/admin/ad`). Unterpunkt „Synchronisation“ (`/admin/ad/synchronisation`): manueller Lauf, Intervall (`POST /admin/ad/synchronisation`) und Protokoll.
- Einzellauf: `php scripts/sync_ad.php` oder Schaltfläche auf der Seite „Synchronisation“ bzw. „Jetzt synchronisieren“ auf dem Admin-Dashboard (Overlay-Markup: `views/admin/ldap/sync-dialog.php`). Die Schaltfläche (`admin-ldap.js`) sendet `POST /admin/ad/sync` mit `Accept: text/event-stream` und zeigt den Fortschritt je Identitätsquelle in einem Overlay, das nur mit „OK“ geschlossen werden kann (danach Neuladen). Events: `sources`, `source-start`, `source-done` (Ergebnis inkl. `warning` bei Gruppenfehler), `done`, `error`; Vorbedingungsfehler als JSON 422. Ohne JavaScript: Redirect mit Flash-Meldung. Ein Lauf = ein `sync_log`-Eintrag (`AdSyncService::run($onProgress)`).
- Dauerlauf: `php scripts/sync_worker.php` (Intervall `LDAP_SYNC_INTERVAL`).
- **Datensicherheit:** nicht erreichbares/leeres AD → *kein* Schreiben, letzter Stand bleibt aktiv.
- Blockweise geschrieben: `AdSyncService::writeBlock()` fasst je 200 Konten (`BLOCK_SIZE`) in eine eigene Transaktion; `deactivateStale()` und `AdGroupRepository::replaceAll()` laufen danach in einer abschließenden Transaktion. `$syncedAt` wird einmal je Lauf gebildet und durch alle Blöcke gereicht – sonst würde `deactivateStale()` die früheren Blöcke als veraltet einstufen. Bricht ein Block ab, bleiben die vorherigen gültig und es wird nichts deaktiviert.
- Nicht mehr vorhandene Personen → `active = 0` (kein Löschen); deaktivierte AD-Konten (`ACCOUNTDISABLE`) werden übersprungen/deaktiviert.
- Jeder Lauf wird in `sync_log` protokolliert und im Adminbereich angezeigt.
- Mehrere Quellen: jede Quelle wird getrennt synchronisiert (`AdSyncService`), Server je Quelle der Reihe nach versucht; fällt eine Quelle aus, bleibt ihr Stand erhalten (Status `partial`, `sync_ad.php` Exit 4).
- Gruppen: `LdapClient::fetchGroups()` liest Gruppen unter `ldap_group_base_dn` und löst Mitglieder per `LDAP_MATCHING_RULE_IN_CHAIN` auf; `AdGroupRepository::replaceAll()` schreibt sie in der abschließenden Transaktion (nach den Kontoblöcken). Bekannte Gruppen werden vorab in einer Abfrage geladen (`knownGroupIds()`), und `currentMemberIds()` vergleicht die Mitgliederliste – nur bei echter Änderung wird DELETE + INSERT ausgeführt. Fehler beim Gruppenabruf lassen den alten Gruppenstand unverändert. `SsoAuth` ergänzt die Gruppen des angemeldeten Benutzers aus diesem Bestand (keine Live-Abfrage des AD).
- Postfachgrenzen: `LdapClient::mailboxQuota()` liest für Orvanta live `mDBStorageQuota`/`mDBOverQuotaLimit`/`mDBOverHardQuotaLimit` am Benutzer bzw. bei `mDBUseDefaults` an der Postfachdatenbank (`homeMDB`); Auswertung in `mailboxQuotaFromEntries()`.
- Postfachadresse: `LdapClient::primaryMailboxAddress()` liest live die primäre SMTP-Adresse des Postfachs (`proxyAddresses`, Eintrag mit `SMTP:`); Auswertung in `primarySmtpFromProxyAddresses()`. Orvanta nutzt sie über `OrvantaMailboxResolver::address()` für die Exchange-Impersonation (je Sitzung 15 min in `orvanta_mailbox_address`), weil Anmeldename und AD-Attribut `mail` bei neuen Konten keine Postfach-Kennung sind (`ErrorNonExistentMailbox`); ohne Treffer gilt die Konfiguration (`exchange_identity`).

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
- Ausgabe in `UCD-SNMP-MIB::extTable`: `extResult` (Exit-Code) unter `.1.3.6.1.4.1.2021.8.1.100.N`, `extOutput` (Text) unter `.1.3.6.1.4.1.2021.8.1.101.N`, Index `N` = 1 `app`, 2 `db`, 3 `sync`, 4 `sync_workflow`, 5 `phpmyadmin`, 6 `nextcloud`, 7 `nextcloud_db`, 8 `nextcloud_redis`, 9 `eurooffice`, 10 `office_workflow` (6–10 nur mit Profil `office`, sonst UNKNOWN), 11 `tls_certificate` (aktives HTTPS-Zertifikat: 0 > 30 Tage, 1 ≤ 30 Tage oder keins aktiv, 2 abgelaufen/noch nicht gültig), 12 `tls_certificate_days` (gleiche Exit-Codes, Text = Resttage, `-9999` = keins aktiv), 13 `storage_ha`, 14 `storage_sync`, 15 `storage_hot_fill`, 16 `storage_cold_fill`, 17 `storage_snapshot` (Snapshot-Speicher: 3 deaktiviert, 2 nicht erreichbar, sonst Füllstand, ≥ 1 bei fehlgeschlagenen Sicherungen) (per `docker exec` im `app`-Container: `scripts/storage_status.php`); mehrzeilige Messwerte über `nsExtendOutLine."storage_metrics"`/`"storage_targets"`.
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
| Frontend-Styling | `public/assets/css/app.css` (handgeschrieben, Theme-Variablen über `ThemeService`); Kacheln lesen `--tile-bg`/`--tile-bg-opacity` sowie die kontrastsicheren `--tile-text`/`--tile-muted`/`--tile-accent`/`--tile-hover-bg`/`--tile-details-text` (Rückfall auf die Designfarben); Textfarben auf eigener Kachelfarbe werden automatisch nach WCAG angepasst (`App\Support\TileColors` + `App\Support\Color`) |

---

## 15. Referenzdokumente

- `README.md` – ausführliche Projektdokumentation (Funktionsumfang, Docker, Konfiguration, Betrieb, Sicherheit).
- `docs/manuals/anwenderhandbuch.pdf` / `administratorhandbuch.pdf` (Quellen als HTML unter `docs/manuals/`).
- `docs/projektdokumentation/` – IT-Projektdokumentation (HTML-Quelle, PDF, Abbildungen); PDF neu erzeugen mit `./docs/projektdokumentation/build.sh` (nur Docker nötig).
- `docs/screenshots/` – Screenshots der öffentlichen und Admin-Bereiche (30–56: Office, Office-Apps, AD-Gruppen und lokale KI; 60–65: Zertifikate; 70–75: Cold-Tier-Erweiterung; 80–95: Orvanta (91: Einstellungsdialog mit Postfachbelegung, 92–95: Langzeitarchiv); 96–102: SMTP-/IMAP-Proxy für Orvanta; 103: Exchange-DAG-Hosts in Orvanta; 104: Indikatoren für beantwortete und weitergeleitete Nachrichten in Orvanta Mail; 105: Termin per Drag&Drop verschieben mit Hinweisbox für die neue Startzeit; 106–109: Abwesenheitsnotizen in Orvanta (106: Vorlagenliste, 107: Vorlagenformular, 108: Dialog, 109: Banner unter dem Menüband); 110–112: zusätzliche Postfächer in Orvanta (110: Ordnerbaum mit Postfachwurzeln und Absenderauswahl im Verfassen-Dialog, 111: Kalenderauswahl der weiteren Postfächer, 112: Adminbereich „Weitere Postfächer“ (Übersicht aus Exchange) mit Erreichbarkeitsprüfung); 113–120: Orvanta-Nachrichtenfluss (113–117: Kartendashboard, 118: Topologie mit Zuordnung der Identitätsquellen, 119: Topologie mit Kennzahlen an den Knoten, 120: 2D-Topologie mit lokalem VM-Speicher und Speicher-Tiers); 121: Ordnerbaum zusätzlicher Postfächer mit der Hierarchie des Exchange-Servers (Wurzelknoten aufgeklappt bzw. eingeklappt); 122–125: Kachel „Reverse-Proxy“ des Admin-Dashboards aus den Kennzahlen des `auth`-Containers (122: Kachel mit CPU-Last, Verbindungen des Messfensters und Quellnetzen, 123: aufgeklappte Bereiche Verbindungen und Anfragen nach Quellnetz, 124: gelbe Warnstufe ab 75 % CPU, 125: Overlay mit Details und Verlaufsgrafiken samt Wertanzeige beim Überfahren); 126–127: Weiterleitung externer Navigationskacheln über den `auth`-Container (126: Proxy-Optionen im Kachelformular des Adminbereichs, 127: Hinweisseite, wenn der Proxy ein Ziel nicht erreicht); aufgenommen mit einem isolierten Docker-Stack und Demodaten); `docs/assets/orvanta-ai/` – Screenshots der KI-Unterstützung in Orvanta (mit llama.cpp-Testendpunkt, Profil `ki`).
- `docs/installation.md` – Assistierte Installation mit `scripts/install.sh`: Ablauf, Optionen, Bedienung, abgefragte Variablen, Abschlussbericht, Fehlerbehebung.
- `docs/db-performance-bericht.md` – Analyse der Datenbank-Performance bei 1500 Mitarbeitenden und 750 Clients: Methodik, Kernbefund, Lastprofil, Engpässe (Verbindungsbudget, InnoDB, AD-Sync, Netzlaufwerke), Priorisierung P1–P3.
- `docs/db-performance-p1-umsetzungsplan.md` – Begründung und Abwägung der umgesetzten P1-Maßnahmen inklusive der bewusst nicht geänderten Serverparameter; enthält die Rechenregel für das Verbindungsbudget.
- `docs/office.md` – Office-Erweiterung: Einrichtung, Architektur, Updates, AD-Gruppen/SSO, Kachel, lokale KI, Sicherung, SNMP.
- `docs/llmint.md` – LLMInt (KI-Oberfläche, eigener Stack) unter `/ki/` hinter dem `auth`-Container: Variablen, Einrichtung (gemeinsames Docker-Netz bzw. anderer Host), Windows-Anmeldung über `/ki/sso.php`, Proxy-Verhalten, Same-Origin-Sicherheit, Fehlersuche.
- `docs/weiterleitung.md` – Externe Navigationskacheln über den Reverse-Proxy (`/weiterleitung/<id>/`): Aktivierung im Adminbereich (Checkbox, Quellnetz-Ausnahmen in CIDR), Adressraum und Aufruf, was der Proxy umschreibt (Referenz), Hinweisseite, Betriebsparameter `NAV_PROXY_*`, Grenzen, Fehlersuche, Tests.
- `docs/orvanta.md` – Mail- und Kalender-App Orvanta (Exchange On-Premise via EWS): Funktionsumfang, Oberfläche, Architektur/Code-Landkarte, Datenbank, Routen, Exchange-Anbindung (Impersonation, Anmeldeverfahren, Admin-Einstellungen), Anhänge/Zwischenspeicher/Nextcloud, Terminerinnerungen, KI-Unterstützung beim Schreiben (Kontextmenü, hellblaue Blöcke, Nutzungsbericht), Langzeitarchiv (Abschnitt 7a), Demo-Modus, Tests, Sicherheit. **Vor Änderungen an Orvanta lesen.**
- `docs/orvanta-nachrichtenfluss.md` – Konzept und Umsetzungsplan des Nachrichtenfluss-Dashboards (`/admin/office/orvanta/nachrichtenfluss`): Elemente und Datenquellen, Zustände und Ausgrauregeln, Wolkendarstellung, Verlaufsgrafik 365/180/90/30/14 Tage, Kennzahlen, Einstellungen, Datenmodell (Migrationen 048/049), Topologie-Ansicht mit Kennzahlen an den Knoten und lokalem VM-Speicher-Tier, Testmatrix, Betriebsgrenzen. **Vor Änderungen am Dashboard lesen.**
- `docs/mail-proxy.md` – SMTP-/IMAP-Proxy für Orvanta-Benutzer ohne Exchange-Postfach: Einrichtung (Mailserver je Identitätsquelle, Postfächer, Zuordnung), Entscheidungsregeln Exchange/Proxy/gesperrt, Funktionsumfang, Sicherheit (Verschlüsselung, HMAC, SSRF), Cache/Invalidierung, Variablen `MAIL_PROXY_*`, Diagnose, technische Referenz (Code-Landkarte, Routen, Proxy-Protokoll, Invarianten, Tests).
- `docs/mail-proxy-referenz.md` – Technische Referenz des SMTP-/IMAP-Proxys für Entwickler und Coding-Agenten: Code-Landkarte (PHP und Container), Laufzeitarchitektur, Datenmodell, Wertobjekte/Cache/Kennungsformate (`mpx.`), Abläufe (Routing, Lesen, Senden, Kennwortübernahme, Admin-Overlays, Invalidierung), Proxy-Protokoll und Operationen, Fehlercodes, Sicherheit, Konfiguration, Invarianten, Tests, Änderungsrezepte, Fehlersuche.
- `docs/storage.md` – Speicher-Tiering und HA-Synchronisation: Hot-Tier (lokales Storage), Cold-Tier (SMB-/S3-Tier; S3-Buckets per s3fs/FUSE; Erweiterung aller Cold-Tiers um weitere Ziele gleicher Art), Container `storage-sync`, Rückholung in Nextcloud, Schutz vor Ransomware/Vorfälle, Snapshot-Speicher (Dateiversionen auf eigener SMB-Freigabe, Wiederherstellung, Beispielarchitektur mit Grafik), SNMP, Wiederherstellung, Vorteile (auch von S3-Zielen).
- `docs/storage-stack.md` – Storage-Stack für Administration und Fehlersuche: alle beteiligten Container und ihre Rollen, Netze, Volumes, Datenflüsse (Mermaid-Diagramme), Katalog-Datenbank `storage-sync-catalog` (Schema, Leistung, `my.cnf`), Redis-Helfer `storage-sync-redis` (Sperren, Zähler, Betrieb ohne Redis), Startreihenfolge, Ausfallverhalten, Betriebsbefehle, Symptomtabelle, Umstieg vom SQLite-Katalog, Sicherung.
- `docs/notfallplan-editor-referenz.md` – technische Referenz des Notfallplan-Editors für Entwickler und Coding-Agenten: Code-Landkarte, Routen/Rechte, JSON-Format `definition`, Oberfläche und Diagramm-Layout, Validierung, Speichern/Konflikte, Vier-Augen-Zustandsautomat, Live-Vorschau, Laufzeitsemantik, Invarianten, Tests, Änderungsrezepte. **Vor Änderungen am Notfallplan-Editor lesen.**
- `docs/orvanta-referenz.md` – technische Referenz der Mail- und Kalender-App Orvanta für Entwickler und Coding-Agenten: Code-Landkarte, Routen/Zugriffsprüfung/API-Rahmen, Datenhaltung, EWS-Aufrufkette und Operationen, API-Verträge und Grenzwerte, Anhang-Tokens/Viewer/Zwischenspeicher, Erinnerungs-Zustandsautomat, Frontend, HTML-Bereinigung/CSP, Invarianten, Tests, Änderungsrezepte, bekannte Eigenheiten, KI-Unterstützung (Ablauf, Verträge, Datenschutz, Testendpunkt), Langzeitarchiv (Abschnitt 17: Archivformat, Datenmodell/Zustandsautomat, Commit-Protokoll/Recovery, Worker, Suche/API/Sicherheit, Tests), Exchange-DAG (Abschnitt 20: Lastverteilung, Failover, Dashboard, Tooltipp). **Vor Änderungen an Orvanta lesen.**
- `docs/storage-referenz.md` – technische Referenz Speicher-Tiering/HA für Entwickler und Coding-Agenten: Code-Landkarte, Prozesse und Takte, Datenformate (MySQL, Katalog in `storage-sync-catalog`, Dateischnittstelle zu Nextcloud), Algorithmen (HA-Status, Auslagerung, Rückholung, Vorfälle, Snapshot-Lebenszyklus), Invarianten, Tests, Änderungsrezepte, Fehlersuche. **Vor Änderungen am Speichermodul lesen.**
