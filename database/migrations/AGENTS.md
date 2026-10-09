# AGENTS.md – database/migrations/

Ergänzt die Wurzel-`AGENTS.md`.

- **Vorhandene Migrationen niemals ändern, umbenennen oder löschen** (sie sind über `schema_migrations` bereits angewendet).
- Schemaänderung = neue Datei `0NN_beschreibung.sql` mit der nächsthöheren Nummer (aktuell zuletzt `049_orvanta_shared_mailboxes_source.sql`; vor dem Anlegen Verzeichnis prüfen).
- Stil der letzten Migrationen übernehmen (utf8mb4, `IF NOT EXISTS`-Muster falls dort üblich).
- Danach: `agentsindex.md` (Abschnitt Datenbankschema), betroffene Modul-Doku und manuell gepflegte SQLite-Test-Schemata in `tests/Unit/` anpassen.
- Ausführung per `php scripts/migrate.php` (benötigt DB).
