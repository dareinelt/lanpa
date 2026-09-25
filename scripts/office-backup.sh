#!/bin/sh
# Erstellt sofort eine Sicherung der Office-Erweiterung (Einzeiler):
#
#   ./scripts/office-backup.sh
#
# Die Sicherung erfolgt im Container office-backup (Wartungsmodus von
# Nextcloud waehrend der Sicherung). Ziel: ${OFFICE_BACKUP_DIR:-./backups}.
set -eu
cd "$(cd "$(dirname "$0")/.." && pwd)"
docker compose exec -T office-backup office-backup backup
docker compose exec -T office-backup office-backup list
