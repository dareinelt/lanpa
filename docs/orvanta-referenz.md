# Orvanta – technische Referenz (für Entwickler und Coding-Agenten)

Ergänzt [docs/orvanta.md](orvanta.md) (Funktionsumfang, Oberfläche,
Einrichtung, Admin-Einstellungen, Sicherheit aus Betreibersicht). Dieses
Dokument beschreibt, **wie** die Mail- und Kalender-App unter `/office/orvanta`
aufgebaut ist: Dateien, Zugriffsprüfung, JSON-API, EWS-Anbindung,
Datenhaltung, Anhänge und Zwischenspeicher, Terminerinnerungen, Frontend,
Invarianten, Tests und typische Änderungsaufgaben. Fundstellen sind als
`Datei` + Symbol angegeben – bei Abweichungen gilt der Code.

**Kurzfassung (TL;DR)**

- Orvanta ist ein **zustandsloser EWS-Client**: Mails, Termine, Kontakte,
  Aufgaben und Notizen liegen ausschließlich in Exchange. Lokal (MySQL) gibt es
  nur Einstellungen (`orvanta_settings`), Erinnerungszustände
  (`orvanta_reminders`) und den Bestand des Anhang-Zwischenspeichers
  (`orvanta_cache_items`).
- Jeder Exchange-Zugriff läuft über `OrvantaExchangeService::call()` →
  `EwsXml::envelope()` (SOAP mit `ExchangeImpersonation` des SSO-Benutzers) →
  `ExchangeTransportInterface::post()` (`CurlExchangeTransport` bzw.
  `DemoExchangeTransport` bei `exchange_host = demo` außerhalb der Produktion).
- Zugriff: `OrvantaController::authorize()` – SSO-Benutzer, Exchange aktiv,
  App-Freigabe `orvanta`, Office-Kachel zugänglich, Postfachadresse vorhanden.
  Jede API-Route ruft diese Prüfung über `OrvantaApiController::handle()` auf.
- API: `/api/orvanta/*`, JSON rein/raus, POST mit CSRF (`X-CSRF-Token` oder
  `_token`), Fehler immer als `{"error": "…"}` mit HTTP-Status.
- Anhänge werden über **signierte Kurzzeit-Tokens** (5 Min., JWT mit aus
  `SecretBox` abgeleitetem Schlüssel) geöffnet: Euro-Office-Viewer, Browser
  oder Download. Geöffnete Anhänge landen im Nextcloud-Bereich des Benutzers
  (Zwischenspeicher mit eigenem Quota, FIFO-Verdrängung).
- Erinnerungen: Server gleicht höchstens alle 5 Minuten die Termine der
  nächsten 48 h mit `orvanta_reminders` ab; jeder Poll liefert fällige
  Erinnerungen **genau einmal** (`due`, danach `delivered`) plus alle aktiven.
- Frontend: Vanilla-JS ohne Build (`public/assets/js/orvanta.js`), strikte CSP
  (keine Inline-Styles) – HTML-Mails werden serverseitig bereinigt und
  clientseitig per `inlineStylesToCssom()` auf CSSOM umgestellt.

## Inhalt

1. Begriffe
2. Code-Landkarte
3. Routen, Zugriff und API-Rahmen
4. Datenhaltung
5. Exchange-Anbindung (EWS)
6. API-Verträge und Grenzwerte
7. Anhänge, Viewer und Zwischenspeicher
8. Terminerinnerungen
9. Frontend
10. HTML-Bereinigung und CSP
11. Invarianten (nicht brechen!)
12. Tests
13. Änderungsrezepte
14. Bekannte Eigenheiten
15. KI-Unterstützung
16. Signaturvorlagen

---

## 1. Begriffe

| Begriff (UI) | Code | Bedeutung |
| --- | --- | --- |
| Benutzer-ID | `$access['uid']` = `office_uid` ?? `username` | Schlüssel für lokale Tabellen und Nextcloud-Bereich |
| Postfach / Impersonation | `$access['impersonate']`, `OrvantaConfigService::impersonationAddress()` | SMTP-Adresse aus dem AD (`exchange_identity = smtp`) oder `username@<exchange_upn_domain>` (`upn`); im Demo-Modus ersatzweise `username@demo.local` |
| Dienstkonto | `exchange_service_user` / `exchange_service_password` | Konto mit `ApplicationImpersonation`; Kennwort mit `SecretBox` verschlüsselt |
| Element-ID | `id` + `change_key` | EWS-`ItemId` (Base64, opak); `change_key` wird nur bei `updateEvent()` mitgesendet |
| Ordnerschlüssel | `folderKey()` (JS), `EwsXml::folderId()` | Systemordner als Kleinbuchstaben-Name (`inbox`, `drafts`, …), eigene Ordner als `FolderId` |
| Zwischenspeicher | `orvanta_cache_items`, `OrvantaAttachmentService::cache()` | Kopie geöffneter Anhänge im Nextcloud-Ordner `<cache_folder>/` mit Quota `cache_quota_mb` |
| Erinnerung | `orvanta_reminders`-Zeile | Lokaler Zustand einer Exchange-Terminerinnerung (`pending` → `delivered` → `dismissed`/`snoozed`) |
| Demo-Modus | `OrvantaConfigService::isDemo()` | `exchange_host = demo` und `APP_ENV ≠ production` → `DemoExchangeTransport` |

## 2. Code-Landkarte

| Datei | Verantwortung |
| --- | --- |
| `app/Controllers/OrvantaController.php` | `index()` (App-Seite im Layout `layouts.editor`, Konfiguration als `$orvanta`), `openAttachment()` (Viewer/Inline/Download), `attachmentFile()` (Rohdatei für den DocumentServer), statisch `authorize()` (gemeinsame Zugriffsprüfung), `inlineType()` (sichere Inline-Typen) |
| `app/Controllers/OrvantaApiController.php` | JSON-API; Rahmen `handle()` (Zugriff, Body, CSRF, Fehlerabbildung), Hilfen `readBody()`, `mailPayload()`, `addresses()`, `ids()`, `requireId()`, `str()/int()/bool()`, `resync()` |
| `app/Controllers/Admin/OfficeController.php` | `updateOrvanta()` (Formular → `OrvantaConfigService::save()`), `testOrvanta()` (`testConnection()`), `render()` übergibt `orvanta*`-Variablen an `views/admin/office.php` |
| `app/Services/Orvanta/OrvantaConfigService.php` | `DEFAULTS`, `AUTH_MODES`, `IDENTITY_MODES`, `VERSIONS`, `DEFAULT_FOLDERS`; `all()` (gecacht, vor Migration nur Defaults), `isEnabled()`, `isDemo()`, `ewsUrl()`, `transportOptions()`, `impersonationAddress()`, `save()` (Validierung), Grenzen `pollInterval()`, `reminderLeadMinutes()`, `cacheQuotaBytes()` |
| `app/Services/Orvanta/OrvantaExchangeService.php` | Fachlogik je Modul (siehe Abschnitt 5), `call()` (SOAP + Fehlerbehandlung), `translate()` (EWS-Fehlercodes → deutsche Meldung), Mapper `messageSummary()`, `calendarSummary()`, `contactData()`, `taskData()`, `attachmentList()` |
| `app/Services/Orvanta/EwsXml.php` | `envelope()`, `parse()`, `error()`, `text()/attr()/bool()/elements()`, `itemId()/itemIds()`, `mailbox()/mailboxes()/recipients()`, `folderId()`, `dateTime()` (UTC), `timestamp()`, `escape()` |
| `app/Contracts/ExchangeTransportInterface.php` | `post(url, xml, options): {status, body, error}` |
| `app/Services/Orvanta/CurlExchangeTransport.php` | cURL-POST, Auth `negotiate` (mit Dienstkonto `CURLAUTH_NTLM`, da GSSAPI Benutzer/Kennwort ignoriert und `app` kein Kerberos-Ticket hat; ohne Konto `CURLAUTH_NEGOTIATE` mit `:`), `ntlm`, `basic`; keine Redirects, nur HTTP(S); liefert `auth_offered` (WWW-Authenticate der letzten Antwort) |
| `app/Services/Orvanta/DemoExchangeTransport.php` | Beispieldaten; erkennt die Operation per `str_contains()` am SOAP-Text; schreibende Aufrufe werden bestätigt, nicht gespeichert |
| `app/Services/Orvanta/MailHtmlSanitizer.php` | `clean(html): {html, blocked_images}` – Whitelist für Tags/Attribute (Abschnitt 10) |
| `app/Services/Orvanta/OrvantaAttachmentService.php` | `openMode()`, `browserCapable()`, `documentType()`, `token()/verify()`, `load()`, `cache()`, `evict()`, `clear()`, `usage()`, `saveToNextcloud()`, `viewerConfig()` |
| `app/Services/Orvanta/OrvantaNotificationService.php` | `sync()`, `poll()`, `dismiss()`, `snooze()`, `relative()` |
| `app/Services/Orvanta/OrvantaRecipientService.php` | `suggest()` (Verlauf + Telefonliste), `remember()` (nach Versand, schreibt `.empfaenger.json`), `recent()` (Nextcloud-Lesen mit Sitzungs-Cache); `FILE_NAME`, `MAX_ENTRIES`, `CACHE_TTL` |
| `app/Services/Orvanta/OrvantaSignatureService.php` | Signaturvorlagen (Abschnitt 16): `all()/find()/blank()/validate()/save()/delete()`, `match(groups)`, `forUser(ssoUser)` → `{id,name,html}`, `person()`, `render()`, `preview()`, statisch `extension()`, `append(body, html)`, `strip(body)`; Konstanten `MARKER_CLASS`, `QUOTE_CLASS`, `PHONE_MODES`, `EXTENSION_LENGTH`, `LINE_HEIGHT`, `LOGO_MAX_WIDTH`, `DEFAULT_TEXT_COLOR`, `DEFAULT_SEPARATOR_COLOR`, `SAMPLE_PERSON`; statisch `logoSize()`, `imageDimensions()`, `scaleImage()` (verkleinert das Logo selbst auf die Anzeigegröße: Raster per GD als PNG, SVG über width/height) |
| `app/Repositories/OrvantaSignatureRepository.php` | `all(activeOnly)`, `find()`, `save()`, `delete()`; `ad_groups` als JSON-Liste → `groups` |
| `app/Controllers/Admin/OrvantaSignatureController.php` | `index()`, `edit()`, `save()`, `delete()`, `preview()` (eigenständiges HTML mit eigener CSP für das iframe) |
| `views/admin/orvanta-signatures.php`, `views/admin/orvanta-signature.php`, `public/assets/js/admin-signature.js` | Liste mit Vorschau-iframes, Formular mit Live-Vorschau (Query an `/admin/office/signaturen/vorschau`) |
| `app/Services/Orvanta/OrvantaException.php` | Fehler mit anzeigbarer Meldung und HTTP-Status (`status()`, Standard 502) |
| `app/Services/Orvanta/OrvantaAiService.php` | KI-Unterstützung (Abschnitt 15): `isAvailable()` (globale KI aktiv + `GET /models`, Datei-Cache 60 s), `resetAvailability()`, `improve()` (`POST /chat/completions`, Systemprompt je `MODES`, Verfeinern per Assistenten-Turn), `recordUsage()`, statisch `stripMarkers()`; Konstanten `MODES`, `MAX_TEXT`, `MAX_PROMPT`, `MAX_CONTEXT`, `MAX_OUTPUT_TOKENS`, `MARKER_CLASS`, `MARKER_ATTR_PREFIX` |
| `app/Services/Orvanta/OrvantaAiCharts.php` | Anonymisierter Nutzungsbericht für den Adminbereich: `period()`, `report(days)`, SVG-Erzeuger `usersChart()`, `daysChart()`, `tokensChart()` (Präsentationsattribute, kein `style`) |
| `app/Contracts/AiTransportInterface.php` | `request(method, url, headers, body, timeout): {status, body, error}` |
| `app/Services/Orvanta/CurlAiTransport.php` | cURL zum OpenAI-kompatiblen Endpunkt; keine Redirects, nur HTTP(S) (Zulässigkeit der Adresse wie im Admin-Verbindungstest über `OfficeAiService::isValidUrl`) |
| `app/Repositories/OrvantaRepository.php` | Einstellungen, Erinnerungen (`syncReminders()`, `dueReminders()`, `activeReminders()`, `markDelivered()`, `dismissReminder()`, `snoozeReminder()`, `purgeReminders()`), Zwischenspeicher (`cacheUsage()`, `findCacheItem()`, `addCacheItem()`, `oldestCacheItems()`, `deleteCacheItem()`, `cacheItems()`, `cacheUsagePerUser()`), KI-Nutzung (`recordAiUsage()`, `aiUsagePerUser()` (Pseudonyme „Benutzer n“), `aiUsagePerDay()`, `aiTokenTotals()`); SQL kompatibel zu MySQL und SQLite |
| `app/Core/Container.php` | `orvantaRepository()`, `orvantaConfig()`, `exchangeTransport()` (Demo oder cURL), `orvantaExchange()`, `orvantaAttachments()`, `orvantaRecipients()`, `orvantaNotifications()`, `orvantaAiCharts()`, `aiTransport()`, `orvantaAi()` (nutzt `officeAi()`); `officeApps()` erhält `orvantaConfig()->isEnabled()` als Closure |
| `app/Controllers/Controller.php` | `orvantaRemindersVisible()` → View-Variable `$orvantaReminders` (Kopfzeilen-Erinnerungen auf allen Seiten) |
| `app/Services/Office/OfficeAppCatalog.php` | App `orvanta` (`kind = intranet`), `ORVANTA_PATH = '/office/orvanta'` |
| `app/Services/Office/NextcloudFilesService.php` | `upload()`, `fetch()`, `delete()` über die Nextcloud-App `intranet_integration` (JWT `OfficeJwt::filesToken()`), `segment()`/`isSafeSegment()`/`isSafeFileName()` (führender Punkt nur bei Dateinamen), `MAX_BYTES` (16 MiB) |
| `views/orvanta/index.php` | App-Gerüst `.ov-office[data-orvanta]` mit `data-config` (JSON), `data-csrf`, `data-module`; Titelleiste, Menüband, Modulleiste, Ordner/Liste/Detail, Statusleiste (mit `[data-ov-ai-indicator]` und Autoren-Button `.ov-statusbar__credit[data-ov-dialog-open="about"]`), `<dialog data-ov-dialog="…">` (compose, event, contact, task, note, move, headers, folder-new, folder-props, profile, settings, help, about, reminder, ai), App-Kontextmenü `[data-ov-ctx-menu]` (Ordnerbaum, Mail-Liste, Textfelder, KI; Einträge per JS) |
| `views/orvanta/viewer.php` | Euro-Office-Viewer `.ov-viewer[data-orvanta-viewer]` mit `data-api`, `data-config`, `data-download` und Download-Fallback |
| `views/admin/office.php` | Karte `#orvanta` (Formular, Verbindungstest, Zwischenspeicher je Benutzer, KI-Nutzungsbericht `#orvanta-ki`) |
| `views/layouts/base.php` | Mitteilungsmenü mit `data-orvanta-reminders` / `data-orvanta-reminder-list`, lädt `orvanta-reminders.js` (mit `data-csrf`) |
| `public/assets/js/orvanta.js` | App (Abschnitt 9) |
| `public/assets/js/orvanta-reminders.js` | Erinnerungen in der Kopfzeile aller übrigen Seiten |
| `public/assets/js/orvanta-viewer.js` | Lädt `api.js` des DocumentServers und startet `DocsAPI.DocEditor` |
| `public/assets/css/orvanta.css` | Präfix `.ov-*`, Grid-Layout, Breakpoints 1200/900 px, Druck; `.ov-ai-*` (Indikator, Menü, Dialog, Block) |
| `public/assets/images/orvanta-ai-robot*.png` | Roboter-Grafiken (klein 36×48/72×96 für Statusleiste/Menü, groß 180×240/360×480 für Hilfe und Admin) |
| `database/migrations/033_create_orvanta_tables.sql` | Drei Tabellen (Abschnitt 4) |
| `database/migrations/034_create_orvanta_ai_usage.sql` | Tabelle `orvanta_ai_usage` (Abschnitt 4.1) |
| `database/migrations/035_orvanta_signatures.sql` | Tabelle `orvanta_signatures` und Spalte `phonebook.title` (Abschnitt 4.1) |
| `database/migrations/036_orvanta_signature_colors.sql` | Spalten `orvanta_signatures.text_color`/`separator_color` (Schlüssel einer Designfarbe) |
| `database/migrations/037_orvanta_signature_name_format.sql` | Spalte `orvanta_signatures.name_format` (`first_last`/`last_first`) |
| `public/index.php` | Routen (öffentliche Gruppe, Prüfung im Controller) und Admin-Routen in `$requireAdmin`; `/office/orvanta` gehört zu den Pfaden des automatischen SSO-Versuchs (`$ssoAttempt`) |
| `tests/Unit/OrvantaServiceTest.php` | Tests mit `RecordingExchangeTransport` und SQLite (Abschnitt 12) |
| `tests/Unit/OrvantaAiTest.php` | Tests der KI-Unterstützung mit `RecordingAiTransport` (Abschnitt 12) |
| `tests/Unit/OrvantaSignatureTest.php` | Tests der Signaturvorlagen gegen SQLite (Abschnitt 12) |

## 3. Routen, Zugriff und API-Rahmen

### 3.1 Routen

Alle App- und API-Routen liegen **außerhalb** der Admin-Gruppen in
`public/index.php`; die Prüfung erfolgt im Controller.

| Methode | Pfad | Controller | Zweck |
| --- | --- | --- | --- |
| GET | `/office/orvanta[?modul=…&termin=…&verfassen=…]` | `OrvantaController::index` | App (`verfassen=<key>`: Verfassen-Dialog in eigenem Tab, Abschnitt 9.1) |
| GET | `/office/orvanta/anhang/oeffnen?token=` | `openAttachment` | Anhang öffnen (Session + Token, `uid` muss passen) |
| GET | `/office/orvanta/anhang/datei?token=` | `attachmentFile` | Rohdatei für den DocumentServer (**nur Token**, keine Session) |
| GET | `/api/orvanta/status` | `status` | Benutzer, Demo, Host, Zwischenspeicher, Serverzeit |
| GET | `/api/orvanta/mail/ordner` | `folders` | Ordnerbaum |
| GET | `/api/orvanta/mail/ordner/eigenschaften?ordner=` | `folderProperties` | Anzahl/Größe eines Ordners, auch inkl. Unterordner (Kontextmenü „Eigenschaften“) |
| POST | `/api/orvanta/mail/ordner/neu` | `createFolder` | Neuen E-Mail-Ordner anlegen (Kontextmenü „Neuer Ordner“) |
| POST | `/api/orvanta/mail/ordner/gelesen` | `markFolderRead` | Alle Nachrichten eines Ordners als gelesen markieren (EWS `MarkAllItemsAsRead`) |
| GET | `/api/orvanta/mail?ordner=&offset=&limit=&q=` | `messages` | Nachrichtenliste |
| GET | `/api/orvanta/mail/nachricht?id=` | `message` | Nachricht inkl. bereinigtem HTML |
| GET | `/api/orvanta/mail/kopfzeilen?id=` | `messageHeaders` | Rohe Internet-Kopfzeilen (Kontextmenü „Info“) |
| POST | `/api/orvanta/mail/senden` | `send` | Neue Nachricht; merkt die Empfänger im Verlauf |
| GET | `/api/orvanta/empfaenger?q=&limit=` | `recipients` | Vorschläge für An/Cc/Bcc (Verlauf + Telefonliste) |
| POST | `/api/orvanta/mail/entwurf` | `draft` | Entwurf anlegen |
| POST | `/api/orvanta/mail/antworten` | `respond` | `mode` = `reply`/`replyall`/`forward` |
| POST | `/api/orvanta/mail/aktion` | `mailAction` | `action` = `read`/`unread`/`flag`/`unflag`/`move`/`delete`/`delete_permanent` |
| POST | `/api/orvanta/anhang/link` | `attachmentLink` | Signierte URL + Öffnungsmodus |
| POST | `/api/orvanta/anhang/nextcloud` | `attachmentToNextcloud` | Dauerhaft in Nextcloud speichern |
| GET | `/api/orvanta/zwischenspeicher` | `cacheUsage` | Belegung Zwischenspeicher + Postfach (`mailbox`) |
| POST | `/api/orvanta/zwischenspeicher/leeren` | `cacheClear` | Zwischenspeicher leeren |
| GET | `/api/orvanta/kalender?start=&end=` | `calendar` | Termine im Zeitraum (≤ 100 Tage) |
| GET / POST | `/api/orvanta/kalender/termin` | `event` / `saveEvent` | Termin lesen / anlegen oder ändern |
| POST | `/api/orvanta/kalender/termin/loeschen` | `deleteEvent` | Termin löschen (mit Absage an Teilnehmer) |
| POST | `/api/orvanta/kalender/antwort` | `meetingResponse` | `response` = `accept`/`tentative`/`decline` |
| GET | `/api/orvanta/kontakte?q=` | `contacts` | Kontaktliste |
| GET / POST | `/api/orvanta/kontakte/kontakt` | `contact` / `saveContact` | Kontakt lesen / speichern |
| POST | `/api/orvanta/kontakte/loeschen` | `deleteContact` | Kontakt(e) löschen |
| GET | `/api/orvanta/aufgaben?erledigt=0\|1` | `tasks` | Aufgabenliste |
| GET / POST | `/api/orvanta/aufgaben/aufgabe` | `task` / `saveTask` | Aufgabe lesen / speichern |
| POST | `/api/orvanta/aufgaben/loeschen` | `deleteTask` | Aufgabe(n) löschen |
| GET | `/api/orvanta/notizen` | `notes` | Notizen |
| GET / POST | `/api/orvanta/notizen/notiz` | `note` / `saveNote` | Notiz lesen / speichern |
| POST | `/api/orvanta/notizen/loeschen` | `deleteNote` | Notiz(en) löschen |
| GET | `/api/orvanta/erinnerungen[?sync=1]` | `reminders` | Fällige und aktive Erinnerungen |
| POST | `/api/orvanta/erinnerungen/erledigt` | `dismissReminder` | Erinnerung schließen |
| POST | `/api/orvanta/erinnerungen/spaeter` | `snoozeReminder` | Erinnerung verschieben (`minutes` 1–1440, Standard 5) |
| POST | `/api/orvanta/ki/verbessern` | `aiImprove` | KI-Unterstützung: `mode`, `text`, `prompt`, optional `previous_text`, `context{subject, recipients}` → `{text, usage{input_tokens, output_tokens}}` (Abschnitt 15) |
| POST | `/admin/office/orvanta` | `Admin\OfficeController::updateOrvanta` | Einstellungen (`$requireAdmin`, CSRF) |
| POST | `/admin/office/orvanta/pruefen` | `testOrvanta` | Verbindungstest, optional `mailbox` |
| GET | `/admin/office/signaturen` | `Admin\OrvantaSignatureController::index` | Signaturvorlagen (Liste, Vorschau-iframes) |
| GET/POST | `/admin/office/signaturen/vorlage[?id=…]` | `edit` / `save` | Vorlage anlegen/bearbeiten (CSRF) |
| POST | `/admin/office/signaturen/loeschen` | `delete` | Vorlage löschen (`id`) |
| GET | `/admin/office/signaturen/vorschau[?id=…\|Felder]` | `preview` | Eigenständige HTML-Seite mit eigener CSP (`img-src data:`, `style-src 'unsafe-inline'`, `frame-ancestors 'self'`) – Beispieldaten, gespeicherte oder ungespeicherte Vorlage |

### 3.2 Zugriffsprüfung (`OrvantaController::authorize()`)

Reihenfolge und Antwort bei Fehlschlag:

1. `Container::sso()->resolve()` liefert keinen Benutzer → **403**.
2. `orvantaConfig()->isEnabled()` falsch (`exchange_enabled ≠ 1` oder keine EWS-URL) → **404**.
3. `officeApps()->findAllowed('orvanta', $ssoUser)` ist `null` (keine Freigabe per AD-Gruppe/App-Paket) → **403**.
4. Aktive Office-Kachel (`OfficeController::ENTRY_PATH`) für den Benutzer nicht zugänglich → **403**.
5. Keine Postfachadresse (`impersonationAddress()` leer) und kein Demo-Modus → **403**.

Rückgabe: `{user, uid, impersonate}`. Der Browser bestimmt die Identität nie
selbst – `impersonate` stammt ausschließlich aus SSO + Konfiguration.

### 3.3 API-Rahmen (`OrvantaApiController::handle()`)

```
authorize() ─▶ (POST) readBody() + CSRF ─▶ $action($access) ─▶ Response::json(...) + Vary: Cookie
     │                  │                         │
     └──── Fehler ──────┴─────────────────────────┴─▶ {"error": "..."} mit Status
```

- `readBody()`: bei `Content-Type: application/json` wird `php://input`
  gelesen (≤ 20 MB, `MAX_BODY`, sonst 413), sonst `$request->post`.
- CSRF: `_token` im Body oder Header `X-CSRF-Token`; ungültig → **419**.
- Fehlerabbildung: `OrvantaException` → `status()`; `ValidationException` → 422
  (Meldungen verkettet); `HttpException` → deren Status; alles andere → 500
  mit generischer Meldung und Logeintrag `Orvanta: Unerwarteter Fehler.`
- GET-Routen sind lesend und ohne CSRF; **jede schreibende Route ist POST mit
  `$write = true`**.

## 4. Datenhaltung

### 4.1 Tabellen (Migration 033)

| Tabelle | Spalten (Auszug) | Hinweise |
| --- | --- | --- |
| `orvanta_settings` | `setting_key` (unique), `setting_value` (TEXT) | Schlüssel/Wert; fehlende Schlüssel → `OrvantaConfigService::DEFAULTS` |
| `orvanta_reminders` | `user_uid`, `item_id` (≤ 512), `item_hash` = `sha1(item_id)`, `subject`, `location`, `starts_at`, `remind_at`, `state` ∈ `pending\|delivered\|dismissed\|snoozed`, `delivered_at`, `dismissed_at` | Unique (`user_uid`, `item_hash`); Index (`user_uid`, `state`, `remind_at`) |
| `orvanta_cache_items` | `user_uid`, `kind` ∈ `attachment\|message`, `item_hash` = `sha1(attachment_id)`, `name` (Dateiname in Nextcloud), `path`, `content_type`, `size_bytes` | Unique (`user_uid`, `item_hash`); Index (`user_uid`, `created_at`) für FIFO |
| `orvanta_ai_usage` (Migration 034) | `user_uid`, `kind` ∈ `mail_compose\|mail_reply\|mail_forward\|event\|reminder`, `model`, `input_tokens`, `output_tokens`, `created_at` | Nur Zähler, nie Texte; Index (`created_at`), (`user_uid`, `created_at`) |
| `orvanta_signatures` (Migrationen 035–037) | `name` (≤ 120), `greeting` (≤ 120), `name_format` ∈ `first_last\|last_first` (Standard `first_last`), `street`, `postal_city` (≤ 190), `phone_mode` ∈ `prefix\|full`, `phone_prefix` (≤ 64, abschließendes Leerzeichen bleibt erhalten), `text_color`/`separator_color` (Schlüssel aus `SettingsService::THEME_COLORS`, Standard `color_text`/`color_accent`), `ad_groups` (JSON-Liste), `sort_order` (1–999), `active` | Zuordnung: erste aktive Vorlage nach `sort_order`, deren Gruppe (ohne Beachtung der Schreibweise) in den SSO-Gruppen vorkommt. Migration ergänzt zudem `phonebook.title` (Position aus dem AD, `LDAP_ATTR_TITLE`) |

Zeitspalten von `orvanta_reminders` werden mit PHP-`date('Y-m-d H:i:s')`
(Zeitzone des PHP-Prozesses) geschrieben und mit `strtotime()` gelesen.

### 4.2 Einstellungen (`orvanta_settings`)

Vollständige Liste und Bedeutung: [orvanta.md](orvanta.md#admin-einstellungen-adminofficeorvanta).
Validierung in `OrvantaConfigService::save()`:

| Schlüssel | Regel |
| --- | --- |
| `exchange_host` | leer, `demo` oder `Validator::isHostname()` |
| `exchange_ews_url`, `exchange_owa_url` | leer oder `isHttpsUrl()` (http/https, Host, ≤ 2048, keine Steuerzeichen/Backslashes) |
| `exchange_enabled = 1` | verlangt Host **oder** EWS-URL |
| `exchange_version` | Schlüssel aus `VERSIONS`, sonst `Exchange2016` |
| `exchange_auth` | `negotiate`/`ntlm`/`basic`; `basic` nur mit `https://`-EWS-URL |
| `exchange_service_password` | leer = unverändert; `exchange_service_password_clear` löscht; ≤ 500 Byte; gespeichert `SecretBox::encrypt()` |
| `exchange_identity` / `exchange_upn_domain` | `smtp`/`upn`; `upn` verlangt gültige Domäne |
| `exchange_timeout` | 3–120 |
| `cache_quota_mb` | 0–1 048 576 (0 = Zwischenspeicher aus) |
| `mailbox_quota_mb` | 0–10 485 760 (0 = ohne Grenze); Ersatzgrenze der Postfachbelegung (`mailboxQuotaBytes()`), wenn weder EWS noch AD eine Grenze liefern |
| `cache_folder` | `NextcloudFilesService::isSafeSegment()` (ein Pfadsegment) |
| `reminder_lead_minutes` | 0–1440 |
| `poll_interval` | 15–900 |
| `default_folder` | Schlüssel aus `DEFAULT_FOLDERS`, sonst `inbox` |

`OrvantaConfigService` cacht `all()` je Instanz; `save()` setzt den Cache
zurück. Vor Ausführung der Migration liefert `all()` nur die Defaults
(`PDOException` wird abgefangen) – damit ist Orvanta dann deaktiviert.

### 4.3 Weitere Zustände

| Ort | Schlüssel | Inhalt |
| --- | --- | --- |
| PHP-Session | `orvanta_sync_<uid>` | Zeitpunkt des letzten Exchange-Abgleichs der Erinnerungen |
| PHP-Session | `orvanta_recipients_<sha1(uid)>` | `{at, items[]}` – Empfänger-Verlauf, 120 s gültig (`OrvantaRecipientService::CACHE_TTL`) |
| `localStorage` | `orvanta.prefs` | `{dense, preview, images, notify, sound, folders, reading}` |
| `sessionStorage` | `orvanta.header.notified` | IDs, für die `orvanta-reminders.js` bereits eine Desktop-Benachrichtigung gezeigt hat |
| Nextcloud (Benutzerbereich) | `<cache_folder>/<sha1-Präfix 12>_<Name>` | Zwischenspeicher |
| Nextcloud (Benutzerbereich) | `<cache_folder>/Anhänge[/<Unterordner>]/<Name>` | „In Nextcloud speichern“ (nicht im Orvanta-Quota) |
| Nextcloud (Benutzerbereich) | `<cache_folder>/.empfaenger.json` (versteckt) | Empfänger-Verlauf `{format:"lanpa-orvanta-empfaenger", version, updated_at, items[{name,email,count,last_used}]}`, max. 500 Einträge, nicht im Quota |

## 5. Exchange-Anbindung (EWS)

### 5.1 Aufrufkette

```
OrvantaExchangeService::<operation>()
  └─ call($body, $impersonate, $strict = true)
       ├─ ewsUrl() leer → OrvantaException 503
       ├─ EwsXml::envelope($body, exchange_version, $impersonate)
       │     Header: RequestServerVersion, ExchangeImpersonation/ConnectingSID
       │             (PrimarySmtpAddress bei '@', sonst PrincipalName),
       │             TimeZoneContext "W. Europe Standard Time"
       ├─ transport->post(url, xml, transportOptions())
       ├─ error ≠ '' → 502 „Exchange ist nicht erreichbar: …“
       ├─ HTTP 401/403 → 502 „Exchange hat die Anmeldung abgelehnt …“ + Ursache aus
       │     authFailure(): kein Dienstkonto/kein Kennwort, Kontoschreibweise,
       │     angebotene Verfahren (`auth_offered` aus WWW-Authenticate), 403 = EWS gesperrt
       ├─ EwsXml::parse() null → 502 „ungültige Antwort“
       ├─ ErrorNonPrimarySmtpAddress → primäre Adresse aus MessageXml (`Value Name="Primary"`),
       │     einmalige Wiederholung; Zuordnung 24 h in `storage/cache/orvanta_primary_smtp.json`
       └─ EwsXml::error() ≠ null → translate() → 502
            ($strict = false: nur, wenn SOAP-Fault oder alle ResponseMessages fehlschlagen)
```

`translate()` übersetzt u. a. `ErrorImpersonateUserDenied`/`ErrorImpersonationDenied`,
`ErrorNonExistentMailbox`, `ErrorItemNotFound`, `ErrorFolderNotFound`,
`ErrorAccessDenied`, `ErrorSchemaValidation`; alles andere „Exchange-Fehler: …“.
**Alle** Exchange-Fehler erscheinen in der API als HTTP 502 (fachliche
Vorprüfungen des Service als 422/404).

### 5.2 Operationen

| Methode | EWS-Operation | Besonderheiten |
| --- | --- | --- |
| `testConnection()` | `GetFolder` inbox | ohne Impersonation = Dienstkonto selbst; liest `ServerVersionInfo` |
| `mailboxUsage()` | `GetFolder` `root` + `recoverableitemsroot` (`$strict = false`) mit `ExtendedFieldURI` `0x0E08` (PR_MESSAGE_SIZE_EXTENDED, Byte), `0x3FF5` (PR_STORAGE_QUOTA_LIMIT), `0x666E` (PR_PROHIBIT_SEND_QUOTA), `0x666A` (PR_PROHIBIT_RECEIVE_QUOTA; alle in KB); danach `FindFolder` Deep ab `root` mit `0x0E08` und `folder:ParentFolderId` (Seiten à 1000, max. 20) | `used` = Stammordner + alle Unterordner (`0x0E08` gilt je Ordner nur für dessen eigene Elemente), ohne `SearchFolder` und ohne den Teilbaum „Wiederherstellbare Elemente“; Tags werden per `hexdec()` normalisiert (Exchange schreibt `0xe08`); liefert EWS keine Grenze, gelten die AD-Grenzen `$directory` (`LdapClient::mailboxQuota()`, vom `OrvantaApiController` je Sitzung 15 min in `orvanta_directory_quota` gehalten); `limit` = Sendegrenze, ersatzweise Empfangsgrenze, Warnschwelle, dann `mailbox_quota_mb`; `source` = `exchange`/`directory`/`setting`/leer; `percent` gegen `limit`; fehlende Grenzen = 0 |
| `folders()` | `GetFolder` (Systemordner, `$strict = false`) + `FindFolder` Deep ab `msgfolderroot` (≤ 500) | nur `FolderClass` `IPF.Note*`; Sortierung Systemordner (`MAIL_FOLDERS`) vor Namen |
| `messages()` | `FindItem` Shallow, `IndexedPageItemView`, absteigend nach `DateTimeReceived`, optional `QueryString` (AQS) | `limit` 1–200 (API: 1–100), `has_more` aus `IncludesLastItemInRange` |
| `message()` | `GetItem` mit `BodyType = HTML` | Text-Body → `nl2br(htmlspecialchars())`, sonst `MailHtmlSanitizer::clean()`; externe Bilder landen in `data-blocked-src`, eingebettete in `data-cid` (Anhänge liefern `content_id`) |
| `send(user, mail, draftId, changeKey)` | ohne Entwurf und Anhänge `CreateItem SendAndSaveCopy`; sonst `saveDraft()` → `SendItem SaveItemToFolder` | mind. ein Empfänger in to/cc/bcc (außer `reference` mit `reply`/`replyall`); `mail.reference = {id, mode}` erzeugt `ReplyToItem`/`ReplyAllToItem`/`ForwardItem` statt `Message` |
| `saveDraft(user, mail, draftId, changeKey)` | neu: `CreateItem SaveOnly` in drafts; bestehend: `UpdateItem SaveOnly` (Subject, Body, Importance, To/Cc/Bcc) | anschließend `CreateAttachment` für neue Anhänge; liefert `{id, change_key}` |
| `respond()` | `send()` mit `reference` | übernimmt `to`, `cc`, `bcc`, `subject`, `importance`, Anhänge; `forward` verlangt Empfänger |
| `markRead()`, `flag()` | `UpdateItem` `AlwaysOverwrite` | Lesebestätigungen unterdrückt |
| `move()` | `MoveItem` | Zielordner per `EwsXml::folderId()` |
| `delete()` | `DeleteItem` `MoveToDeletedItems` bzw. `HardDelete` | `SendMeetingCancellations = SendToNone`; genutzt für Mail, Kontakte, Aufgaben, Notizen |
| `attachment()` | `GetAttachment` | `FileAttachment` → Base64-Inhalt; `ItemAttachment` → Body als `<Name>.html` (`text/html`) |
| `calendar()` | `FindItem` mit `CalendarView` (≤ 500) | Serien als Einzelvorkommen; nach Start sortiert |
| `event()` | `GetItem` (HTML-Body bereinigt, Teilnehmer, Anhänge, `recurring`) | |
| `createEvent()` | `CreateItem` | Body wird bereinigt; Einladungen `SendToAllAndSaveCopy` nur mit Teilnehmern; `reminder < 0` = keine Erinnerung |
| `updateEvent()` | `UpdateItem` `AlwaysOverwrite`, `SendToChangedAndSaveCopy` | Teilnehmer werden **nicht** geändert; `change_key` optional |
| `deleteEvent()` | `DeleteItem` mit `SendToAllAndSaveCopy` | Absagen an Teilnehmer |
| `respondToMeeting()` | `AcceptItem`/`TentativelyAcceptItem`/`DeclineItem` | |
| `upcomingReminders()` | über `calendar()` (`from − 1 h` … `from + hours`) | nur Termine mit `ReminderIsSet` |
| `contacts()`, `contact()` | `FindItem`/`GetItem` `AllProperties` (≤ 500, nach `FileAs`) | `email` = erste Adresse ohne `smtp:`/`sip:` |
| `createContact()`, `updateContact()` | `CreateItem` / `UpdateItem` (`SetItemField` je Feld) | mind. Vor-, Nachname oder Firma |
| `tasks()`, `task()`, `createTask()`, `updateTask()` | `FindItem` (optional ohne `Completed`), `GetItem`, `CreateItem`, `UpdateItem` | `updateTask()` setzt nur übergebene Felder; Status `Completed` → 100 %, `NotStarted` → 0 % |
| `notes()`, `note()`, `createNote()`, `updateNote()` | Ordner `notes`, `ItemClass IPM.StickyNote` | Betreff = erste Zeile (≤ 80 Zeichen); Farbe aus Property `0x8B00` |

### 5.3 IDs, Ordner und Zeit

- EWS-IDs sind opak; der Server prüft nur „nicht leer, ≤ 2048 Zeichen“
  (`requireId()`) und escaped sie in XML. Zugriffsschutz entsteht durch die
  Impersonation – fremde IDs scheitern an Exchange.
- `EwsXml::folderId()`: `^[a-z]+$` → `DistinguishedFolderId`, sonst `FolderId`.
  Echte `FolderId`s (Base64 mit Großbuchstaben/`=`) treffen das Muster nie.
- Zeiten: API und Frontend arbeiten mit **Unix-Sekunden**; an EWS gehen
  UTC-Zeitstempel (`EwsXml::dateTime()`), Antworten werden mit `strtotime()`
  gelesen. Die Zeitzone im SOAP-Kopf ist fest `W. Europe Standard Time`.

## 6. API-Verträge und Grenzwerte

| Endpunkt | Eingabe (JSON) | Antwort |
| --- | --- | --- |
| `status` | – | `{user{name,email}, demo, host, cache{used,quota,items,folder,percent}, server_time}` |
| `mail/ordner` | – | `{folders[{id,name,parent,total,unread,kind,class}]}` |
| `mail/ordner/eigenschaften` | Query `ordner` (Systemname wie `inbox` oder FolderId) | `{id, name, total, unread, subfolders, size, total_with_subfolders, size_with_subfolders}`; Größe in Byte aus `PR_MESSAGE_SIZE_EXTENDED` (`0x0E08`), Unterordner per `FindFolder` Deep (ohne Suchordner, max. 1000) |
| `mail/ordner/neu` | `parent` (leer = oberste Ebene, `msgfolderroot`), `name` (1–255 Zeichen, Steuerzeichen entfernt) | `{id, name, message}`; Ordnerklasse `IPF.Note`; vorhandener Name → `ErrorFolderExists` (502 mit Hinweis) |
| `mail/ordner/gelesen` | `folder` | `{ok, message}`; `SuppressReadReceipts` = true |
| `mail` | Query `ordner`, `offset`, `limit` (1–100), `q` | `{items[], total, offset, has_more}`; Element: `id, change_key, subject, preview, from{name,email}, to[], received, sent, is_read, has_attachments, size, importance, flagged, categories[], item_class, is_meeting_request` |
| `mail/nachricht` | Query `id` | wie Listenelement + `body_html, blocked_images, cc, bcc, reply_to, sender, internet_message_id, attachments[{id,name,content_type,content_id,size,inline,is_item}]`; `cid:`-Bilder zeigen per `src` auf `anhang/oeffnen?token=…` |
| `mail/kopfzeilen` | Query `id` | `{id, subject, headers, source}`; `headers` = unveränderter RFC-5322-Kopfblock aus `item:MimeContent` (`source` = `mime`), ersatzweise aus `InternetMessageHeaders` zusammengesetzt (`source` = `exchange`) |
| `mail/senden` | `to, cc, bcc` (Strings oder `{name,email}`), `subject, body, html, importance, attachments[{name, content_type, content(Base64)}]`, optional `draft_id, change_key` (gespeicherten Entwurf senden) | `{id, message}` |
| `empfaenger` | Query `q` (Pflicht, sonst leer), `limit` (1–25, Standard 8) | `{items[{display_name,email,department,source,recent}]}`; Verlaufstreffer zuerst (`source` = „Zuletzt verwendet“), dann Telefonliste ohne Dubletten; bekannte Adressen erhalten Name/Bereich aus der Telefonliste |
| `mail/entwurf` | wie `mail/senden`; `draft_id, change_key` aktualisieren einen bestehenden Entwurf; `reply_id, mode` legen einen Antwort-Entwurf an | `{id, change_key, message}` |
| `mail/antworten` | `id, mode` + Felder wie `mail/senden` (inkl. `cc`, `bcc`, Anhänge) | `{id, message}` |
| `mail/aktion` | `action, ids[]` (oder `id`), bei `move` zusätzlich `folder` | `{ok, count}` |
| `anhang/link` | `attachment_id, name` | `{url, mode, expires_in}` |
| `anhang/nextcloud` | `attachment_id, folder` (optionaler Unterordner) | `{ok, message, path, target}` (Fehler → 502) |
| `zwischenspeicher` | – | `{used, quota, items, folder, percent, mailbox}`; `mailbox` = `{used, quota, warning, receive_limit, percent}` in Byte oder `null`, wenn Exchange die Werte nicht liefert (Fehler blockieren die Antwort nicht) |
| `zwischenspeicher/leeren` | – | wie `zwischenspeicher` + `{removed, message}` | Query `start`, `end` (Standard: aktuelle Woche) | `{items[], start, end}`; Element: `id, change_key, subject, start, end, all_day, location, organizer, free_busy, type, reminder_set, reminder_minutes, is_meeting, my_response, categories` |
| `kalender/termin` (POST) | `id` (leer = neu), `change_key, subject, start, end, all_day, location, body, reminder` (Standard 15, < 0 = aus), `free_busy, required[], optional[]` | `{id[, change_key], message}`; löst `resync()` aus |
| `kontakte/kontakt` (POST) | `id, given_name, surname, company, job_title, department, email, phone, mobile, notes` | `{id, message}` |
| `aufgaben/aufgabe` (POST) | `id, subject, body, importance, status` und optional `due, start, reminder, percent` (Unix-Sekunden bzw. %) | `{id, message}` |
| `notizen/notiz` (POST) | `id, body` (nicht leer) | `{id, message}` |
| `erinnerungen` | Query `sync=1` erzwingt Abgleich | `{due[], active[], server_time, warning}`; Element: `id, item_id, subject, location, start, remind_at, state, relative` |

Grenzwerte (Server maßgeblich):

| Grenze | Wert | Stelle |
| --- | --- | --- |
| Request-Body | 20 MB | `OrvantaApiController::MAX_BODY` |
| Anhänge je Nachricht (dekodiert, Summe) | 15 MB | `mailPayload()`; Client prüft 15 MB je Datei (`addComposeFiles()`) |
| IDs je Sammelaktion | 1–200 | `ids()` |
| Element-ID | ≤ 2048 Zeichen | `requireId()` |
| Kalenderzeitraum | `end > start`, ≤ 100 Tage | `calendar()` |
| Adressen | `FILTER_VALIDATE_EMAIL`, getrennt durch `;`, `,`, Leerraum; entdoppelt | `addresses()` |
| Snooze | 1–1440 Minuten | `snoozeReminder()`, `OrvantaNotificationService::snooze()` |
| Anhang-Token | 300 s | `OrvantaAttachmentService::TOKEN_LIFETIME` |
| Datei im Zwischenspeicher / in Nextcloud | ≤ 16 MiB und ≤ Quota | `NextcloudFilesService::MAX_BYTES` |
| KI: markierter Text / Anweisung / Kontextfelder | ≤ 8 000 / ≤ 1 000 / ≤ 300 Zeichen; Antwort ≤ 1 200 Token | `OrvantaAiService::MAX_*`; Client spiegelt `AI_MAX_TEXT`/`AI_MAX_PROMPT` |
| KI: Zeitlimits | Erreichbarkeit 3 s (Cache 60 s), Anfrage `ORVANTA_AI_TIMEOUT` (5–120 s, Standard 30) | `AVAILABILITY_TIMEOUT`, `AVAILABILITY_TTL`, `Container::orvantaAi()` |

## 7. Anhänge, Viewer und Zwischenspeicher

### 7.1 Öffnen

```
Klick in orvanta.js
  ├─ window.open('', '_blank') sofort (Popup-Blocker), Platzhaltertext
  ├─ POST /api/orvanta/anhang/link {attachment_id, name}
  │     token = JWT {aud: orvanta_attachment, sub: uid, imp, att, name, iat, exp = iat + 300}
  │     Schlüssel = SecretBox::deriveKey('orvanta_attachment')
  └─ Popup → /office/orvanta/anhang/oeffnen?token=…
        verify() + claims.uid === aktueller uid (sonst 403)
        openMode(name):
          office  + officeAvailable() → views/orvanta/viewer.php (DocEditor, mode=view)
          browser oder (office ohne DocumentServer, aber browserCapable) → inline
          sonst → Download
```

- `openMode()`: Endungen aus `OFFICE_TYPES` → `office` (Word/Zelle/Folie/PDF
  inkl. `pdf`, `txt`, `csv`, `html`); `BROWSER_TYPES` (`png jpg jpeg gif webp
  bmp svg txt pdf`) → `browser`; sonst `download`. `pdf` und `txt` sind damit
  `office`, fallen ohne DocumentServer aber auf Inline zurück.
- Inline-Auslieferung: `inlineType()` erzwingt für bekannte Endungen einen
  festen MIME-Typ; Header `nosniff`, `Cache-Control: private, no-store` und
  CSP `default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox`
  (gilt auch für SVG).
- Viewer: `viewerConfig()` baut die DocEditor-Konfiguration (`documentType`
  aus `OFFICE_TYPES`, `document.key` = 20 Zeichen aus `sha1(attachment_id|token)`,
  `document.url` = `appInternalUrl()` + `office/orvanta/anhang/datei?token=…`,
  `permissions.edit = false`, `editorConfig.mode = view`) und signiert sie mit
  dem Office-JWT-Secret (`exp` 1 h). `orvanta-viewer.js` lädt `api_url`
  (`euroOfficePublicPath()` + `web-apps/apps/api/documents/api.js`), setzt
  Größe und `onError`/`onRequestClose` und zeigt nach 20 s ohne iframe den
  Download-Fallback.
- `attachmentFile()` prüft **nur** das Token (der DocumentServer hat keine
  Intranet-Session) und lädt die Datei für `claims.uid`/`claims.impersonate`.

### 7.2 Laden und Zwischenspeicher (`OrvantaAttachmentService`)

```
load(uid, impersonate, attachmentId)
  hash = sha1(attachmentId)
  Eintrag in orvanta_cache_items und Quota > 0?
     ja → nextcloud->fetch(uid, cache_folder, name)
            ok + Inhalt → zurück (cached = true)
            sonst       → Eintrag löschen, weiter mit Exchange
  exchange->attachment() → cache(uid, hash, attachment) → zurück (cached = false)

cache(): nur wenn Quota > 0, 0 < Größe ≤ Quota, ≤ MAX_BYTES, Nextcloud verfügbar
         und noch nicht vorhanden → evict(uid, quota − size)
         → upload(<sha1[0..12]>_<segment(name)>) → addCacheItem()
         Fehler werden verschluckt (Rückgabe false)

evict(uid, limit): löscht älteste Einträge (created_at, id) in Zehnerblöcken
                   in Nextcloud und Tabelle, bis cacheUsage ≤ limit
clear(uid) = evict(uid, −1)
```

- `usage()` liefert `{used, quota, items, folder, percent}`; die Statusleiste
  der App und der Adminbereich (`cacheUsagePerUser()`, Top 100) lesen daraus.
- `saveToNextcloud()` lädt über `load()` (füllt also ggf. den
  Zwischenspeicher) und lädt nach `<cache_folder>/Anhänge[/<segment(folder)>]/`
  hoch; Ergebnis enthält `target` für `/office-starten?ziel=…`.
- Die Übertragung nach Nextcloud läuft über die Nextcloud-App
  `intranet_integration` (`/index.php/apps/intranet_integration/api/files`)
  mit einem JWT aus `OfficeJwt::filesToken()` – kein WebDAV-Kennwort des
  Benutzers.

## 8. Terminerinnerungen

### 8.1 Server

```
GET /api/orvanta/erinnerungen
  ├─ ?sync=1 oder letzter Abgleich (Session orvanta_sync_<uid>) ≥ 300 s:
  │     OrvantaNotificationService::sync(uid, impersonate)
  │       upcomingReminders(now, 48 h) → je Termin:
  │         eigene Erinnerung → remind_at = start − ReminderMinutesBeforeStart
  │         keine Erinnerung, reminder_lead_minutes > 0 → remind_at = start − lead
  │         keine Erinnerung, lead = 0 → übersprungen
  │         bereits beendete Termine werden übersprungen
  │       OrvantaRepository::syncReminders(uid, items)
  │       purgeReminders(starts_at < now − 7 Tage)   (alle Benutzer)
  │       Exchange-Fehler → Rückgabe als warning (kein HTTP-Fehler)
  └─ poll(uid):
        due    = pending/snoozed mit remind_at ≤ now (≤ 20) → markDelivered()
        active = alle delivered (≤ 20)
```

Nach `saveEvent`, `deleteEvent` und `meetingResponse` ruft der Controller
`resync()` auf (sofortiger Abgleich, Session-Zeitstempel wird gesetzt).

### 8.2 Zustandsautomat (`orvanta_reminders.state`)

```
             sync (neu)            poll: remind_at ≤ now
 (Exchange) ───────────▶ pending ─────────────────────────▶ delivered ──dismiss──▶ dismissed
                           ▲  ▲                              │   ▲                     │
                           │  └──────── poll ───── snoozed ◀─┘   │                     │
                           │            (remind_at ≤ now)  snooze│                     │
                           └──── sync: Startzeit geändert ───────┴─────────────────────┘
```

- `syncReminders()` (Transaktion): neue Termine → `pending`; vorhandene
  übernehmen Betreff/Ort/Zeiten; geänderte **Startzeit** setzt
  `delivered`/`dismissed` zurück auf `pending`; bei `snoozed` bleibt
  `remind_at` erhalten. Nicht mehr gelieferte Termine werden **nur** in den
  Zuständen `pending`/`snoozed` gelöscht.
- `snooze` ist aus jedem Zustand möglich und setzt `remind_at = now + minutes`.
- `dismiss`/`snooze` filtern immer zusätzlich auf `user_uid`.

### 8.3 Clients

| | `orvanta.js` (App) | `orvanta-reminders.js` (alle anderen Seiten) |
| --- | --- | --- |
| Aktiv, wenn | `[data-orvanta]` vorhanden | `$orvantaReminders` (Exchange aktiv, `reminder_header = 1`, App freigegeben) und **kein** `[data-orvanta]` |
| Takt | `poll_interval` (≥ 15 s); zusätzlich alle 5 Min. und beim Start `sync=1`; bei `visibilitychange` | 60 s; erster und jeder 5. Poll mit `sync=1`; bei `visibilitychange` |
| Anzeige | Dialog `reminder` (Öffnen, Später mit Minutenwahl, Schließen, Sammelaktionen), Glocke/Popover, Desktop-Benachrichtigung (`tag: orvanta-reminder-<id>`, `requireInteraction`), optional Ton (880 Hz) | Einträge im Mitteilungsmenü („5 Min. später“, „Schließen“), Desktop-Benachrichtigung (`tag: orvanta-<id>`) |
| Doppelte Anzeige | `shownReminderIds` (nur im Speicher) | `sessionStorage` `orvanta.header.notified` |
| Berechtigung | `Notification.requestPermission()` nur auf Benutzeraktion (Hinweis-Toast beim Start bzw. Einstellungen) | nutzt vorhandene Berechtigung |

Link zu einem Termin: `/office/orvanta?modul=calendar&termin=<item_id>`.

## 9. Frontend

### 9.1 `orvanta.js`

- IIFE, startet nur bei `[data-orvanta]`; liest `data-config` (JSON aus
  `OrvantaController::index()`: `user{name,email,username}`, `defaultModule`,
  `pollInterval`, `reminderLead`, `officeAvailable`, `nextcloudAvailable`,
  `cacheFolder`, `cacheQuota`, `demo`, `owaUrl`) und `data-csrf`.
- Zentraler Zustand `state` (`module`, `folders`/`folder`, `messages`/`total`/`hasMore`,
  `selected`/`selectedIds`, `filter`/`search`, `calView` (`day`/`workweek`/`week`/`month`/`agenda`),
  `calDate`/`events`, `contacts`, `tasks`/`tasksCompleted`, `notes`/`noteDraft`,
  `reminders`, `compose`, `online`, `prefs`).
- Module: `switchModule()` → `loadModule()`; URL per `history.replaceState`
  auf `?modul=<name>`; Startmodul: `?modul=` vor `data-module` (in
  `views/orvanta/index.php` über `$moduleFor` aus `default_folder` abgeleitet,
  `inbox` → `mail`); `?modul=calendar&termin=<id>` öffnet einen Termin (`init()`).
- `api(path, {query, body})`: `credentials: same-origin`, `Accept: application/json`,
  `X-CSRF-Token`; mit `body` automatisch POST + JSON. Nicht-JSON → „Ungültige
  Antwort des Servers.“; HTTP-Fehler → `Error(data.error)`. Netzwerkfehler und
  502/503/504 setzen den Offline-Status (`setOnline(false)`), Erfolg wieder
  online. **Kein Timeout/AbortController.**
- Verfassen: `openCompose(mode, message)` (`new`/`reply`/`replyall`/`forward`/
  `draft`), `contenteditable`-Editor, `composePayload()` (nur noch nicht
  hochgeladene Anhänge, `draft_id`, `change_key`), `sendCompose(form, asDraft)`:
  - Entwurf → `mail/entwurf` (mit `reply_id`/`mode` bei Antworten); der Dialog
    bleibt offen, `state.compose.draftId/changeKey` werden gesetzt, weitere
    Speicherungen aktualisieren denselben Entwurf;
  - Antwort/Weiterleitung ohne gespeicherten Entwurf → `mail/antworten`
    (mit Cc/Bcc und Anhängen; Exchange hängt Zitat und Bezug an);
  - neue Nachricht oder gespeicherter Entwurf → `mail/senden`.
  Im Ordner „Entwürfe“ bietet die Nachrichtenansicht „Entwurf bearbeiten“.
- Signatur: `config.signature` (`{id, name, html}` oder `null`, aus
  `OrvantaSignatureService::forUser()`). `renderComposeSignature(editor)`
  entfernt vorhandene `.ov-signature-block` und fügt den Block mit
  `contenteditable="false"` vor `:scope > .ov-quote` bzw. am Ende ein (davor
  stets ein editierbarer Absatz); wird nach `openCompose()`,
  `fillComposeFromDraft()` und `restoreCompose()` aufgerufen.
  `guardComposeSignature(editor)`: `beforeinput` verwirft Eingaben im Block und
  beschränkt Auswahlen, die ihn überlappen, auf die Teile davor/dahinter;
  `input` stellt den Block wieder her, wenn er fehlt oder sich sein
  `outerHTML` gegenüber `data-ov-signature-html` geändert hat (z. B. nach
  „Alles auswählen“ + Formatierung). Maßgeblich bleibt die serverseitige
  Fassung (Abschnitt 16).
- Verfassen in eigenem Tab: `detachCompose()` (Schaltfläche
  `data-ov-action="compose-detach"`) erstellt mit `composeSnapshot()` den
  vollständigen Zustand (Felder, Editor-HTML, Anhänge inkl. Base64, `mode`,
  `replyTo`, `draftId`, `changeKey`, Titel) und öffnet
  `/office/orvanta?modul=mail&verfassen=<key>`. Übergabe: bis 1,5 MB JSON
  zusätzlich in `localStorage` (`orvanta.compose.handoff.<key>`), sonst nur
  über `BroadcastChannel('orvanta-compose')` (`request` → `payload` →
  `received`). Der Ursprungs-Tab schließt den Dialog erst nach bestätigtem
  Empfang (Timeout 20 s, sonst bleibt die Nachricht dort geöffnet). Der neue
  Tab (`startStandaloneCompose()`, Klasse `ov--compose-standalone`, kein
  Erinnerungs-Polling) stellt den Zustand mit `restoreCompose()` wieder her;
  Senden/Verwerfen schließt den Tab (`finishStandaloneCompose()`), ein
  `beforeunload`-Hinweis schützt ungesendete Inhalte.
- Mail-Anzeige: `body.innerHTML = inlineStylesToCssom(message.body_html)`,
  danach `data-ov-style` → `node.style.cssText`; Links erhalten
  `target=_blank` und `rel="noopener noreferrer nofollow"`.
- Tastatur (außerhalb von Eingabefeldern/Dialogen): `1`–`5` Module, `/` Suche,
  `N` Neu, `R` Antworten, `Entf` Löschen (je Modul), Pfeile hoch/runter in der
  Liste, `Esc` schließt das Erinnerungs-Popover.
- Ansicht-Einstellungen (`orvanta.prefs`) setzt `applyPrefs()` als Klassen
  am Wurzelelement (u. a. `ov--no-folders`, `ov--no-reading`).
- Statusleiste: Verbindung, letzte Aktualisierung (`markSync()`), Quota
  (`loadQuota()`/`renderQuota()`, Warnfarbe ab 80 %).

### 9.2 Gestaltung

`orvanta.css` übernimmt Farbpalette, Ribbon und Statusleiste des
Notfallplan-Editors, ist aber vollständig eigenständig (`.ov-*`, eigene
Variablen `--ov-*` auf Basis der globalen `--color-*`). Grid: Titelleiste /
Menüband / Arbeitsbereich / Statusleiste; Arbeitsbereich mit Modulleiste,
Ordnern, Liste und Detail; Detail nur sichtbar mit `ov--detail-open`.
Breakpoints 1200 px und 900 px, eigenes Drucklayout.

## 10. HTML-Bereinigung und CSP

- `MailHtmlSanitizer::clean()` parst mit `DOMDocument` (`LIBXML_NONET`) und
  arbeitet mit Whitelists:
  - `DROP_TAGS` (inkl. Inhalt entfernt): `script`, `style`, `iframe`,
    `object`, `embed`, `svg`, `math`, `form`-Elemente, `link`, `meta`, `base`,
    `audio`, `video`, …
  - unbekannte Tags werden ausgepackt, ihre Kinder erneut geprüft;
  - `ALLOWED_ATTRIBUTES` (kein `id`, keine `on*`, keine `data-*`); `style`
    wird verworfen bei `expression`, `url(`, `javascript`, `behavior`,
    `@import`, `position: fixed`;
  - `a[href]` nur `http`, `https`, `mailto`, `tel`, `#…`; erhält `target`/`rel`;
  - `img[src]` nur `data:image/…` – alles andere (auch `cid:`) wird entfernt,
    gezählt (`blocked_images`) und das Bild durch `span.orv-img-blocked` ersetzt.
- Angewendet auf: empfangene Mails (`message()`), Terminbeschreibungen
  (`event()`), ausgehende HTML-Bodys (`messageXml()`, `createEvent()`,
  `updateEvent()`). `respond()` sendet `NewBodyContent` **unbereinigt**
  (nur XML-escaped) an Exchange.
- Die Seiten-CSP (`public/index.php`) erlaubt `style-src 'self' 'nonce-…'` –
  `style`-Attribute würden blockiert. Deshalb ersetzt `inlineStylesToCssom()`
  per Regex `style="…"` durch `data-ov-style="…"` (bewusst ohne
  `DOMParser`, da auch inerte Dokumente CSP-Verstöße melden). Das setzt
  serverseitig serialisiertes HTML mit gequoteten Attributen voraus.

## 11. Invarianten (nicht brechen!)

1. **Identität nur vom Server.** `impersonate` und `uid` kommen ausschließlich
   aus `authorize()`; kein Endpunkt akzeptiert ein Postfach aus dem Request.
2. **Jede Route ruft `authorize()`** (App, API über `handle()`,
   `openAttachment()`); einzige Ausnahme ist `attachmentFile()`, die
   stattdessen ein gültiges Token verlangt.
3. **Schreibende Endpunkte sind POST mit CSRF** (`handle(..., true)`).
4. **Exchange ist führend.** Lokale Tabellen enthalten keine Inhalte außer
   Erinnerungs-Metadaten; der Zwischenspeicher ist verwerfbar und Fehler darin
   dürfen nie eine Anfrage scheitern lassen.
5. **Alle Werte in SOAP über `EwsXml::escape()`**, Zeiten über
   `EwsXml::dateTime()`, Ordner über `EwsXml::folderId()`.
6. **HTML aus Exchange erreicht den Browser nur über `MailHtmlSanitizer`**;
   `innerHTML` nur für `body_html` vom Server, alle übrigen Werte per
   `textContent`/`el()`.
7. **Keine Inline-Styles im Frontend** – nur Klassen oder CSSOM
   (`node.style.cssText`).
8. **Anhang-Tokens sind kurzlebig, zweckgebunden (`aud`) und benutzergebunden**;
   `openAttachment()` vergleicht `claims.uid` mit dem angemeldeten Benutzer.
9. **Quota-Einhaltung vor dem Upload** (`evict()` vor `upload()`); Quota 0
   deaktiviert Lesen **und** Schreiben des Zwischenspeichers.
10. **Erinnerungen werden genau einmal als `due` geliefert** (`markDelivered()`
    im selben Poll); `dismiss`/`snooze` immer mit `user_uid` filtern.
11. **Demo nie in Produktion** – `isDemo()` prüft `app.env`; der Transport wird
    ausschließlich in `Container::exchangeTransport()` gewählt.
12. Repository-SQL muss auf MySQL **und** SQLite laufen (Tests).
13. Grenzwerte in Client (15 MB je Datei, Poll ≥ 15 s) und Server synchron halten.
14. **Signatur wird serverseitig angefügt** (`withSignature()` in `send`/`draft`/
    `respond`, vor `stripMarkers()`/Sanitizer); der Editor-Block ist nur
    Anzeige. Der Marker `div.ov-signature-block` darf nicht umbenannt werden
    (Dedupe beim erneuten Öffnen von Entwürfen; Sanitizer lässt `class`,
    `table`, `img` mit `data:`-URI und Inline-Styles ohne `url(` durch).

## 12. Tests

Dependency-freier Runner: `php tests/run.php` (Syntaxprüfung zusätzlich
`find . -name "*.php" -print0 | xargs -0 -n1 php -l`).

| Datei | Inhalt |
| --- | --- |
| `tests/Unit/OrvantaAiTest.php` | `RecordingAiTransport`; Verfügbarkeit ohne Konfiguration (keine Anfrage), `GET /models` mit Datei-Cache und Fingerabdruck (URL\|Modell), `improve()` (Anfrageaufbau, Kontext, Token, `max_tokens`), Verfeinern (Assistenten-Turn), Bearer-Schlüssel, Validierung 422, 503 ohne KI, 502 bei Timeout/500/401/ungültigem JSON/leeren `choices`, `stripMarkers()`, Zähler und pseudonyme Auswertung (keine SIDs), `OrvantaAiCharts` (Zeitraum, SVG ohne `style`, leere Daten) |
| `tests/Unit/OrvantaSignatureTest.php` | Validierung (Pflichtfelder, Modus, Reihenfolge, Gruppen-Dedupe, Präfix-Leerzeichen), Speichern/Laden/Löschen, Zuordnung (Reihenfolge, inaktiv, Schreibweise), Darstellung (AD-Daten, Präfix + Durchwahl vs. komplette Rufnummer, Farben, Logo als `data:`-URI, Sanitizer-Durchlauf), Logo in Zeilenhöhe und gewählte Designfarben (`logoSize()`, `imageDimensions()` inkl. SVG), Logo-Pixelgröße = Anzeigegröße (`scaleImage()`), Fallbacks (ohne Logo/Telefonbuch/inaktiver Eintrag), `extension()`, `append()` (Dedupe, vor Zitat), `strip()` |
| `tests/Unit/OrvantaServiceTest.php` | Konfiguration (Defaults, EWS-URL, Validierung, verschlüsseltes Kennwort), EWS-Umschlag (Impersonation, Version), Nachrichten lesen/senden, KI-Marker werden beim Senden/Entwurf/Termin entfernt, Transportfehler → `OrvantaException`, 503 ohne Server, Kalender/Kontakte/Aufgaben/Notizen, Postfachbelegung (`mailboxUsage()`, Summe aller Ordner ohne Suchordner/Wiederherstellbare Elemente, Quota in KB, ohne Grenzen, AD-Grenzen, Ersatzgrenze `mailbox_quota_mb`, `LdapClient::mailboxQuotaFromEntries()`), `EwsXml`, Sanitizer, Erinnerungen (Sync, fällig, erledigt, Snooze, verschobene Termine, Sync-Fehler), `relative()`, `openMode()`, Token-Ablauf und Zweckbindung, Quota/FIFO, Quota 0, Laden mit Zwischenspeicher, Nextcloud-Ablage, Viewer-Konfiguration, Empfänger-Vorschläge (Verlauf zuerst, Dubletten, Nextcloud-Lesen/Fehler, versteckte Dateinamen) |

Testbausteine: `RecordingExchangeTransport` (zeichnet SOAP auf, antwortet mit
`DemoExchangeTransport` oder `$forced`), `orvantaPdo()` (SQLite-Schema
**parallel zur Migration pflegen**), `orvantaConfig()`, `orvantaExchange()`,
`orvantaAttachments()`. `FakeOfficeProbe` und `officeConfig()` stammen aus
`tests/Unit/OfficeTest.php` (wird wegen alphabetischer Ladereihenfolge vorher
geladen).

Controller und JavaScript haben keine automatisierten Tests. Manuell im
Demo-Modus prüfen (`exchange_host = demo`, `APP_ENV ≠ production`,
`SSO_FAKE_USER`, siehe `docs/office.md` „Testmodus“): alle Module,
Verfassen/Antworten mit und ohne Anhang, Anhang öffnen (Viewer, Browser,
Download), Erinnerung in App und Kopfzeile, Statusleiste/Quota.

## 13. Änderungsrezepte

| Aufgabe | Vorgehen |
| --- | --- |
| **Neue Einstellung** | `OrvantaConfigService::DEFAULTS` + Validierung in `save()` + ggf. Getter mit Klammerung; Formularfeld in `views/admin/office.php` (Karte `#orvanta`, `$ovField`/`$ovFieldError`); bei Frontend-Bedarf in `OrvantaController::index()` → `$orvanta`; Test in `OrvantaServiceTest.php`; Tabelle in `docs/orvanta.md`. Keine Migration nötig (Schlüssel/Wert). |
| **Neuer API-Endpunkt** | Methode in `OrvantaApiController` über `handle()` (schreibend: `true`), Route in `public/index.php` beim Orvanta-Block, Aufruf über `api()` in `orvanta.js`; Routenliste in `docs/orvanta.md` und `agentsindex.md` ergänzen. |
| **Neues Feld eines Exchange-Elements** | `FieldURI` in `MESSAGE_FIELDS`/`CALENDAR_FIELDS` bzw. Abfrage, Mapper (`messageSummary()` …) erweitern, beim Schreiben `SetItemField` in `update*()` und Element in `create*()` (EWS verlangt die **Schema-Reihenfolge** der Kindelemente!), Demo-Antworten in `DemoExchangeTransport` ergänzen, Frontend-Anzeige/-Formular, Test. |
| **Neue EWS-Operation** | In `OrvantaExchangeService` über `call()` (nie direkt am Transport), Werte mit `EwsXml::escape()`; neuen Fehlercode ggf. in `translate()`; `DemoExchangeTransport::post()` um Erkennung erweitern, sonst antwortet die Demo mit generischem Erfolg. |
| **Neuer Anhangs-Öffnungsmodus / Dateityp** | `OFFICE_TYPES` bzw. `BROWSER_TYPES`, bei Inline zusätzlich `OrvantaController::inlineType()`; Test „Oeffnungsart je Dateityp“. |
| **Zwischenspeicher für Nachrichten** (`kind = message`) | Spalte existiert; `OrvantaAttachmentService::cache(..., 'message')` mit eigenem Hash-Schema verwenden, damit keine Kollision mit `sha1(attachment_id)` entsteht. |
| **Erinnerungslogik ändern** | `OrvantaNotificationService::sync()/poll()` und `OrvantaRepository::syncReminders()` (Zustandsübergänge Abschnitt 8.2); beide Clients prüfen; Tests „Erinnerungen …“, „Repository-Sync …“. |
| **Neues Modul** | `DEFAULT_FOLDERS`, Modulliste/Menüband/Dialog in `views/orvanta/index.php`, `switchModule()`/`loadModule()`/Tastenkürzel in `orvanta.js`, Service-Methoden + API, Demo-Daten, Doku. |
| **KI-Unterstützung in weiterem Editor** | Editor mit `contenteditable` und eigenem `data-ov-…-body`-Hook; in `orvanta.js` `aiEditors()`, `aiMode()` und `aiContext()` erweitern; neuer Wert in `OrvantaAiService::MODES` + ENUM in `orvanta_ai_usage.kind` (Migration) + Systemprompt in `messages()`; serverseitig `stripMarkers()` vor dem Sanitizer aufrufen; Test. |
| **Signaturaufbau ändern** (Zeilen, Trennzeichen, Logo-Größe) | Nur `OrvantaSignatureService::render()`/`phoneLine()` (E-Mail-tauglich: Tabelle, Inline-Styles ohne `url(`, Bilder nur als `data:`-URI – sonst entfernt sie `MailHtmlSanitizer`); Farben über die Farbschlüssel der Vorlage aus `SettingsService::theme()`; Logo-Höhe = `LINE_HEIGHT` × Zeilenzahl, das Bild wird per `scaleImage()` selbst auf diese Größe gebracht (Outlook/Exchange ignorieren width/height); Test „Darstellung …“ anpassen; Screenshot 88/90 erneuern. |
| **Neues Vorlagenfeld der Signatur** | Spalte per Migration + SQLite-Schema in `OrvantaSignatureTest.php`; `OrvantaSignatureRepository` (`hydrate`, `save`), `OrvantaSignatureService::blank()/validate()/render()`, Formular `views/admin/orvanta-signature.php` (`data-signature-field`), Query in `admin-signature.js` und `OrvantaSignatureController::preview()`; Doku. |
| **KI-Prompt oder Modellparameter ändern** | Nur `OrvantaAiService::messages()` bzw. `improve()` (Temperatur, `max_tokens`); nie Modell/URL in Orvanta speichern – sie stammen aus `OfficeAiService`. |

Nach Änderungen: `php tests/run.php`; diese Referenz sowie bei Benutzersicht
`docs/orvanta.md` und `agentsindex.md` aktualisieren.

## 14. Bekannte Eigenheiten

- `reminder_lead_minutes` gilt **nur** für Termine ohne eigene Exchange-
  Erinnerung; eine gesetzte Erinnerung wird nicht vorgezogen.
- Eingebettete `cid:`-Bilder werden über den Anhang-Endpunkt
  (`anhang/oeffnen?token=…`, 5 Minuten gültig) geladen; wird eine Nachricht
  länger offen gehalten, kann das Bild beim erneuten Rendern ablaufen.
- Antworten senden den Editorinhalt als `NewBodyContent`; Exchange hängt die
  Originalnachricht selbst an. Wird im Editor zusätzlich zitiert, erscheint
  das Zitat doppelt.
- Die Terminbeschreibung ist seit der KI-Unterstützung ein
  `contenteditable`-Editor (`[data-ov-event-body]`), der HTML sendet; der
  Server bereinigt wie bei Mails (`eventBody()`: `stripMarkers()` →
  `MailHtmlSanitizer`). Ältere reine Textbeschreibungen bleiben lesbar.
- „Erinnerung“ als KI-Einsatzort (`reminder`) bedeutet: Termin mit gesetzter
  Erinnerung (Auswahl ≠ „Keine“); es gibt keinen eigenen Erinnerungs-Editor.
- Originaltexte für „Auf Original zurücksetzen“ liegen nur im JS-Zustand
  (`ai.originals`) und werden beim Schließen des Dialogs verworfen; Blöcke aus
  wieder geöffneten Entwürfen sind daher nur noch verfeinerbar/entmarkierbar.
- Das App-Kontextmenü (`[data-ov-ctx-menu]`, Abschnitt „App-Kontextmenue“ in
  `orvanta.js`) ersetzt das Browser-Menü an drei Stellen: auf Ordnern im
  Ordnerbaum (`folderMenuItems()`: Öffnen, Neuer Ordner … – Dialog
  `folder-new`, legt einen Unterordner des angeklickten Ordners an –, Alle als
  gelesen markieren – deaktiviert ohne ungelesene Nachrichten –, Eigenschaften
  – Overlay `folder-props` mit Elementen, Ungelesen, Größe und ggf.
  Unterordnern), auf Zeilen der Mail-Liste (Öffnen/Entwurf bearbeiten, Antworten, Allen antworten,
  Weiterleiten, gelesen/ungelesen, Kennzeichnen, Verschieben, Archivieren,
  Löschen – bei angehakten Zeilen für alle markierten) und in Textfeldern
  (`input`, `textarea`, `contenteditable`: Rückgängig, Wiederholen,
  Ausschneiden, Kopieren, Einfügen, Alles auswählen; KI-Einträge davor, sofern
  Markierung bzw. KI-Block). Überall sonst (z. B. Lesebereich) bleibt das
  Browser-Menü. Rechtsklick auf eine nicht ausgewählte Zeile wählt sie aus wie
  ein Linksklick; Antworten/Weiterleiten laden die Nachricht zuvor vollständig
  (`withFullMessage()`).
- Drag&Drop: Mail-Zeilen sind `draggable`; `dragstart` legt die gezogenen IDs
  in `state.dragIds` ab (ist die Zeile angehakt, alle angehakten Zeilen, sonst
  nur diese). Ordner im Ordnerbaum (`bindFolderDrop()`) nehmen die Ablage an –
  außer dem gerade geöffneten Ordner – und verschieben über
  `mailAction('move', ids, ordner)` (`POST /api/orvanta/mail/aktion`,
  EWS `MoveItem`). CSS: `.ov-item--dragging`, `.ov-folder--drop`.
- „Einfügen“ nutzt `navigator.clipboard.readText()` (nur sicherer Kontext,
  Browser kann nachfragen oder ablehnen → Hinweis-Toast auf Strg+V);
  Ausschneiden/Kopieren/Rückgängig laufen über `document.execCommand`. Die
  Auswahl im Feld wird beim Öffnen gesichert und vor jedem Befehl
  wiederhergestellt (`mousedown` im Menü ist `preventDefault`).
- Das Menü wird beim Öffnen in den `<dialog>` des Ursprungselements
  verschoben, weil modale Dialoge in der Top-Layer liegen und ein
  `position: fixed`-Element im `body` sonst verdeckt bliebe.
- Schreibende EWS-Aufrufe nutzen `ConflictResolution="AlwaysOverwrite"`; es
  gibt keine optimistische Sperre (letzte Änderung gewinnt).
  `updateEvent()` ändert keine Teilnehmer.
- Fällige Erinnerungen erscheinen nur in dem Tab/Client, dessen Poll sie
  zuerst abholt; andere sehen sie nur noch als `active`.
- `purgeReminders()` löscht beim Abgleich eines Benutzers alte Einträge
  **aller** Benutzer (Starttermin älter als 7 Tage).
- Die Zeitzone im SOAP-Kopf ist fest `W. Europe Standard Time`; ganztägige
  Termine und Serien hängen davon ab.
- Die Liste lädt höchstens 100 Nachrichten je Abruf (Service erlaubt 200);
  Kontakte, Aufgaben, Notizen und Kalenderansichten sind auf 500 Elemente
  begrenzt, ohne Nachladen.
- Inline-Bilder einer Mail (`inline = true`) werden im Frontend aus der
  Anhangliste gefiltert und nur im Nachrichtentext angezeigt.
- `usage()` zählt Einträge über `cacheItems(uid, 10000)` (Obergrenze der Zählung).
- Der Anhang-Viewer bekommt ein Token mit 5 Minuten Laufzeit; lädt der
  DocumentServer die Datei später erneut (z. B. nach Neuladen), ist der Link
  abgelaufen und der Anhang muss in Orvanta neu geöffnet werden.
- `api()` in `orvanta.js` hat kein Zeitlimit; hängende Exchange-Aufrufe enden
  erst mit `exchange_timeout` auf dem Server.

---

## 15. KI-Unterstützung

**Grundsatz:** Orvanta besitzt keine eigene KI-Konfiguration. Modell, Adresse,
Schlüssel und Aktivierung kommen aus `OfficeAiService` (Adminbereich Office →
Lokale KI, Tabelle `settings`). Dem Frontend wird nur `aiAvailable` (bool)
übergeben. Jeder Aufruf geht Browser → Intranet-Server → KI-Endpunkt; der
Browser kennt den Endpunkt nicht.

### 15.1 Ablauf

```
Rechtsklick auf Markierung ──► [data-ov-ctx-menu] (KI-Einträge) ──► Dialog "ai" (prompt)
   └─ aiSubmit(): POST /api/orvanta/ki/verbessern {mode, text, prompt, previous_text?, context}
        OrvantaApiController::aiImprove() ─► OrvantaAiService::improve()
            ├─ Validierung (422), isActive() (503)
            ├─ POST {url}/chat/completions  (CurlAiTransport, Bearer falls Schlüssel)
            ├─ Fehler → 502 ohne Rohantwort in der Meldung
            └─ recordUsage(uid, mode, tokens, model)  → orvanta_ai_usage
   ◄─ {text, usage}  → aiWrapRange()/aiFillBlock(): <span class="ov-ai-block" data-ov-ai-id="…">
Senden/Entwurf/Termin: OrvantaExchangeService → OrvantaAiService::stripMarkers() → MailHtmlSanitizer
```

### 15.2 Verträge

- `mode` ∈ `MODES` (`mail_compose`, `mail_reply`, `mail_forward`, `event`,
  `reminder`); Client: `aiMode()` aus `state.compose.mode` bzw. Erinnerungs-
  Auswahl im Termindialog.
- `context`: nur `subject` (≤ 300 Zeichen) und `recipients` (Anzahl). Keine
  Adressen, kein weiterer Nachrichteninhalt.
- `previous_text` (Verfeinern): wird als Assistenten-Nachricht mitgegeben;
  `text` bleibt das Original aus `ai.originals` (Fallback: Blocktext).
- Antwort `text` ist Klartext (Zeilenumbrüche erlaubt); `cleanOutput()`
  entfernt Codezäune, Präfixe („Text:“) und umschließende Anführungszeichen.
  Der Client setzt ihn als Textknoten mit `<br>` ein – nie als HTML.
- Marker: Klasse `ov-ai-block` (+ `ov-ai-block--fresh` kurz nach Einfügen),
  Attribut `data-ov-ai-id`, `title`. `stripMarkers()` entfernt Klassen
  `ov-ai*`, Attribute `data-ov-ai*`, `title` markierter Elemente,
  Kommentare mit `ov-ai`, und löst attributlose `<span>` auf.

### 15.3 Datenschutz und Bericht

- `orvanta_ai_usage` enthält nur Zähler. `aiUsagePerUser()` liefert Pseudonyme
  in Reihenfolge des ersten Auftretens im Zeitraum; `user_uid` wird nicht
  zurückgegeben, die Zuordnung nicht gespeichert.
- SVGs (`OrvantaAiCharts`) nutzen ausschließlich Präsentationsattribute
  (`fill`, `stroke`, `font-size`) – kein `style`, kein Skript (CSP).
- Zeitraum per `GET /admin/office?ki_zeitraum=7|30|90` (`period()` normiert).

### 15.4 Testendpunkt

`docker compose --profile ki up -d` startet `ghcr.io/ggml-org/llama.cpp:server`
mit `Qwen/Qwen2.5-0.5B-Instruct-GGUF` (Volume `ki_models`, Port `KI_PORT`).
`scripts/seed.php` trägt bei gesetztem `OFFICE_AI_SEED_URL` die Werte
`office_ai_url`/`office_ai_model` per `INSERT IGNORE` ein. Im Container heißt
der Endpunkt `http://ki:8080/v1`; in `APP_ENV=production` würde HTTP vom
Transport abgewiesen.

## 16. Signaturvorlagen

```
Admin: /admin/office/signaturen[/vorlage|/loeschen|/vorschau]
          └─ Admin\OrvantaSignatureController ─► OrvantaSignatureService ─► OrvantaSignatureRepository (orvanta_signatures)

Seite:  OrvantaController::index() → $orvanta['signature'] = OrvantaSignatureService::forUser(user)
          forUser(): match(user.groups) → person(user.id → phonebook.findActiveById) → render()
Editor: renderComposeSignature() fügt den Block schreibgeschützt ein; guardComposeSignature() schützt ihn
Senden: OrvantaApiController::withSignature(mail, access) → OrvantaSignatureService::append(body, forUser().html)
          → OrvantaExchangeService (stripMarkers → MailHtmlSanitizer) → EWS
```

- `render()` erzeugt E-Mail-taugliches HTML: `<div class="ov-signature-block">`
  mit optionalem Grußformel-Absatz und einer Tabelle (links Logo als
  `data:`-URI; rechts Name fett (`formatName()`: `first_last` → „Vorname
  Nachname“, `last_first` → „Nachname, Vorname“ aus `phonebook.first_name`/
  `last_name`; fehlt einer davon → `display_name`), Position, Abteilung, Adresse, Rufnummer).
  Das Logo erhält feste `width`/`height`-Attribute (Outlook ignoriert
  `max-width`/`height:auto`): Höhe = Zeilenzahl × `LINE_HEIGHT` (18 px; Text
  mit `line-height` in px, `mso-line-height-rule:exactly`, `white-space:nowrap`),
  Breite proportional aus `imageDimensions()` (Raster per
  `getimagesizefromstring`, SVG per `width`/`height` bzw. `viewBox`), höchstens
  `LOGO_MAX_WIDTH` 240 px; ohne ermittelbare Maße nur `height`. Trennzeichen
  `&#9632;` in `separator_color`, Text in `text_color` (Schlüssel aus
  `SettingsService::THEME_COLORS`, Werte aus `SettingsService::theme()`,
  geprüfte Hex-Werte).
  Leere Bestandteile entfallen samt Trennzeichen.
- Rufnummer: `prefix` → `rtrim(phone_prefix) . ' <b>' . extension(phone) . '</b>'`
  (letzte `EXTENSION_LENGTH` Ziffern der AD-Rufnummer); `full` → `T.: ` +
  AD-Rufnummer; keine AD-Rufnummer → keine Zeile.
- `append()` entfernt vorhandene Marker-Blöcke (`strip()`), fügt die Signatur
  vor dem ersten `div.ov-quote` ein, sonst am Ende; leere Signatur → Body
  unverändert. `withSignature()` greift nur bei `html === true`.
- Person-Fallback: ohne Telefonbuchzeile (z. B. `SSO_FAKE_USER`, `id = 0`)
  nur `display_name`/`username`, keine Position/Abteilung/Rufnummer.
- Logo-Loader ist eine Closure (`Container::orvantaSignatures()` →
  `LogoService::current()`); SVG-Logos werden eingebettet, einige Mail-Clients
  zeigen SVG jedoch nicht an – PNG/JPEG bevorzugen.
