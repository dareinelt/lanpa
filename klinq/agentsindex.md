# Agentsindex – Überblick über das Projekt „KLINQ“

Dieser Index richtet sich an KI-Agenten und neue Entwickler. Er beschreibt kompakt, **was** das Projekt tut,
**wie** es aufgebaut ist und **wo** welche Datei liegt, damit Änderungen zielsicher und ohne Suchaufwand
vorgenommen werden können. Ausführliche Fachdokumentation liegt in [`docs/`](docs/) – die Verweise sind jeweils
am Ende der Abschnitte verlinkt.

---

## 1. Zweck der Anwendung

KLINQ ist eine multifunktionale Web-Anwendung für die IT und bündelt mehrere Module unter einem Dach –
aktuell **Assetverwaltung** und **Help Desk**, weitere Funktionen folgen.

Modul **Assetverwaltung** – Inventar- und Assetverwaltung für IT-Hardware (PCs, Mobilgeräte,
Netzwerkkomponenten, Zubehör) mit:

- Assetstamm inkl. automatisch vergebener Inventarnummern, Historie und optimistischer Sperre
- Entnahme/Retoure („Bewegungen“) am Desktop und mobil per QR-/Barcode-Scan, auch offline (PWA); am
  Desktop optional mit elektronischer Signatur (Passwort-Bestätigung, je Benutzer freizuschalten)
- Übergabeprotokolle je Mitarbeiter mit digitaler Unterschrift, Vorlagen-Baukasten und PDF-Archiv
- Einkauf: Bestellungen, Vorlagen, Bedarfsmeldungen, Nachbestellung von Verbrauchsmaterial, Wareneingang
- Lizenzverwaltung mit Zuordnung zu Assets, Dokumentenablage außerhalb des Webroots
- QR-Etikettendruck, Berichte/CSV-Export, CSV-Import von Altbeständen
- Active-Directory-Synchronisation der Mitarbeiter und optionale LDAP-Anmeldung, Windows-SSO über das Intranet LANPA (auth-Container, signiertes Einstiegsticket)
- Rollenbasierte Rechte plus zusätzliche Berechtigungsgruppen (`config/permissions.php`), lückenloses
  Audit-Log, Benutzerverwaltung

Modul **Help Desk** (per `HELPDESK_ENABLED` abschaltbar) – IT-Ticketsystem mit:

- Ticket-Lebenszyklus inkl. Status-Workflow, SLA-Fristen/Eskalation, Zusammenführen auf ein bestehendes Ticket
  (Overlay mit Ticket-Suche, Zusammenführungsgrund und optionaler Info-E-Mail an den Melder), Verknüpfungen,
  Watcher, Arbeitszeiterfassung und Asset-Verknüpfung je Ticket
- Ticketregeln (automatische Zuweisung/Kategorisierung), Vorlagen, Tags, Kategorien/Gruppen
- Zwei Eingabemodi für neue Agenten-Tickets: klassisches Formular und achtstufiger Assistent
  (`/helpdesk/tickets/new?modus=assistent`, gemeinsame Felder in `_fields.php`)
- Benutzerportal (`/portal`) für eigene Tickets sowie ein anonymes Störungsmeldeformular (`/stoerung`)
  ohne Anmeldung (Melder/Rechner/AD-Daten werden serverseitig ermittelt)
- E-Mail-Eingang per IMAP (neues Ticket oder Kommentar per Threading) und E-Mail-Benachrichtigungen
- Wissensdatenbank mit Verknüpfung zu Tickets
- Ticket-Berichte/CSV-Export (Recht nur über die Berechtigungsgruppe „Statistik“ oder Admin-Rolle)
- Ticket löschen und stornieren als getrennte Rechte (nur über die Berechtigungsgruppen „Darf Tickets löschen“
  bzw. „Darf Tickets stornieren“ oder Admin-Rolle)
- Eigene Scheduler-Jobs für Eskalation/Auto-Close, Pausierungs-Ende und Mailabholung (`bin/helpdesk.php`)
- Pausengründe sind Status des Statuskatalogs mit Kategorie „Wartend“ und werden in der Help-Desk-Administration
  unter **Status** gepflegt (kein eigener Katalog)

## 2. Technologie und Rahmenbedingungen

| Thema | Festlegung |
|---|---|
| Sprache/Laufzeit | PHP 8.4 (`declare(strict_types=1)` in allen Dateien), Apache |
| Datenbank | MySQL 8.4, InnoDB, `utf8mb4_unicode_ci`, Zugriff ausschließlich über PDO-Prepared-Statements |
| Frontend | Vanilla JS + HTML + CSS, **kein** Build-Schritt, **keine** CDNs, **keine** npm/Composer-Abhängigkeiten |
| Framework | keines – eigener Mini-Kern (Container, Router, Middleware, View) |
| Nebendienste | Python-Container `pdf` (WeasyPrint, HTML→PDF) und `mail` (SMTP-Relay) |
| Betrieb | Docker Compose (`app`, `mysql`, `pdf`, `mail`), Migrationen laufen beim Start |
| Sprache der Inhalte | Code-Kommentare, Doku und UI sind **deutsch** – neue Beiträge ebenso |

> **Wichtig für Agenten:** Es gibt keinen Paketmanager im Produktionspfad. Neue Laufzeit-Abhängigkeiten
> (Composer/npm/CDN) sind nicht erwünscht; benötigte Funktionalität wird mit PHP-Bordmitteln in `app/Support/`
> ergänzt (z. B. `QrCode`, `CsvReader`, `ColognePhonetic`).

## 3. Verzeichniskarte

| Pfad | Inhalt |
|---|---|
| `public/` | Einziger per Web erreichbarer Ordner: `index.php`, `css/`, `js/`, PWA (`sw.js`, `manifest.webmanifest`, `offline.html`), Icons |
| `bootstrap/autoload.php` | Minimaler PSR-4-Autoloader (`App\` → `app/`, `Tests\` → `tests/`) |
| `app/Core/` | `Application`, `ApplicationFactory`, `Container`, `Config`, `Env`, `Database`, `Router`/`Route`, `Request`/`Response`, `View`, `SessionManager`, `Logger`, `Providers/` |
| `app/Middleware/` | `SecurityHeadersMiddleware`, `RequestLogMiddleware`, `CsrfMiddleware`, `AuthorizationMiddleware` (in dieser Reihenfolge) |
| `app/Security/` | `CurrentUser`, `Permissions`, `PasswordHasher` (Argon2id), `CsrfTokenManager`, `HtmlEscaper` |
| `app/Controllers/` | HTTP-Schicht; `CrudController`/`BaseController` als Basis, `Mobile/` (Scan-UI), `Api/` (JSON), `Helpdesk/` (Agent, Portal, API, Wissensdatenbank, Admin, Berichte, Störungsmeldung, Anhänge) |
| `app/Services/` | Geschäftslogik und Validierung je Fachbereich; `Ad/` (Sync), `Ldap/` (Client, Fake-Client, Auth), `Lanpa/` (Brücke zum Intranet: Einstiegsticket, Dienst-Token, KI-Konfiguration), `Helpdesk/` (Ticket-, SLA-, Regel-, Mail-, Wissensdatenbank-Logik, `Helpdesk/Mail/` für IMAP/Datei-Postfachclients) |
| `app/Repositories/` | Datenzugriff; `BaseRepository` kapselt `fetchOne/fetchAll/insertRow/updateRow` |
| `app/Support/` | Fachlogikfreie Helfer: `Validator`, `Paginator`, `CsvReader`/`CsvWriter`, `QrCode`, `ColognePhonetic`, `Url`, `ModuleNavigation`, `IpRange`, `Secret` (Verschlüsselung gespeicherter Zugangsdaten) |
| `app/Exceptions/` | `HttpException`, `NotFoundException`, `ForbiddenException`, `ValidationException`, `ConflictException` |
| `routes/web.php`, `routes/modules/*.php` | Routen je Modul (u. a. `helpdesk.php` für Agentenbereich/Portal/API), **jede Route trägt ihre Berechtigung** |
| `resources/views/` | PHP-Templates je Modul (u. a. `helpdesk/`), `partials/` (Layouts, Helfer, Filterleiste, Pagination, Flash, Icons) |
| `config/` | `app.php`, `database.php`, `ldap.php`, `lanpa.php`, `mail.php`, `uploads.php`, `permissions.php` (Rollen **und** zusätzlich zuweisbare Berechtigungsgruppen) – gespeist aus Umgebungsvariablen |
| `database/migrations/` | Nummerierte SQL-Migrationen (001–019), genau einmal angewendet über `schema_migrations`; u. a. Help-Desk-Kernschema (008), Mail-Eingang (009), Störungsmeldung (010), Berechtigungsgruppen (011), Mail-Postfacheinstellungen (012), elektronische Signatur bei Bewegungen (013) und deren Opt-in je Benutzer (014), Help-Desk-Pausierung mit Pausenende (019) |
| `database/seeders/`, `database/fixtures/` | Stammdaten (Rollen, Assettypen, Status, Help-Desk-Standardwerte, Berechtigungsgruppen) bzw. Fake-AD-Daten |
| `bin/` | CLI: `migrate.php`, `sync-ad.php`, `helpdesk.php` (Eskalation/Auto-Close/Pausierungs-Ende/Mailabholung), `install.sh` (Docker-freie Installation), `build-docs-pdf.py` (Handbuch-PDF) |
| `storage/` | `uploads/`, `logs/`, `labels/`, `tmp/`, `mail-inbox/` (Help-Desk-Dateipostfach für Tests) – außerhalb des Webroots, als Docker-Volumes eingebunden |
| `tests/` | Eigener Runner `tests/run.php`, `Unit/`, `Integration/`, `Support/` |
| `docker/` | `php/` (Dockerfile, vHost, php.ini, Entrypoint, Scheduler), `pdf/`, `mail/`, `mysql/init/` |
| `docs/` | Fachdokumentation (Markdown) + `handbuch.pdf` + `screenshots/` |

## 4. Request-Lebenszyklus

```
Browser → Apache → public/index.php → ApplicationFactory::create()->run()
        → Router (Pfad + Methode, {id} → ([^/]+))
        → SecurityHeaders → RequestLog → Csrf → Authorization
        → Controller → Service → Repository → PDO → MySQL
                          └→ AuditLogService
        → View (PHP-Template, CSP-konform) oder Response::json()
```

1. `ApplicationFactory` lädt `.env`/Umgebung in `Config`, setzt Zeitzone und Fehlerbehandlung, baut den
   `Container` (Singletons mit **expliziten** Fabriken, kein Reflection-Autowiring) und lädt automatisch alle
   `app/Core/Providers/*.php` sowie `routes/modules/*.php`.
2. Middleware: CSP ohne `unsafe-inline`, `X-Frame-Options: DENY`, `nosniff`; Logging ab Status 400 (außer 404);
   CSRF-Prüfung bei POST/PUT/PATCH/DELETE (`_csrf` oder `X-CSRF-Token`, `hash_equals`); Authorization lädt den
   Kontostatus je Anfrage (Deaktivierung und Rollenwechsel wirken sofort).
3. Controller lesen Eingaben und delegieren; **Services** whitelisten alle Felder über `Validator`
   (Schutz vor Mass Assignment), setzen Geschäftsregeln durch und protokollieren über `AuditLogService`.
4. `Application` behandelt Ausnahmen zentral: `ValidationException` → 422 mit Feldfehlern,
   `ConflictException` → 409, `HttpException` → passender Status, sonst 500 mit Logeintrag
   (Details im Browser nur bei `APP_DEBUG=true`). Bei `Accept: application/json` kommen JSON-Fehler zurück.

Detail: [`docs/architektur.md`](docs/architektur.md)

## 5. Module im Überblick

Jedes Modul folgt demselben Schnitt: **Routendatei → Controller → Service → Repository → Views**
(plus Provider für die Container-Registrierung).

| Modul | Routen | Controller | Service(s) | Views | Doku |
|---|---|---|---|---|---|
| Dashboard/Suche | `routes/modules/assets.php` (`/search`, `/api/search`), `web.php` | `DashboardController`, `SearchController` | `DashboardService`, `SearchService` | `dashboard/`, `search/` | [berichte](docs/berichte.md) |
| Assets | `routes/modules/assets.php` | `AssetController` | `AssetService`, `InventoryNumberService` | `assets/` | [assets](docs/assets.md) |
| Bewegungen (Entnahme/Retoure) | `routes/modules/movements.php` | `MovementController`, `Mobile/MobileController`, `DocumentController` | `MovementService`, `MovementReceiptRenderer`, `DocumentService` | `movements/`, `mobile/` | [movements](docs/movements.md) |
| Übergabeprotokoll | `routes/modules/handover.php` | `HandoverController` | `HandoverService`, `HandoverRenderer`, `PdfClient`, `MailClient` | `handover/`, `mobile/handover_*` | [uebergabeprotokoll](docs/uebergabeprotokoll.md) |
| Einkauf | `routes/modules/orders.php` | `PurchaseOrderController`, `ProcurementController` | `PurchaseOrderService`, `SupplierService`, `ArticleService` | `orders/` | [einkauf](docs/einkauf.md) |
| Lizenzen | `routes/modules/licenses.php` | `LicenseController` | `LicenseService` | `licenses/` | [lizenzen](docs/lizenzen.md) |
| Etiketten | `routes/modules/labels.php` | `LabelController` | `LabelService`, `Support\QrCode` | `labels/`, `partials/label.php` | [labels](docs/labels.md) |
| Stammdaten | `routes/modules/masterdata.php` | `LocationController`, `CostCenterController`, `ManufacturerController`, `ArticleController`, `SupplierController`, `EmployeeController` (alle über `CrudController`) | `LocationService`, `CostCenterService`, `ManufacturerService`, `ArticleService`, `SupplierService`, `EmployeeService` | `locations/`, `cost_centers/`, `manufacturers/`, `articles/`, `suppliers/`, `employees/` | [datenmodell](docs/datenmodell.md) |
| AD-Sync | `routes/modules/ad.php` | `AdSyncController` | `Ad\EmployeeSyncService`, `Ad\AdUserMapper`, `Ldap\*` | `admin/` | [ad-sync](docs/ad-sync.md) |
| Windows-SSO über LANPA | `routes/modules/sso.php` | `LanpaSsoController` | `Security\LanpaSsoToken`, `Services\Lanpa\LanpaIdentityService`, `Services\Lanpa\LanpaBridge` | `sso/` | [windows-sso](docs/windows-sso.md) |
| Offline/PWA | `routes/modules/offline.php` | `Api\OfflineController` | `OfflineSyncService` | `public/js/offline*.js`, `public/sw.js` | [offline](docs/offline.md) |
| Berichte | `routes/modules/reports.php` | `ReportController` | `ReportService`, `Support\CsvWriter` | `reports/` | [berichte](docs/berichte.md) |
| Import | `routes/modules/imports.php` | `ImportController` | `ImportService`, `Support\CsvReader` | `imports/` | [import](docs/import.md) |
| Benutzer/Profil | `routes/modules/users.php` | `UserController`, `ProfileController`, `AuthController` | `UserService`, `AuthService` | `users/`, `profile/`, `auth/` | [audit](docs/audit.md) |
| Audit & Admin | `routes/modules/audit.php`, `admin.php` | `AuditController`, `AdminController`, `HelpdeskMailboxController`, `HelpdeskOutboundMailController` | `AuditLogService`, `SettingsService`, `Helpdesk\MailboxSettingsService`, `Helpdesk\OutboundMailSettingsService`, `Support\Secret` | `audit/`, `admin/` | [audit](docs/audit.md), [helpdesk](docs/helpdesk.md) |
| Help Desk (Agent/Portal/API) | `routes/modules/helpdesk.php` | `Helpdesk\{HelpdeskDashboardController, TicketController, TicketAttachmentController, KnowledgeBaseController, HelpdeskReportController, HelpdeskAdminController, HelpdeskApiController, PortalController, QuickReportController, HelpdeskSupportShiftController}` | `Helpdesk\{TicketService, TicketWorkflowService, TicketSlaService, TicketRuleService, TicketRuleEvaluator, TicketMergeService, TicketNumberService, TicketNotificationService, TicketMailService, TicketPriorityMatrix, TicketReportService, TicketMailIngestionService, HelpdeskAdminService, HelpdeskSchedulerService, KnowledgeBaseService, MailboxSettingsService, ReporterIdentityService, SharedAccountSuggestionService, SupportShiftService}`, `Helpdesk\Mail\{ImapMailboxClient, FileMailboxClient,    MailboxClientFactory, MimeMessageParser}`, `PersonDirectoryService` (Personenauswahl für Melder/Betroffene aus dem Intranet-Telefonbuch, `/api/helpdesk/people/search` und `…/adopt`), `Support\HtmlSanitizer` (Bereinigung des Mail-Editors) | `helpdesk/` | [helpdesk](docs/helpdesk.md) |

### Fachliche Kernregeln (häufige Fehlerquellen)

- **Inventarnummern**: Format `PRÄFIX + YY + NNN` (z. B. `PC24001`), Vergabe in einer Transaktion über
  `inventory_sequences` mit `SELECT … FOR UPDATE`; manuell vergebene höhere Nummern ziehen die Sequenz nach.
- **Optimistische Sperre**: `assets.version` wird im Formular mitgeschickt; Konflikt → 409 statt stillem Überschreiben.
- **Bewegungen dürfen unvollständig sein** („offen“) und werden am Desktop nachbearbeitet; jede Bewegung
  aktualisiert das Asset sofort und schreibt in `asset_history`.
- **Offline-Sync ist idempotent**: `client_transaction_id` (UUID) in `sync_transactions`/`movements`;
  Wiederholungen liefern das gespeicherte Ergebnis statt doppelter Buchungen.
- **Wareneingang**: je gelieferter Einheit entsteht ein Asset – außer bei Verbrauchsartikeln
  (`articles.is_consumable`): diese werden nur als Lagerbestand (`stock_quantity`) geführt und erzeugen
  **niemals** Assets oder Inventarnummern (`PurchaseOrderService::…` Wareneingang).
- **Übergabeprotokolle** sind je Mitarbeiter versioniert: `draft → signed → superseded/cancelled`; das zuletzt
  unterschriebene Protokoll gilt, ältere bleiben als PDF archiviert.
- **AD-Sync löscht nie**: fehlende/deaktivierte Konten werden inaktiv gesetzt; Identifikation nur über `objectGUID`.
- **PDF- und E-Mail-Versand sind optional und rein informativ** – Fehler dürfen den Fachworkflow nie abbrechen.
- **Elektronische Signatur bei Bewegungen**: Am Desktop (nicht mobil/Scan) kann ein Benutzer eine Entnahme/
  Retoure durch erneute Eingabe des eigenen Passworts signieren (`movements.signed_electronically/signed_at`).
  Nutzbar nur, wenn `users.can_sign_electronically` für den Benutzer gesetzt ist.
- **Zuletzt geöffnetes Modul je Benutzer**: `users.last_module` (`assets`/`helpdesk`) pflegt die
  `AuthorizationMiddleware` über `ModuleNavigation::moduleForPath()` (nur bei einem Wechsel); `/`, die lokale
  Anmeldung und der Intranet-Einstieg ohne ausdrückliches Ziel nutzen `ModuleNavigation::startUrl()`; das ist
  `entryUrl() ?? home(ASSETS)`, also das zuletzt verwendete Modul nur bei vorhandenem Recht, sonst der
  Help-Desk-Einstieg (`/helpdesk/portal` für die Rolle `portal`) und erst danach `/dashboard`. Profil, globale
  Suche, JSON-Endpunkte und der Systembereich ändern die Auswahl nicht.
- **Help Desk ist per `HELPDESK_ENABLED` abschaltbar**, ohne Routen zu entfernen – die Berechtigungsprüfung
  bleibt aktiv, nur Navigation/Dashboard/Scheduler werden ausgeblendet.
- **Eingabemodus für neue Tickets je Benutzer**: `users.ticket_form_mode` (`klassisch`/`assistent`) merkt den
  zuletzt verwendeten Modus serverseitig (unabhängig vom Client). `TicketController::resolveFormMode()` liest
  ihn über `UserRepository::ticketFormMode()`, `rememberFormMode()`/`rememberTicketFormMode()` schreiben ihn nur
  bei Änderung.
- **Bilder in Beschreibung und Kommentaren** liegen als base64-Data-URI im Text (Markdown `![Bild](data:…)`,
  eingefügt per `STRG+V` durch `public/js/helpdesk-media.js`; im Feld als Marke `[Bild n]` mit Vorschau,
  beim Absenden aufgelöst), gerendert über `App\Support\RichText`;
  Dateianhänge laufen weiter über `DocumentService` (`documents`, `entity_type = ticket`).
- **Ticketnummern** folgen `HELPDESK_TICKET_PREFIX-JJJJ-NNNNNN`, vergeben über `ticket_sequences` analog zu
  den Inventarnummern (`TicketNumberService`).
- **SLA/Eskalation**: Fristen aus `ticket_slas` (je Kategorie/Priorität), Warn-/Eskalationsschwellen aus
  `HELPDESK_SLA_WARNING_PERCENT`/`HELPDESK_ESCALATION_PERCENT`; `HelpdeskSchedulerService` prüft periodisch
  Eskalation, Auto-Close (`HELPDESK_AUTO_CLOSE_DAYS`) und ruft die Mailabholung.
- **Mail-Ingestion ist idempotent**: eingehende Nachrichten werden über `ticket_inbound_mails` (Message-ID)
  verfolgt; Antworten werden per Ticketnummer/`In-Reply-To` dem richtigen Ticket als Kommentar zugeordnet.
- **Agenten-E-Mail aus dem Ticket** (`TicketMailService`): Melder immer, betroffene Person optional; jede Person
  bekommt eine eigene Nachricht (`MailClient::send()` kann nur einen Empfänger) mit gemeinsamer `Message-ID`.
  Threading-Kopfzeilen wie bei Benachrichtigungen, aber **kein** `Auto-Submitted` und **kein**
  `TicketNotificationService::wrap()`. Die Orvanta-Signatur kommt über `LanpaBridge::signature()` aus dem Intranet
  und wird **serverseitig** angehängt (nie aus dem Browser übernommen); der HTML-Text läuft vorher durch
  `Support\HtmlSanitizer`. Ergebnis ist ein öffentlicher Kommentar (`source = email`) plus optional eine
  Pausierung über `TicketService::changeStatus(..., notify: false)`. Ein in den Help-Desk-Einstellungen
  gepflegter **Präfix-Text** (`ReplyTemplateSettingsService`, Einstellung `helpdesk.reply_template.prefix_text`,
  Startwert `HELPDESK_REPLY_TEMPLATE_PREFIX`) wird erst beim Senden in `renderBody()` vorangestellt und steht
  nicht im Mail-Dialog; für die Zusammenführungs-Info gilt er nicht.
- **Störungsmeldeformular** (`/stoerung`) ist ohne Anmeldung erreichbar; Melder/Rechnername/AD-Daten werden
  serverseitig ermittelt, CSRF-Schutz und optionale Netz-/Ratenbegrenzung (`HELPDESK_QUICK_REPORT_*`) greifen
  im Controller, nie im Client. Das optionale Feld „Betroffener Mitarbeiter“ nutzt eigene öffentliche
  Endpunkte (`/stoerung/benutzer`, `/stoerung/benutzer/uebernehmen`), weil die Help-Desk-API `helpdesk.view`
  verlangt; die Übernahme aus dem Telefonbuch ist dort zusätzlich auf 20 Vorgänge je Sitzung und Stunde begrenzt.
  Für **Sammelkonten** (Katalog in den Help-Desk-Einstellungen, `QuickReportSettingsService`, Pflege über eine
  Eingabezeile mit Vorschlägen aus `SharedAccountSuggestionService`) ist die Angabe
  eines zusätzlichen Benutzers Pflicht; der Erklärungstext ist dort anpassbar, das Feld wird automatisch
  aufgeklappt, und `QuickReportController::store()` prüft die Pflicht serverseitig.
- **Eingangsbestätigung**: `TicketConfirmationService` versendet nach `TicketService::create()` den unter
  `helpdesk.reply_template.confirmation_text` gepflegten Text (Startwert `HELPDESK_REPLY_TEMPLATE_CONFIRMATION`,
  Platzhalter `{anrede}`/`{name}`/`{ticketnummer}`/`{betreff}`/`{datum}`/`{link}`/`{firma}`) an Melder **und**
  zusätzlichen Benutzer – immer bei Quelle `form`/`email`, bei Agentenanlage nur mit Opt-in im neunten
  Assistenten-Schritt. Versand je Empfänger über `TicketNotificationService::sendTo()` (Ereignis
  `created_confirmation`), damit Protokollierung und Idempotenz greifen; die allgemeine Anlage-Info an den
  Melder entfällt dann.
- **Hinweis am Fuß** jeder Benachrichtigungsmail: `TicketNotificationService::wrap()` hängt den unter
  `helpdesk.reply_template.footer_text` gepflegten Text an (Startwert `HELPDESK_REPLY_TEMPLATE_FOOTER`,
  Platzhalter `{firma}`, Standardtext `ReplyTemplateSettingsService::DEFAULT_FOOTER_TEXT`, leer = kein
  Hinweis). Die von Agenten verfasste E-Mail (`TicketMailService`) nutzt den Rahmen nicht.
- **Gespeicherte Zugangsdaten** (z. B. Help-Desk-Postfachpasswort) werden über `Support\Secret` mit `APP_KEY`
  verschlüsselt abgelegt, nie im Klartext.

## 6. Berechtigungen

Rechte folgen der Konvention `<bereich>.<aktion>` und stehen samt Rollenzuordnung in
[`config/permissions.php`](config/permissions.php). Rollen: **admin**, **assetmanagement**, **lager**,
**einkauf**, **helpdesk_admin**, **helpdesk_lead**, **helpdesk_agent**, **readonly** (alle `*.view` außer
`audit.view` und `helpdesk.view`, fremde Tickets sehen nur Help-Desk-Rollen) und **portal** (nur
`portal.view`, `portal.create`, `knowledgebase.view` – kein Zugriff auf Assetverwaltung, Dashboard oder
Suche). `portal` ist die Standard- und Ersatzrolle für Benutzer ohne KLINQ-Berechtigung.

Zusätzlich zur Rolle können einem Benutzer **Berechtigungsgruppen** zugewiesen werden (`permission_groups`,
`user_permission_groups`), die einzelne Rechte unabhängig von der Rolle freischalten: die Gruppe
„Statistik“ für `helpdesk.reports` (Ticket-Berichte), die Gruppe „Darf Ticketaufruf auswerten“ für
`helpdesk.view_history` (macht die Ereignisse `viewed` in der Ticket-Historie sichtbar, die beim Aufruf
eines Tickets durch einen Agenten geschrieben werden), die Gruppe „Darf Tickets löschen“ für
`helpdesk.delete` und die Gruppe „Darf Tickets stornieren“ für `helpdesk.cancel`. Löschen und Stornieren
sind getrennte Rechte und standardmäßig keiner Help-Desk-Rolle zugeordnet; `helpdesk.close` deckt nur
Lösen und Schließen ab.

Prüfebenen:

1. `AuthorizationMiddleware` erzwingt Anmeldung und das an der Route hinterlegte Recht (Rolle **oder**
   zusätzliche Berechtigungsgruppe).
2. Services prüfen sicherheitsrelevante Vorgänge erneut über `CurrentUser::require` (z. B. Ausmusterung,
   Storno, Offline-Sync je Vorgang, Ticket-Löschung).
3. Die Oberfläche blendet nur aus, was serverseitig ohnehin verweigert wird.

`RouteInventoryTest` stellt sicher, dass keine Route ohne Berechtigung existiert; `PermissionsTest` sichert die
Konsistenz der Rollenmatrix. **Neue Route ⇒ neues/bestehendes Recht zwingend angeben.**

## 7. Datenmodell (Kurzfassung)

Alle Zeitstempel liegen in **UTC** (DB-Sitzung auf `+00:00`), reine Datumsfelder sind zeitzonenfrei; Anzeige in
`APP_TIMEZONE`.

- **Kern**: `assets`, `asset_history`, `movements`, `asset_types`, `asset_categories`, `asset_statuses`,
  `inventory_sequences`
- **Stammdaten**: `locations` (hierarchisch, Zyklusschutz), `cost_centers`, `employees`, `manufacturers`,
  `articles`, `suppliers`
- **Einkauf**: `purchase_orders`, `purchase_order_items`, `goods_receipts`, `goods_receipt_items`,
  `purchase_order_templates(_items)`, `purchase_requests(_items)`
- **Lizenzen/Dokumente**: `licenses`, `license_assignments`, `documents`
- **Übergabe**: `handover_templates`, `handover_protocols`
- **Import/Sync**: `import_runs`, `import_rows`, `sync_transactions`, `ad_sync_runs`
- **Betrieb**: `users`, `roles`, `permission_groups`, `user_permission_groups`, `audit_logs`,
  `system_settings`, `schema_migrations`
- **Help Desk**: `tickets`, `ticket_sequences`, `ticket_types`, `ticket_statuses`, `ticket_priorities`,
  `ticket_groups`, `ticket_group_members`, `ticket_categories`, `ticket_slas`, `ticket_tags`,
  `ticket_tag_relations`, `ticket_assets`, `ticket_comments`, `ticket_attachments`, `ticket_events`,
  `ticket_relations`, `ticket_watchers`, `ticket_worklogs`, `ticket_templates`, `ticket_rules`,
  `ticket_notifications`, `ticket_inbound_mails`, `knowledge_articles`, `knowledge_article_tags`,
  `knowledge_article_tickets`

ER-Diagramm und Feldbeschreibungen: [`docs/datenmodell.md`](docs/datenmodell.md)

## 8. Frontend

- Progressive Enhancement: Jedes Formular funktioniert ohne JavaScript; JS ergänzt Autocomplete
  (`search.js`, `picker.js`), Duplikatprüfung (`duplicate-check.js`), Scan (`scan.js`, `vendor/jsqr.js` lokal),
  Etikettenvorschau (`labels.js`), Unterschrift (`signature-pad.js`), Offline-Warteschlange
  (`offline.js`, `offline-ui.js`, IndexedDB + `sw.js`), Kontextmenü und KI-Dialog im Help Desk
  (`helpdesk-ai.js` für Textfelder, `helpdesk-mail.js` für den Mail-Editor im Ticket – gleiche Styles
  `.hd-ctx-menu`/`.hd-ai-dialog`, gemeinsame `window.AppUI`), Zusammenführen-Dialog (`helpdesk-merge.js`).
- CSS ist nach `base/`, `layout/`, `components/`, `pages/`, `utilities/` gegliedert und wird über
  `public/css/app.css` importiert; Design-Tokens als CSS-Variablen.
- **Keine Inline-Styles und keine Inline-Skripte** – die CSP verbietet `unsafe-inline`.
- Der Fenstertitel lautet immer `KLINQ - <Modul>` (z. B. `KLINQ - Help Desk`). Er wird zentral in
  `resources/views/partials/layout.php` aus `$appName` (`APP_NAME`) und `$moduleTitle` gebildet; die Layouts
  (`app_layout.php`, `mobile_layout.php`) setzen `$moduleTitle` aus `ModuleNavigation::label()`.

## 9. Konfiguration und Betrieb

- Konfiguration ausschließlich über Umgebungsvariablen (`.env`, Vorlage: `.env.example`) → `config/*.php`.
  Wichtige Gruppen: `APP_*` (inkl. `APP_KEY` zur Verschlüsselung gespeicherter Zugangsdaten), `DB_*`,
  `SESSION_*`, `ADMIN_*`, `PDF_SERVICE_*`, `MAIL_*`/`SMTP_*`, `MAX_UPLOAD_BYTES`/`ALLOWED_UPLOAD_EXTENSIONS`,
  `AD_*` (inkl. Attribut-Mapping und `AD_DRIVER=fake` für Tests), `LANPA_*` und `KLINQ_SSO_SECRET` (Intranet-Anbindung),
  `HELPDESK_*` (Modul-Schalter, SLA-Schwellen, Eskalation/Auto-Close, Mail-Eingang per IMAP,
  Störungsmeldeformular `/stoerung`).
- Start: `cp .env.example .env && docker compose up --build -d` → <http://localhost:8080>.
  Migrationen laufen im Entrypoint; `docker-compose.override.yml` bindet den Quellcode live ein (Entwicklung).
  Alternativ ohne Docker: `sudo bin/install.sh` (Debian/Ubuntu, inkl. SSL-Zertifikat).
- Scheduler im App-Container (`docker/php/scheduler.sh`) startet den AD-Sync
  (`AD_SYNC_INTERVAL_MINUTES > 0`) sowie die Help-Desk-Jobs für Eskalation/Auto-Close
  (`HELPDESK_ESCALATION_INTERVAL_MINUTES`) und Mailabholung (`HELPDESK_MAIL_INTERVAL_MINUTES`); alternativ
  per Cron auf dem Host: `php bin/sync-ad.php`, `php bin/helpdesk.php process`.
- Migrationen manuell: `php bin/migrate.php`.
- Handbuch-PDF neu bauen: `python3 bin/build-docs-pdf.py` (benötigt `weasyprint`, `markdown`).

Detail: [`docs/installation.md`](docs/installation.md), [`docs/backup.md`](docs/backup.md)

## 10. Tests

Eigener, abhängigkeitsfreier Runner:

```bash
php tests/run.php --unit                  # Unit-Tests (ohne Datenbank)
php tests/run.php --filter=RouterTest     # einzelner Test
docker compose exec app php tests/run.php # alle Tests inkl. Integration (Test-Datenbank)
```

- `tests/Unit/` – Router, Validator, Rechte-/Routeninventar, QR-Code, CSV, Argon2id, Inventarnummern,
  Standortbaum, Kölner Phonetik, Handover-Renderer …
- `tests/Integration/` – Services gegen die Test-Datenbank (`DB_TEST_DATABASE`, angelegt via
  `docker/mysql/init/01-test-database.sh`); Basisklasse `tests/Support/DatabaseTestCase.php`.
- Integrationstests werden übersprungen (nicht rot), wenn keine Test-Datenbank erreichbar ist.

## 11. Konventionen für Änderungen

1. **Schichten einhalten**: Controller (Eingabe/Antwort) → Service (Validierung, Regeln, Audit) →
   Repository (SQL). Kein SQL in Controllern/Services, keine Geschäftslogik in Repositories/Views.
2. **Neues Modul**: Repository + Service + Controller + Views + Provider in `app/Core/Providers/` +
   Routendatei in `routes/modules/` (beide werden automatisch geladen) + Rechte in `config/permissions.php`.
3. **Schemaänderung**: neue, fortlaufend nummerierte Datei in `database/migrations/` (bestehende Migrationen
   werden nie verändert) und Doku in `docs/datenmodell.md` nachziehen.
4. **Sicherheit**: Prepared Statements, Sortierspalten aus Whitelists, Ausgabe über `HtmlEscaper`,
   Uploads nur über `DocumentService` (außerhalb `public/`), Ausgabe von Dateien nur über Controller mit
   Rechteprüfung, CSRF-Token in allen schreibenden Formularen.
5. **Audit**: Jede fachliche Änderung wird über `AuditLogService` protokolliert; Passwörter und Geheimnisse
   niemals loggen.
6. **Doku**: Fachliche Änderungen in der passenden Datei unter `docs/` ergänzen; README-Links prüfen.
7. **Sprache und Stil**: deutsche Kommentare/UI, `declare(strict_types=1)`, keine neuen externen Abhängigkeiten.

## 12. Schnelleinstieg nach Aufgabentyp

| Aufgabe | Startpunkte |
|---|---|
| Neues Feld am Asset | `database/migrations/00X_*.sql` → `AssetRepository` → `AssetService` → `resources/views/assets/form.php`/`show.php` → `docs/assets.md` |
| Neue Route/Seite | `routes/modules/<modul>.php` (Recht!) → Controller-Methode → View → ggf. `config/permissions.php` |
| Neues Recht/Rolle | `config/permissions.php` → Routen ergänzen → `tests/Unit/PermissionsTest.php` prüfen |
| Bewegungs-/Scan-Logik | `MovementService`, `Mobile/MobileController`, `public/js/scan.js`, `docs/movements.md` |
| Offline-Verhalten | `OfflineSyncService`, `Api/OfflineController`, `public/js/offline.js`, `public/sw.js`, `docs/offline.md` |
| Bestellung/Wareneingang | `PurchaseOrderService`, `ProcurementController`, `resources/views/orders/`, `docs/einkauf.md` |
| Etikettenlayout | `LabelService`, `Support/QrCode`, `resources/views/partials/label.php`, `docs/labels.md` |
| Navigation/Modulwechsel | `Support/ModuleNavigation`, `resources/views/partials/app_layout.php`, `public/css/layout/shell.css` |
| PDF/E-Mail | `PdfClient`, `MailClient` (Versandkonto bzw. `mail`-Container), `docker/pdf/server.py`, `docker/mail/server.py` |
| AD-Anbindung | `Ad/EmployeeSyncService`, `Ad/AdUserMapper`, `Ldap/*`, `AD_*`-Variablen, `docs/ad-sync.md` |
| Windows-Benutzererkennung | `Security/LanpaSsoToken`, `Services/Lanpa/LanpaIdentityService`, `LANPA_*`-Variablen, `docs/windows-sso.md` |
| Neues Ticketfeld/-verhalten | `database/migrations/0XX_*.sql` → `TicketService`/`TicketWorkflowService` → `routes/modules/helpdesk.php` (Recht!) → `resources/views/helpdesk/` → `docs/helpdesk.md` |
| Help-Desk-Regeln/SLA/Eskalation | `Helpdesk/TicketRuleService`, `Helpdesk/TicketRuleEvaluator`, `Helpdesk/TicketSlaService`, `Helpdesk/HelpdeskSchedulerService`, `HELPDESK_*`-Variablen, `docs/helpdesk.md` |
| Help-Desk-Mail-Eingang | `Helpdesk/TicketMailIngestionService`, `Helpdesk/Mail/*`, `Helpdesk/MailboxSettingsService`, `HelpdeskMailboxController`, `HELPDESK_MAIL_*`/`HELPDESK_IMAP_*`-Variablen |
| Help-Desk-Mail-Versand | `MailClient` (Konto vor Container), `Helpdesk/OutboundMailSettingsService`, `Helpdesk/Mail/SmtpTransport`, `HelpdeskOutboundMailController`, `HELPDESK_SMTP_*`-Variablen (nur Startwerte) |
| Neue Berechtigungsgruppe | `config/permissions.php` (`groups`/`group_labels`) → Recht an Route/Service prüfen → `UserService` (Zuordnung) |

---

**Weiterführend:** [README](README.md) · [Architektur](docs/architektur.md) · [Datenmodell](docs/datenmodell.md) ·
[Installation & Betrieb](docs/installation.md) · [Audit & Benutzer](docs/audit.md)
