# Platzhalter fuer Office-Secrets

Leere Platzhalterdateien, damit `docker compose` auch ohne eingerichtetes
Office startet. `scripts/office-setup.sh` erzeugt die echten Secrets unter
`./secrets/` (nicht versioniert) und setzt `OFFICE_SECRETS_DIR=./secrets`
in der `.env`. Hier niemals echte Werte eintragen.
