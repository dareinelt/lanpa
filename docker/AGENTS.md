# AGENTS.md – docker/

Ergänzt die Wurzel-`AGENTS.md`.

- Je Unterordner ein Container-Image (`auth`, `mail-archive`, `nextcloud`, `snmp`, `storage-sync`, …); Änderungen nur im betroffenen Container, Rollen und Netze in `docs/storage-stack.md`/`docs/office.md` beachten.
- Dienste, Volumes und Netze werden in `docker-compose*.yml` im Wurzelverzeichnis definiert; neue Variablen zusätzlich in `.env.example` (nur Platzhalter) und Doku aufnehmen.
- Keine Secrets in Images, Skripten oder Compose-Dateien; Zugangsdaten laufen über `.env`/`scripts/credentials.php`.
- Keine externen Downloads/CDNs ohne ausdrückliche Begründung; Zero-Dependency-Prinzip des Projekts beachten.
- Container lassen sich in der Agentenumgebung meist nicht starten: Syntax-/Skriptprüfung statt Laufzeittest, und das in der PR vermerken.
