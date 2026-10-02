#!/bin/sh
# Laedt Apache neu, sobald sich die Adresse eines Backend-Containers aendert.
#
#   backend-watch.sh HOST [HOST ...]
#
# mod_proxy loest die Container-Hostnamen (app, nextcloud, eurooffice) je
# Apache-Prozess nur einmal auf und behaelt die Adresse bis zum Neuladen -
# auch mit disablereuse/addressttl. Wird ein Container neu erstellt (z. B.
# "docker compose up -d" nach einem Update), erhaelt er oft eine neue Adresse;
# die alte kann dann an einen anderen Container vergeben sein. Anfragen an
# /office/ landeten so z. B. bei der Intranet-Anwendung ("Seite nicht
# gefunden"). Dieses Skript prueft die Adressen alle BACKEND_WATCH_INTERVAL
# Sekunden (Standard 10) und fuehrt bei einer Aenderung "apache2ctl graceful"
# aus (laufende Anfragen werden zu Ende bedient).
set -u

INTERVAL="${BACKEND_WATCH_INTERVAL:-10}"
STATE_DIR="$(mktemp -d)"

log() {
    echo "[auth] $*"
}

resolve() {
    getent ahostsv4 "$1" 2>/dev/null | awk 'NR == 1 { print $1 }'
}

apache_running() {
    pid_file="${APACHE_PID_FILE:-/var/run/apache2/apache2.pid}"
    [ -s "$pid_file" ] && kill -0 "$(cat "$pid_file")" 2>/dev/null
}

[ "$#" -gt 0 ] || { echo "Aufruf: $0 HOST [HOST ...]" >&2; exit 1; }

for host in "$@"; do
    resolve "$host" > "${STATE_DIR}/${host}"
done

while :; do
    sleep "$INTERVAL"
    changed=""
    for host in "$@"; do
        addr="$(resolve "$host")"
        # Nicht aufloesbar (Container gestoppt): letzte Adresse behalten.
        [ -n "$addr" ] || continue
        last="$(cat "${STATE_DIR}/${host}" 2>/dev/null || true)"
        if [ "$addr" != "$last" ]; then
            printf '%s\n' "$addr" > "${STATE_DIR}/${host}"
            [ -n "$last" ] && changed="${changed} ${host} (${last} -> ${addr})"
            # Erstmals aufloesbar: Apache kennt die Adresse noch nicht.
        fi
    done
    if [ -n "$changed" ] && apache_running; then
        if apache2ctl graceful >/dev/null 2>&1; then
            log "Backend-Adresse geaendert:${changed} - Apache neu geladen."
        else
            echo "[auth] WARNUNG: Apache konnte nach Adressaenderung nicht neu geladen werden:${changed}" >&2
        fi
    fi
done
