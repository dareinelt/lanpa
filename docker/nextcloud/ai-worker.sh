#!/bin/sh
# Fuehrt KI-Aufgaben (Assistant, integration_openai) von Nextcloud zeitnah
# aus. Ohne diesen Worker laufen sie nur alle 5 Minuten ueber cron.php.
# Kurze Laufzeit je Durchgang, damit neu aktivierte Apps (KI-Anbieter)
# schnell geladen werden.
set -u

cd /var/www/html || exit 1

log() { printf '[ai-worker] %s\n' "$*"; }

ready() {
    status=$(php occ status --output=json 2>/dev/null) || return 1
    printf '%s' "$status" | grep -q '"installed":true' \
        && ! printf '%s' "$status" | grep -q '"needsDbUpgrade":true' \
        && ! printf '%s' "$status" | grep -q '"maintenance":true'
}

trap 'exit 0' TERM INT

while :; do
    if ! ready; then
        sleep 30 &
        wait $!
        continue
    fi
    php occ --no-interaction background-job:worker -t 60 'OC\TaskProcessing\SynchronousBackgroundJob' \
        || { log "Worker beendet (Status $?), Neustart in 10 s."; sleep 10 & wait $!; }
done
