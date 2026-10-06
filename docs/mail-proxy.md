# SMTP-/IMAP-Proxy für Orvanta (Benutzer ohne Exchange-Postfach)

Orvanta spricht Exchange standardmäßig per EWS an (siehe [orvanta.md](orvanta.md)).
Benutzer ohne Exchange-Postfach – etwa aus einer Identitätsquelle ohne eigenen
Exchange-Server – erhalten über den SMTP-/IMAP-Proxy ein Postfach auf einem
beliebigen IMAP-/SMTP-Mailserver. Die Oberfläche von Orvanta bleibt gleich,
nur die Funktionen ohne IMAP-Entsprechung werden ausgeblendet.

**Exchange bleibt der Standard und die Rückfallebene:** Wer keine gültige
Zuordnung hat, nutzt Orvanta unverändert über Exchange.

---

## 1. Überblick

```
OrvantaController/ApiController ─► OrvantaMailRouter ─┬─► OrvantaExchangeService (EWS, Standard)
                                                      └─► ProxyMailBackend ─► HttpMailProxyTransport
                                                                                │ HMAC-signiert, intern
                                                                                ▼
                                                                          mail-proxy (Python)
                                                                           ├─ IMAP (Pool je Postfach)
                                                                           └─ SMTP
```

- Je **Identitätsquelle** (Hauptquelle `0` oder Eintrag aus `identity_sources`)
  gibt es höchstens **einen Mailserver** (SMTP + IMAP).
- Je Mailserver beliebig viele **Postfächer** (Anmeldename, Adresse, Passwort).
- Ein **AD-Benutzer** (`phonebook.id`) wird höchstens **einem Postfach** derselben
  Identitätsquelle zugeordnet; ein Postfach gehört höchstens einem Benutzer.
- Der Container **`mail-proxy`** (nur Python-Standardbibliothek) führt die
  IMAP-/SMTP-Operationen aus. Er hat keinen Datenbankzugriff, speichert keine
  Zugangsdaten und ist nur im internen Netz `mail_proxy` erreichbar.

## 2. Einrichtung

1. `php scripts/migrate.php` (Migration `039_mail_proxy.sql`).
2. `docker compose up -d --build` – startet den Dienst `mail-proxy` und hängt
   `app` zusätzlich an das Netz `mail_proxy`. Voraussetzung: Docker Engine ≥ 26
   bzw. Compose ≥ 2.24 (Volume-`subpath` für den Schlüssel).
3. Adminbereich → **Office → SMTP-/IMAP-Proxy** (`/admin/office/mail-proxy`):
   1. **Proxy-Konfiguration je Identitätsquelle:** Quelle wählen, SMTP- und
      IMAP-Server eintragen, speichern.
   2. **Postfächer:** Anmeldename, E-Mail-Adresse, Anzeigename und Passwort.
      „Verbindung testen“ prüft SMTP und IMAP (Verbindung, TLS, Anmeldung) –
      **ohne** eine Mail zu versenden.
   3. **Zuordnung AD-Benutzer → Postfach:** beide Felder mit
      Autovervollständigung; vorgeschlagen werden nur aktive Benutzer und noch
      freie, aktive Postfächer der gewählten Identitätsquelle.
4. Die Orvanta-App muss für den Benutzer wie gewohnt freigegeben sein
   (Office → Office-Apps). Orvanta ist auch ohne Exchange-Einstellungen
   verfügbar, sobald ein aktiver Proxy-Mailserver existiert.

### Mailserver-Felder

| Feld | Werte / Regeln |
| --- | --- |
| SMTP-Host, IMAP-Host | Vollqualifizierter Name oder IP-Adresse. **Nicht** erlaubt: Loopback, Link-Local (u. a. `169.254.169.254`), `0.0.0.0`/`::`, Multicast, einteilige Namen (Docker-Dienste wie `db`), `localhost`/`*.localhost`. Private Netze sind erlaubt. |
| SMTP-Port | 25, 465, 587, 2525 |
| SMTP-Verschlüsselung | `starttls`, `tls`, `none` (`none` nur ohne SMTP-Anmeldung) |
| IMAP-Port | 143, 993 |
| IMAP-Verschlüsselung | `tls` oder `starttls` (unverschlüsseltes IMAP ist nicht möglich) |
| TLS-Zertifikat prüfen | Standard an, mindestens TLS 1.2. Abschalten nur pro Server; die Oberfläche zeigt dann ein Warn-Badge. |
| Zeitlimit | 5–60 Sekunden je Verbindungsschritt |
| Aktiv | deaktiviert → alle Benutzer dieser Quelle nutzen wieder Exchange |

Die Identitätsquelle eines Servers ist nach dem Anlegen fest. Ein Server mit
Postfächern lässt sich nicht löschen (vorher Postfächer löschen oder
deaktivieren).

### Postfach-Felder

- **Passwort** wird ausschließlich als SecretBox-Chiffrat (`enc:v1:…`) gespeichert
  und nie wieder angezeigt. Leeres Feld beim Bearbeiten = unverändert.
  Zeilenumbrüche sind nicht erlaubt, höchstens 4096 Zeichen. Ändert der
  Benutzer sein Kennwort am Mailserver, kann er das neue in Orvanta eingeben;
  es ersetzt nach erfolgreicher Anmeldung den hier eingetragenen Wert
  (Abschnitt 5a).
- **E-Mail-Adresse** wird kleingeschrieben gespeichert und ist je Server eindeutig.
  Sie ist gleichzeitig die Absenderadresse; Benutzer können keinen anderen
  Absender wählen.

## 3. Welches Backend nutzt ein Benutzer?

`MailProxyResolver` entscheidet beim Öffnen von Orvanta:

| Zustand | Ergebnis |
| --- | --- |
| keine Zuordnung, Benutzer ohne Telefonlisten-Eintrag | Exchange (unverändert) |
| Benutzer inaktiv (z. B. per AD-Sync deaktiviert) | Exchange |
| Identitätsquelle gelöscht oder deaktiviert | Exchange |
| Mailserver deaktiviert | Exchange |
| Quellen von Zuordnung, Benutzer und Server passen nicht zusammen | Exchange |
| **Postfach deaktiviert** | **gesperrt** (HTTP 403): keine Verbindung, **kein** Rückfall auf Exchange |
| sonst | Proxy-Postfach |

Fehlen die Tabellen (Migration nicht ausgeführt) oder ist die Datenbank nicht
erreichbar, bleibt es beim Exchange-Verhalten.

## 4. Funktionsumfang über den Proxy

| Verfügbar | Nicht verfügbar |
| --- | --- |
| Mail lesen (HTML bereinigt wie bei EWS), Kopfzeilen | Kalender, Termine, Besprechungsanfragen |
| Senden, Antworten, Allen antworten, Weiterleiten (mit Anhängen) | Kontakte |
| Entwürfe speichern und aus Entwürfen senden | Aufgaben, Notizen |
| Ordner (inkl. Anlegen, mUTF-7), Suche (auch Umlaute) | Terminerinnerungen |
| Gelesen/Ungelesen, Kennzeichnung, Verschieben, Löschen (Papierkorb/endgültig) | Langzeitarchiv (Worker benötigt EWS) |
| Anhänge öffnen/speichern (Euro-Office, Browser, Download, Nextcloud) | |
| Kontingent (IMAP `QUOTA`, sonst Angaben aus dem Verzeichnis) | |

Nicht verfügbare Funktionen sind per Capability-Flag in der Oberfläche
ausgeblendet; die API antwortet darauf mit **409**. Gesendete Mails legt der
Proxy per IMAP `APPEND` im Gesendet-Ordner ab.

Systemordner werden über SPECIAL-USE (RFC 6154) erkannt, sonst über übliche
deutsche und englische Namen (`Sent`, `Gesendete Elemente`, `Trash`,
`Papierkorb`, `Drafts`, `Entwürfe`, `Junk`, `Spam` …).

## 5. Sicherheit

- **Zugangsdaten:** nur verschlüsselt in `mail_proxy_mailboxes.password_encrypted`.
  PHP entschlüsselt sie pro Anfrage (`MailProxyResolver::account()`) und prüft
  dabei erneut Aktiv-Status und Zuordnung. `MailProxyAccount` verhindert
  Ausgabe per `print_r`/`var_dump`/JSON und `serialize()`. Listen, Vorschläge,
  Diagnose und Logs enthalten nie Passwort oder Chiffrat.
- **Anfragen an den Proxy:** HMAC-SHA256 über
  `"v1\n<Zeitstempel>\n<Nonce>\n<Operation>\n<sha256(Body)>"`. Schlüssel:
  `SecretBox::deriveKey('mail-proxy')` – der Proxy liest dafür nur
  `storage/keys/secrets.key` schreibgeschützt (Volume-`subpath`, UID 33).
  Zeitfenster ±60 s, Nonce-Replay-Schutz, begrenzte Body-Größe. Kein weiteres
  Geheimnis in `.env` oder im Image.
- **Postfach-Bindung:** Nachrichten-IDs haben die Form
  `mpx.<mailbox_id>.<ordner>.<uidvalidity>.<uid>` (Anhänge zusätzlich
  `.<index>`). IDs eines anderen Postfachs werden mit 403 abgelehnt, bevor eine
  Verbindung aufgebaut wird; ebenso Anfragen für eine andere Postfachadresse.
- **SSRF-Schutz in zwei Stufen:** PHP prüft Hostnamen und Ports beim Speichern
  (`MailProxyService::isAllowedHost()`); der Proxy löst den Namen bei jeder
  Verbindung erneut auf, sperrt dieselben Bereiche plus `MAIL_PROXY_DENY_CIDRS`
  und verbindet sich direkt auf die geprüfte IP (kein DNS-Rebinding).
- **Container:** schreibgeschütztes Dateisystem, `cap_drop: ALL`,
  `no-new-privileges`, keine Portfreigabe, keine Datenbank; Netze `mail_proxy`
  (intern, nur `app`) und `mail_egress` (ausgehend zu den Mailservern).
- **Protokolle:** strukturierte JSON-Zeilen auf stdout; Felder mit
  `pass|secret|token|authorization|signature|credential` werden maskiert.

## 5a. Geändertes Kennwort (Abfrage in Orvanta)

Ändert ein Benutzer das Kennwort seines Postfachs direkt am Mailserver,
schlägt Orvanta nicht hart fehl:

1. Lehnt der Mailserver die Anmeldung ab (Proxy-Code `auth_failed`), antwortet
   die Orvanta-API mit **409** und `{"error": "…", "code": "mail_auth"}`.
2. `orvanta.js` öffnet daraufhin das Overlay „Kennwort des Postfachs“ und fragt
   das aktuelle Kennwort ab. Gleichzeitige Anfragen teilen sich eine Abfrage.
3. `POST /api/orvanta/mail/kennwort` (CSRF, nur für Proxy-Postfächer, nur das
   eigene zugeordnete und aktive Postfach) prüft das Kennwort mit
   `mailbox.test` (IMAP- und ggf. SMTP-Anmeldung, **kein** Mailversand).
4. Nur wenn der Mailserver die Anmeldung bestätigt, wird das Kennwort
   verschlüsselt gespeichert und **ersetzt den vom Admin eingetragenen Wert**.
   Danach wiederholt Orvanta die ursprüngliche Anfrage einmal.
5. Ein abgelehntes Kennwort (422) wird nicht gespeichert; nach 5 Fehlversuchen
   je Sitzung und Postfach ist die Eingabe 5 Minuten gesperrt (429). Ist der
   Mailserver nicht erreichbar, bleibt das gespeicherte Kennwort unverändert.

Abbrechen schließt das Overlay; die auslösende Aktion zeigt dann die normale
Fehlermeldung, die nächste Aktion fragt erneut. Das Protokoll vermerkt die
Übernahme (`mail-proxy password updated by user`, nur Postfach-ID) – nie das
Kennwort.

## 6. Cache und Invalidierung

- Die aufgelöste Zuordnung (nur Kennungen und Zustand, nie Zugangsdaten) wird
  in `storage/cache/mail-proxy/` zwischengespeichert, TTL `MAIL_PROXY_CACHE_TTL`
  (Standard 300 s, `0` = aus, max. 3600).
- Jeder Eintrag ist an `mail_proxy_state.generation` gebunden. Jede Änderung im
  Adminbereich erhöht die Generation, leert das Cache-Verzeichnis und den
  IMAP-Pool des Proxys (`cache.invalidate`). Dasselbe passiert bei Änderung,
  Deaktivierung oder Löschung einer Identitätsquelle (`LdapController`) und
  wenn der AD-Sync Benutzer deaktiviert (`AdSyncService`).
- Unabhängig vom Cache werden beim Verbindungsaufbau Zugangsdaten frisch
  gelesen und Aktiv-Status sowie Zuordnung erneut geprüft; Abweichungen führen
  zu 403/409 statt zu einer Verbindung mit veralteten Daten.
- Der IMAP-Pool des Proxys ist über einen Fingerprint aus Generation und
  Verbindungsdaten (inkl. Passwort) gebunden; nach einer Änderung wird keine
  alte Verbindung weiterverwendet.

## 7. Konfiguration (`.env`, optional)

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `MAIL_PROXY_URL` | `http://mail-proxy:8025` | Interne Adresse des Proxys (nur `http(s)://host[:port]`, ohne Zugangsdaten/Query) |
| `MAIL_PROXY_CACHE_TTL` | `300` | Gültigkeit des Zuordnungs-Caches in Sekunden |
| `MAIL_PROXY_MAX_CONNECTIONS` | `32` | Gleichzeitige Anfragen im Proxy |
| `MAIL_PROXY_POOL_SIZE` | `32` | Gepoolte IMAP-Verbindungen |
| `MAIL_PROXY_POOL_IDLE_SECONDS` | `90` | Leerlaufzeit gepoolter Verbindungen |
| `MAIL_PROXY_MAX_MESSAGE_MB` | `35` | Maximale Größe einer gesendeten/geladenen Nachricht |
| `MAIL_PROXY_DENY_CIDRS` | leer | Zusätzlich gesperrte Netze (kommagetrennt), z. B. Docker-Netze interner Dienste |

Postfach-Zugangsdaten gehören **nicht** in die `.env`.

## 8. Diagnose und Fehlersuche

Office → **Status & Diagnose** zeigt eine Karte „SMTP-/IMAP-Proxy“:
Konfigurationsstatus, Erreichbarkeit/Version/Verbindungen des Dienstes, Anzahl
der Server, Postfächer und Zuordnungen, Cache-TTL, letzter Erfolg und letzter
Fehler (ohne Geheimnisse).

| Meldung | Ursache / Abhilfe |
| --- | --- |
| „Der Mail-Proxy-Dienst ist nicht erreichbar.“ (503) | Container `mail-proxy` läuft nicht oder `app` hängt nicht im Netz `mail_proxy`: `docker compose ps mail-proxy`, `docker compose logs mail-proxy` |
| „Der Mail-Proxy hat die Anfrage abgelehnt (Schlüssel prüfen).“ | Schlüsseldatei nicht lesbar/abweichend oder Uhrzeit von `app` und `mail-proxy` weicht > 60 s ab |
| „Die Anmeldung am Postfach wurde abgelehnt. Möglicherweise wurde das Kennwort geändert.“ | Orvanta fragt den Benutzer in einem Overlay nach dem aktuellen Kennwort (Abschnitt 5a); im Adminbereich Benutzername/Passwort prüfen (Verbindungstest) |
| „Die TLS-Verbindung zum Mailserver ist fehlgeschlagen.“ | Zertifikat, Hostname oder TLS-Modus/Port prüfen |
| „Der konfigurierte Mailserver ist als Ziel nicht zulässig.“ | Host löst auf eine gesperrte Adresse auf (Loopback, Link-Local, `MAIL_PROXY_DENY_CIDRS`) |
| Orvanta zeigt „Das zugeordnete Postfach ist deaktiviert.“ | Postfach im Adminbereich aktivieren oder Zuordnung löschen |
| Benutzer landet weiter bei Exchange | Zuordnung fehlt, Benutzer/Quelle/Server inaktiv – siehe Tabelle in Abschnitt 3 |

## 9. Sicherung

Die Proxy-Tabellen sind bewusst **nicht** im Export der Intranet-Sicherung
(`BackupService` exportiert nur bekannte Tabellen), damit keine Chiffrate
mitwandern. Nach einer Wiederherstellung auf einem anderen System sind
Mailserver und Postfächer neu anzulegen.

## 10. Technische Referenz (für Entwickler)

### Code-Landkarte

| Datei | Aufgabe |
| --- | --- |
| `database/migrations/039_mail_proxy.sql` | Tabellen `mail_proxy_servers`, `mail_proxy_mailboxes`, `mail_proxy_mappings`, `mail_proxy_state` |
| `app/Repositories/MailProxyRepository.php` | SQL (auch in SQLite lauffähig); Chiffrat nur über `mailboxSecret()`/`connectionRow()` |
| `app/Services/MailProxy/MailProxyService.php` | Admin-Logik: Validierung inkl. SSRF-Prüfung, CRUD, Vorschläge, Verbindungstest, Diagnose, `invalidate()` |
| `app/Services/MailProxy/MailProxyResolver.php` | Entscheidung Exchange/Proxy/gesperrt (`decide()` rein), frische Zugangsdaten (`account()`) |
| `app/Services/MailProxy/MailProxyRoute.php`, `MailProxyAccount.php` | Wertobjekte (Route ohne Geheimnisse, Account nicht serialisierbar) |
| `app/Services/MailProxy/MailProxyCache.php` | Dateicache mit TTL und Generation |
| `app/Services/MailProxy/OrvantaMailRouter.php` | Backend-Auswahl je Benutzer bzw. für Anhang-Tokens (`backendForUid()`) |
| `app/Services/MailProxy/ProxyMailBackend.php` | `OrvantaMailBackendInterface` über den Proxy; ID-Format, Postfach-Bindung, Abbildung auf die Orvanta-API-Formate |
| `app/Services/MailProxy/HttpMailProxyTransport.php` | HTTP + HMAC zum Container, Fehlercodes → deutsche Meldungen |
| `app/Contracts/OrvantaMailBackendInterface.php` | Gemeinsames Interface von `OrvantaExchangeService` und `ProxyMailBackend`, Capability-Konstanten |
| `app/Contracts/MailProxyTransportInterface.php` | Austauschbarer Transport (Tests: `FakeMailProxyTransport`) |
| `app/Controllers/Admin/MailProxyController.php`, `views/admin/mail-proxy.php`, `public/assets/js/admin-mail-proxy.js` | Adminseite inkl. Autovervollständigung |
| `docker/mail-proxy/mail_proxy.py`, `Dockerfile` | Proxy-Dienst |

### Routen (alle `$requireAdmin`, POST mit CSRF)

| Methode | Pfad | Zweck |
| --- | --- | --- |
| GET | `/admin/office/mail-proxy` | Seite (`?quelle=<id>`) |
| POST | `/admin/office/mail-proxy/server`, `…/server/status`, `…/server/loeschen` | Mailserver speichern, (de)aktivieren, löschen |
| POST | `/admin/office/mail-proxy/postfach`, `…/postfach/loeschen`, `…/postfach/test` | Postfach speichern, löschen, Verbindungstest |
| POST | `/admin/office/mail-proxy/zuordnung`, `…/zuordnung/loeschen` | Zuordnung speichern, löschen |
| GET | `/admin/office/mail-proxy/users`, `…/mailboxes` | JSON-Vorschläge (gefiltert nach Quelle, ohne Zugangsdaten) |

Für Benutzer (Orvanta-Freigabe, CSRF): `POST /api/orvanta/mail/kennwort`
(`OrvantaApiController::mailPassword()` → `MailProxyService::updateUserPassword()`),
siehe Abschnitt 5a.

### Proxy-Protokoll

- `POST /v1/<operation>` mit JSON-Body `{"account": {...}, ...}` und Headern
  `X-Mail-Proxy-Timestamp`, `X-Mail-Proxy-Nonce` (32 Hex), `X-Mail-Proxy-Signature`.
- Antwort `{"ok": true, "data": {...}}` bzw. `{"ok": false, "error": {"code", "message"}}`;
  Codes: `unreachable`, `tls`, `auth_failed`, `timeout`, `not_found`, `invalid`,
  `busy`, `forbidden_target`, `unauthorized`, `smtp_rejected`, `too_large`.
  `auth_failed` wird in PHP zu 409 mit `OrvantaException::reason()` =
  `mail_auth`; fehlgeschlagene Prüfschritte von `mailbox.test` tragen den Code
  im Feld `code`.
- Operationen: `imap.folders`, `imap.create_folder`, `imap.mark_folder_read`,
  `imap.folder_status`, `imap.messages`, `imap.message`, `imap.headers`,
  `imap.attachment`, `imap.flags`, `imap.move`, `imap.delete`, `imap.quota`,
  `imap.save_draft`, `smtp.send`, `mailbox.test`, `cache.invalidate`.
- `GET /health` (ohne Signatur): Status, Version, Verbindungen, Pool, Zähler.

### Invarianten

- Exchange-Verhalten für Benutzer ohne gültige Zuordnung bleibt unverändert.
- Deaktiviertes Postfach → nie ein Rückfall auf Exchange oder ein anderes Postfach.
- Kein Passwort/Chiffrat in Views, JSON, Cache-Dateien oder Logs.
- Absender ist immer das gebundene Postfach (vom Proxy erzwungen).
- Signaturformat in PHP (`HttpMailProxyTransport::signature()`) und Python
  (`signature()`) identisch halten – der Test vergleicht einen festen Referenzwert.

### Tests

`tests/Unit/MailProxyTest.php` (SQLite-Schema spiegelt Migration 039,
`FakeMailProxyTransport` statt Netzwerk): Hostprüfung/SSRF, Servervalidierung,
Verschlüsselung und geheimnisfreie Listen, Zuordnungsregeln, Vorschläge,
Entscheidungstabelle, Cache/Generation, frische Zugangsdaten, Router inkl.
Sperre ohne Rückfall, Postfach-Bindung der IDs, Signatur-Referenzwert,
Verbindungstest und Diagnose, Kennwort-Übernahme durch den Benutzer (nur nach
bestätigter Anmeldung) und Grund `mail_auth`. Ende-zu-Ende-Tests gegen einen echten Mailserver
(z. B. GreenMail) sind manuell durchzuführen.
