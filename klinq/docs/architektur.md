# Architektur

Die Anwendung ist eine klassische, serverseitig gerenderte PHP-Webanwendung ohne Framework und ohne externe Laufzeitabhängigkeiten. Bewusst einfache Bausteine – ein kleiner Container, ein Router mit Middleware-Kette, Controller → Service → Repository – halten den Code nachvollziehbar und ohne Composer wartbar.

```
Browser ──HTTP──▶ Apache ──▶ public/index.php ──▶ ApplicationFactory ──▶ Router
                                                        │                 │ Middleware: SecurityHeaders → RequestLog → Csrf → Authorization
                                                        │                 ▼
                                                        │              Controller ──▶ Service ──▶ Repository ──PDO──▶ MySQL 8.4
                                                        │                 │              │
                                                        │                 ▼              └─▶ AuditLogService
                                                        │               View (PHP-Templates, CSP-konform)
                                                        └── Container (Provider aus app/Core/Providers/*.php)
```

## Verzeichnisstruktur

| Pfad | Inhalt |
|---|---|
| `public/` | Webroot: `index.php`, CSS (`css/`), JavaScript (`js/`), PWA (`sw.js`, `manifest.webmanifest`, `offline.html`). Nur dieses Verzeichnis ist über Apache erreichbar. |
| `bootstrap/autoload.php` | Minimaler PSR‑4-Autoloader (`App\` → `app/`, `Tests\` → `tests/`) |
| `app/Core/` | Laufzeit: `Application`, `ApplicationFactory`, `Container`, `Config`, `Env`, `Database`, `Router`/`Route`, `Request`/`Response`, `View`, `SessionManager`, `Logger`; `Providers/` registriert modulweise Dienste |
| `app/Middleware/` | `SecurityHeadersMiddleware`, `RequestLogMiddleware`, `CsrfMiddleware`, `AuthorizationMiddleware` |
| `app/Security/` | `CurrentUser`, `Permissions`, `PasswordHasher` (Argon2id), `CsrfTokenManager`, `HtmlEscaper` |
| `app/Controllers/` | HTTP-Schicht: Eingaben lesen, Services aufrufen, Views rendern oder JSON antworten. `CrudController` als Basis für Stammdaten; `Mobile/` für die Scan-Oberfläche; `Api/` für JSON-Endpunkte; `Helpdesk/` für Ticketsystem, Portal und Wissensdatenbank |
| `app/Services/` | Geschäftslogik und Validierung (ein Service je Fachbereich), `Ad/` (AD-Sync), `Ldap/` (LDAP-Client, Fake-Client, LDAP-Authentifizierung), `Helpdesk/` (Ticket-Workflow, SLA, Regeln, Benachrichtigungen, Scheduler) |
| `app/Repositories/` | Datenzugriff mit PDO-Prepared-Statements; `BaseRepository` kapselt `fetchOne/fetchAll/insertRow/updateRow` |
| `app/Support/` | Hilfsklassen ohne Fachlogik: `Validator`, `Paginator`, `CsvReader`/`CsvWriter`, `QrCode`, `ColognePhonetic`, `Url` |
| `app/Exceptions/` | `HttpException` (404/403/409 …), `ValidationException`, `ConflictException` |
| `routes/web.php` + `routes/modules/*.php` | Routen je Modul mit **Pflicht-Berechtigung** pro Route |
| `resources/views/` | PHP-Templates; `partials/` (Layout, Helfer, Filterleiste, Pagination, Flash) |
| `config/` | Konfiguration aus Umgebungsvariablen (`app.php`, `database.php`, `ldap.php`, `helpdesk.php`, `permissions.php`) |
| `database/migrations/` | Nummerierte SQL-Migrationen; `database/seeders/001_defaults.sql` Stammdaten (Rollen, Assettypen, Status), `003_helpdesk_defaults.sql` Help-Desk-Stammdaten; `database/fixtures/` Fake-AD |
| `bin/` | CLI: `migrate.php`, `sync-ad.php`, `helpdesk.php` (`process`: SLA-Prüfung, Eskalation, Auto-Close; `mail`: E-Mail-Eingang per IMAP) |
| `storage/` | Uploads, Logs, temporäre Dateien (außerhalb des Webroots, Docker-Volumes) |
| `tests/` | Eigener schlanker Test-Runner (`tests/run.php`), Unit- und Integrationstests |
| `docker/` | Dockerfile (PHP 8.4 + Apache), php.ini, vHost, Entrypoint, Scheduler, MySQL-Init |

## Request-Verarbeitung

1. **`public/index.php`** lädt den Autoloader und ruft `ApplicationFactory::create()->run()` auf.
2. **`ApplicationFactory`** liest `.env`/Umgebung in `Config`, setzt Zeitzone und Fehlerbehandlung, baut den `Container` (alle Dienste als Singletons mit expliziten Fabriken – kein Reflection-Autowiring), lädt alle `app/Core/Providers/*.php` und `routes/modules/*.php`.
3. **`Router`** matcht Methode + Pfad (Parameter `{id}` → `([^/]+)`) und führt die Middleware-Kette aus:
   - `SecurityHeadersMiddleware` – CSP (`default-src 'self'`, kein `unsafe-inline`), `X-Frame-Options: DENY`, `nosniff`, Referrer- und Permissions-Policy; vom Controller gesetzte strengere CSP bleibt erhalten.
   - `RequestLogMiddleware` – protokolliert Antworten ≥ 400 (außer 404).
   - `CsrfMiddleware` – prüft bei POST/PUT/PATCH/DELETE das Token (`_csrf`-Feld oder `X-CSRF-Token`-Header) mit `hash_equals`; Fehler → 403.
   - `AuthorizationMiddleware` – erzwingt Anmeldung, lädt den Kontostatus je Anfrage (Deaktivierung wirkt sofort, Rollenwechsel ohne Neuanmeldung) und prüft die Routen-Berechtigung.
4. **Controller** validieren nur oberflächlich (IDs, Query-Parameter) und delegieren an **Services**, die mit `Validator` alle Eingaben whitelisten (Schutz vor Mass Assignment), Geschäftsregeln durchsetzen und Änderungen über `AuditLogService` protokollieren.
5. **Repositories** verwenden ausschließlich Prepared Statements; Sortierspalten kommen aus Whitelists, `LIMIT/OFFSET` sind Ganzzahlen.
6. **`Application`** fängt Ausnahmen zentral: `ValidationException` → 422 mit Feldfehlern, `ConflictException` → 409 (optimistische Sperre, Offline-Konflikte), `HttpException` → passender Status, alles andere → 500 mit Logeintrag (Details im Browser nur bei `APP_DEBUG=true`). JSON-Clients (`Accept: application/json`) erhalten JSON-Fehler.

## Berechtigungen

Rollen und Rechte stehen in `config/permissions.php` (`<bereich>.<aktion>`, z. B. `assets.manage`, `movements.checkout`, `orders.receive`). Acht Rollen:

| Rolle | Umfang |
|---|---|
| **admin** | Vollzugriff inkl. Benutzerverwaltung, Einstellungen, Audit-Log |
| **assetmanagement** | Assets, Etiketten, Entnahmen/Retouren, Mitarbeiter, Standorte, Kostenstellen, Hersteller/Artikel, Lizenzen, Import, Berichte, Audit-Log, Serviceportal |
| **lager** | Bestand und Assets, Entnahmen/Retouren, Wareneingang, Etiketten, Serviceportal |
| **einkauf** | Lieferanten, Bestellungen, Wareneingang, Hersteller/Artikel, Lizenzen, Dokumente, Berichte inkl. Export, Serviceportal |
| **helpdesk_agent / helpdesk_lead / helpdesk_admin** | Help-Desk-Agentenbereich in drei Stufen (Tickets bearbeiten → SLA/Kategorien/Vorlagen/Export → Administration); dazu alle Leserechte. Details: [Help Desk](helpdesk.md) |
| **readonly** | Alle `*.view`-Rechte außer `audit.view` und `helpdesk.view` |

Jede Route trägt ihre Berechtigung (Test `RouteInventoryTest` stellt sicher, dass keine Route ohne Recht existiert); zusätzlich prüfen Services sicherheitsrelevante Operationen erneut (`CurrentUser::require`), z. B. Ausmusterung, Storno oder Offline-Sync je Vorgang. Die Oberfläche blendet nur aus, was serverseitig ohnehin verweigert wird.

## Authentifizierung

- Lokale Konten mit **Argon2id**-Hash (`PasswordHasher`), Sperre nach 10 Fehlversuchen für 15 Minuten (`is_locked` wird in SQL/UTC berechnet), Rehash bei geänderten Parametern.
- Optional **LDAP/AD-Anmeldung** (`AD_AUTH_ENABLED`): Bind mit den Benutzerdaten über LDAPS, Anlage des Kontos beim ersten Login mit `AD_AUTH_DEFAULT_ROLE`; Passwörter von AD-Konten werden nie lokal gespeichert.
- Sitzungen: eigener Cookie-Name, `HttpOnly`, `SameSite`, `Secure` (automatisch bei HTTPS), `session_regenerate_id` bei Anmeldung und Passwortänderung, serverseitiger Ablauf (`SESSION_LIFETIME`).

## Frontend

Vanilla JavaScript ohne Build-Schritt. Progressive Enhancement: Alle Formulare funktionieren ohne JS; JS ergänzt Suche-Autocomplete, Duplikatprüfung, Picker, Barcode-/QR-Scan (`vendor/jsqr.js`, lokal eingebunden), Etikettenvorschau und die Offline-Warteschlange (`offline.js`, IndexedDB, Service Worker). CSS ist in `base/`, `layout/`, `components/`, `pages/`, `utilities/` gegliedert und wird über `app.css` importiert – Design-Tokens als CSS-Variablen, keine Inline-Styles (CSP).

## Nebenläufigkeit und Konsistenz

- **Inventarnummern** werden über `inventory_sequences` mit `SELECT … FOR UPDATE` in einer Transaktion vergeben (kein doppeltes Vergeben bei parallelen Anfragen); manuell vergebene höhere Nummern ziehen die Sequenz nach.
- **Optimistische Sperre** über `assets.version`: Formulare senden die gelesene Version; bei zwischenzeitlicher Änderung wird ein 409 mit Hinweis ausgelöst statt still zu überschreiben.
- **Offline-Sync** ist idempotent: Jede Client-Transaktion hat eine UUID (`sync_transactions`, `movements.client_transaction_id`), Wiederholungen liefern das gespeicherte Ergebnis.
- Mehrschrittige Schreibvorgänge (Wareneingang, Import, Bewegung + Historie + Audit) laufen in Datenbanktransaktionen.

## Erweiterbarkeit

Ein neues Modul besteht typischerweise aus Repository, Service, Controller, View(s), einem Provider in `app/Core/Providers/` und einer Routendatei in `routes/modules/` – beide werden automatisch geladen. Neue Rechte werden in `config/permissions.php` ergänzt und den Rollen zugeordnet; `PermissionsTest` und `RouteInventoryTest` sichern die Konsistenz.

Weiterführend: [Datenmodell](datenmodell.md) · [Installation & Betrieb](installation.md) · [Backup](backup.md) · [Audit-Log & Benutzer](audit.md)
