#!/bin/bash
# storage-sync: startet Monitor, Synchronisation und Rueckholung. Endet einer
# der Prozesse, beendet sich der Container (restart: unless-stopped startet
# ihn neu) - so bleibt kein Teil unbemerkt ausgefallen.
set -euo pipefail

if [[ $# -gt 0 ]]; then
    # Einzelbefehl, z. B. restore: docker compose exec storage-sync ... oder run
    exec php /var/www/html/scripts/storage_sync.php "$@"
fi

mkdir -p /var/lib/storage-sync /var/lib/lanpa-tiering /mnt/targets
install -d -m 0700 /run/storage-sync

# Ohne Datenbank kein Start (Einstellungen, Ziele, Status).
for _ in $(seq 1 60); do
    php -r 'require "/var/www/html/bootstrap.php"; App\Core\Database::connection(); exit(0);' >/dev/null 2>&1 && break
    sleep 2
done

pids=()
for role in monitor sync recall; do
    php /var/www/html/scripts/storage_sync.php "$role" &
    pids+=($!)
done

stop() {
    kill -TERM "${pids[@]}" 2>/dev/null || true
    wait || true
    # Einbindungen sauber loesen
    for mp in /mnt/targets/*; do
        mountpoint -q "$mp" && umount -l "$mp" || true
    done
}
trap 'stop; exit 0' TERM INT

set +e
wait -n
status=$?
echo "storage-sync: ein Teilprozess wurde beendet (Status ${status}) - Neustart." >&2
stop
exit 1
