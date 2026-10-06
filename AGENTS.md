# AGENTS.md – Globale Regeln für Coding-Agenten

Dieses Repository ist die **Intranet-Landingpage** (PHP 8.4+/8.5, MySQL, Vanilla-JS/CSS, Docker Compose) – bewusst **ohne Frameworks, Composer, npm oder CDNs**. Router, Container, View, Migrator und Testrunner sind selbst geschrieben.

Ausführlicher Kontext steht in [`agentsindex.md`](agentsindex.md) (Architektur, Routen, Schema, Workflows) und `README.md`. Diese Datei dupliziert das nicht, sondern legt Verhaltensregeln fest. Bereichsspezifische Regeln liegen in tieferen `AGENTS.md`-Dateien (`tests/`, `database/migrations/`, `docker/`); die jeweils nähere Datei ergänzt oder präzisiert diese hier.

## Rangfolge

1. Explizite Aufgabe des Nutzers.
2. Nächstgelegene `AGENTS.md` → diese Datei → `agentsindex.md` (Abschnitte 13/14: Konventionen, Änderungsaufgaben).
3. Generische Best Practices. Bei Konflikt gilt immer die projektspezifische Regel.

## Projektüberblick (Kurzfassung)

- Request-Fluss: `public/index.php` → `bootstrap.php` → `Router` → Controller (`app/Controllers/`) → Service (`app/Services/`) → Repository (`app/Repositories/`) → PDO (`app/Core/Database.php`).
- Weitere Verzeichnisse: `app/Contracts` (Interfaces), `app/Security`, `config/`, `views/`, `public/assets/` (JS/CSS), `database/migrations/`, `scripts/` (CLI), `docker/` (Container-Images), `docs/`, `tests/`.
- Größere Module mit eigener Referenz: Orvanta (Mail/Kalender, EWS; SMTP-/IMAP-Proxy `mail-proxy` für Benutzer ohne Exchange), Speicher-Tiering (`storage-sync`), Notfallplan-Editor/KAEP, Office, LLMInt.

## Grundprinzipien

1. Keinen fremden Code refaktorieren.
2. Dateien nicht verschieben oder umbenennen, außer ausdrücklich verlangt.
3. Bestehendes Verhalten nur ändern, wenn die Aufgabe es verlangt.
4. Vor neuen Mustern erst vorhandene Muster ansehen (ähnlichen Controller/Service/Repository/Test lesen) und diesen folgen.
5. Minimale, fokussierte Änderungen; nur Dateien anfassen, die die Aufgabe braucht.
6. Keine neuen Abhängigkeiten (Composer/npm/CDN/externe Fonts/Icons). Die Zero-Dependency-Regel ist Projektprinzip.
7. Nach Änderungen die relevanten vorhandenen Tests und Prüfungen ausführen (siehe unten).
8. Nie Secrets, Zugangsdaten, `.env`, generierte Artefakte oder lokalen Zustand committen (z. B. `install-reports/`, `backups/`, Laufzeitdaten in `storage/`). `.env.example` enthält nur Platzhalter.
9. Projektkonventionen nicht überschreiben, nur weil eine andere bevorzugt würde.
10. Bei Unsicherheit konservativ vorgehen und Annahmen in der Antwort/PR nennen.

Zusätzlich: Zuerst Code und zugehörige Doku verstehen, dann ändern. Keine großflächigen Änderungen ohne Notwendigkeit.

## Coding-Konventionen

- `declare(strict_types=1);` in jeder PHP-Datei; Namespace `App\…`; Klassen `final`; Abhängigkeiten `private readonly` per Konstruktor.
- Docblocks mit `@param`/`@return` (z. B. `list<array<string,mixed>>`).
- **UI-Texte und Kommentare auf Deutsch**, Code-Bezeichner auf Englisch.
- Ausgabe in Views immer über `Html::e()`; SQL nur mit Prepared Statements, keine String-Konkatenation von Werten.
- Neue Funktionen folgen **Route → Controller → Service → Repository**; Routen in `public/index.php` in der passenden Middleware-Gruppe (`$requireAuth`/`$requireAdmin`); Formulare mit CSRF-Prüfung.
- Neue Einstellungen über `SettingsService`/`settings`-Tabelle (Booleans als `'1'`/`'0'`, Defaults in `defaults()`), ggf. `.env.example` und Doku ergänzen.
- Frontend: handgeschriebenes JS/CSS in `public/assets/`; CSP-Nonce beachten, keine Inline-Handler ohne Nonce.
- Admin-Navigation: `$navItems` in `views/layouts/admin.php`; Controller übergeben passenden `activeNav`-Schlüssel.
- Schnittstellen zu externen Systemen (LDAP, Exchange, KI, Archiv) laufen über `app/Contracts/*`; Tests nutzen Demo-/Fake-Implementierungen.

## Dokumentation

- Bei Verhaltensänderungen betroffene Doku mitpflegen: `agentsindex.md` (Tabellen/Routen/Schema), Modul-Doku (`docs/orvanta.md` + `docs/orvanta-referenz.md` + `docs/mail-proxy.md`, `docs/storage.md` + `docs/storage-referenz.md`, `docs/notfallplan.md` + `docs/notfallplan-editor-referenz.md`, `docs/office.md`, `docs/llmint.md`).
- Referenzdokumente (`*-referenz.md`) vor Änderungen am jeweiligen Modul lesen.
- Neue Doku nur anlegen, wenn nötig; keine Duplikate von Code.

## Validierung

Nur vorhandene Werkzeuge verwenden:

```bash
php tests/run.php                                        # Testsuite (eigener Runner, kein PHPUnit)
find . -name "*.php" -print0 | xargs -0 -n1 php -l       # Syntaxprüfung
php scripts/migrate.php                                  # Migrationen (benötigt DB)
```

Es gibt keinen konfigurierten Linter, Formatter oder Typechecker – keinen hinzufügen. Docker-abhängige Teile (`docker compose`) lassen sich oft nicht in der Agentenumgebung testen; dann in der Beschreibung vermerken.

## Git

- Kleine, thematisch fokussierte Commits mit kurzer, beschreibender Betreffzeile (Deutsch, Imperativ/Beschreibung, z. B. „Admin-Navigation: Office als aufklappbarer Punkt mit Untermenü“). Änderungen laufen über Pull Requests (Branch-Präfix `copilot/…` oder `<nutzer>/…` wird vorgefunden, kein striktes Schema).
- PR-Beschreibung nach `.github/pull_request_template.md` (Warum / Was / Architektur / Hinweise / Tests / Migration).
- Keine Force-Pushes oder History-Umschreibungen; bestehende Dateien nicht ohne Auftrag löschen.
- Keine generierten Dateien committen (Ausnahme: ausdrücklich versionierte Doku-Artefakte wie PDFs in `docs/`, nur auf Anforderung neu erzeugen).
