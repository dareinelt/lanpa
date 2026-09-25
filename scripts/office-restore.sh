#!/bin/sh
# Stellt eine Sicherung der Office-Erweiterung wieder her:
#
#   ./scripts/office-restore.sh                       verfuegbare Sicherungen
#   ./scripts/office-restore.sh office-20260101-020000.tar.enc [--with-intranet]
#
# Nextcloud, nextcloud-cron und eurooffice werden dafuer gestoppt und danach
# wieder gestartet. --with-intranet stellt zusaetzlich Datenbank und Dateien
# der Intranet-Anwendung wieder her.
set -eu
cd "$(cd "$(dirname "$0")/.." && pwd)"

if [ $# -eq 0 ]; then
    docker compose exec -T office-backup office-backup list
    exit 0
fi

name="$1"; shift
extra="${1:-}"
printf 'Sicherung %s wiederherstellen? Aktuelle Office-Daten werden ueberschrieben. [j/N] ' "$name"
read -r answer
case "$answer" in j|J|ja|Ja) ;; *) echo "Abgebrochen."; exit 1 ;; esac

docker compose stop nextcloud nextcloud-cron eurooffice
[ "$extra" = "--with-intranet" ] && docker compose stop app sync
docker compose exec -T office-backup office-backup restore "$name" $extra
docker compose up -d
echo "Wiederherstellung abgeschlossen. Nextcloud fuehrt beim Start ggf. Reparaturschritte aus."
