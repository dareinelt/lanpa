# AGENTS.md – tests/

Ergänzt die Wurzel-`AGENTS.md`.

- Ausführen: `php tests/run.php` (eigener Runner `tests/run.php`, **kein PHPUnit**, nichts nachinstallieren).
- Neue Tests unter `tests/Unit/` nach dem Muster vorhandener Tests; Runner-Konventionen aus `tests/run.php` übernehmen.
- Keine echte DB, kein Netzwerk: SQLite-Schemata bzw. Fakes nutzen. Die SQLite-Schemata in den Tests spiegeln Migrationen **manuell** – bei Schemaänderungen betroffene Test-Schemata anpassen.
- Orvanta/EWS-Tests laufen über `DemoExchangeTransport` (via `RecordingExchangeTransport`); neue EWS-Operationen brauchen Demo-Antworten.
- Bestehende Tests nie löschen oder abschwächen, um Fehler zu umgehen; Ursache im Code beheben.
- Fehlerfälle und Sicherheitsaspekte (CSRF, Rechte, Escaping) mit abdecken, wo das Muster es vorsieht.
