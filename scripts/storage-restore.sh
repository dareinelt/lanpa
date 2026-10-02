#!/bin/sh
# Stellt die Office-Daten (Nextcloud, Euro-Office, Nextcloud-Datenbank) aus
# einem Speicherziel des Cold-Tiers (SMB-/S3-Tier) wieder her - z. B. nach Verlust
# der VM oder des Hot-Tiers (lokales Storage). Siehe docs/storage.md.
#
#   ./scripts/storage-restore.sh                  Speicherziele anzeigen
#   ./scripts/storage-restore.sh <ID> [--full]    aus Ziel <ID> wiederherstellen
#
# Ohne --full werden nur haeufig genutzte/juengere Dateien in den Hot-Tier
# kopiert, alle anderen als Platzhalter angelegt (Rueckholung bei Zugriff).
# Mit --full wird der komplette Bestand lokal kopiert.
#
# Voraussetzung: Speicherziel im Adminbereich (Speicher (HA)) mit Zugangsdaten
# eingetragen. Auf einer neu aufgesetzten Installation wird die Kennung der
# Freigabe (.lanpa-storage.json) uebernommen.
set -eu
cd "$(cd "$(dirname "$0")/.." && pwd)"

if [ $# -eq 0 ]; then
    docker compose exec -T app php scripts/storage_status.php storage_targets || true
    echo "Aufruf: $0 <ID> [--full]"
    exit 0
fi

target="$1"; shift
full="${1:-}"
case "$target" in *[!0-9]*|'') echo "Ungueltige Ziel-ID: ${target}" >&2; exit 2 ;; esac
[ -z "$full" ] || [ "$full" = "--full" ] || { echo "Unbekannte Option: ${full}" >&2; exit 2; }

printf 'Office-Daten aus Speicherziel %s wiederherstellen? Lokale Nextcloud-/Euro-Office-Daten und die Nextcloud-Datenbank werden ueberschrieben. [j/N] ' "$target"
read -r answer
case "$answer" in j|J|ja|Ja) ;; *) echo "Abgebrochen."; exit 1 ;; esac

docker compose stop nextcloud nextcloud-cron nextcloud-ai-worker eurooffice
docker compose up -d storage-sync

# Warten, bis storage-sync das Ziel eingebunden hat (Monitor, alle 5 s).
i=0
until docker compose exec -T storage-sync test -s /var/lib/storage-sync/targets.json 2>/dev/null; do
    i=$((i + 1)); [ "$i" -lt 30 ] || { echo "storage-sync meldet keine Speicherziele." >&2; exit 3; }
    sleep 2
done
sleep 6

docker compose exec -T storage-sync php scripts/storage_sync.php restore --target="$target" --keep-paused $full

docker compose exec -T storage-sync sh -c '
    set -e
    dump=/var/lib/storage-sync/dumps/nextcloud.dump
    if [ -s "$dump" ]; then
        echo "Nextcloud-Datenbank wird aus der Sicherung des Speicherziels wiederhergestellt ..."
        PGPASSWORD="$(cat /run/secrets/nextcloud_db_password)" pg_restore -h nextcloud-db -U nextcloud -d nextcloud \
            --clean --if-exists --no-owner --role=nextcloud "$dump"
    else
        echo "Keine Datenbanksicherung auf dem Speicherziel gefunden - Nextcloud-Datenbank bleibt unveraendert." >&2
    fi'

# Erst jetzt die Synchronisation fortsetzen - sonst koennte ein veralteter
# Datenbankstand auf die Speicherziele uebertragen werden.
docker compose exec -T storage-sync php scripts/storage_sync.php resume

docker compose up -d
echo "Wiederherstellung abgeschlossen. Nach dem Start von Nextcloud den Dateibestand abgleichen:"
echo "  docker compose exec -u www-data nextcloud php occ files:scan --all"
