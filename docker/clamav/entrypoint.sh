#!/bin/sh
# Start des clamav-Containers (Malware-Protection).
#
# Ablauf:
#   1. Signaturdatenbank einmalig laden, falls das Volume noch leer ist.
#   2. freshclam als Aktualisierungs-Daemon im Hintergrund starten.
#   3. clamd im Vordergrund starten (PID 1, ueber tini SIGTERM-faehig).
set -eu

log() {
    echo "Malware-Protection (clamav): $*"
}

# Laufzeitverzeichnisse anlegen: /run und /var/log/clamav sind im
# docker-compose.yml fluechtige (tmpfs) Verzeichnisse, /var/lib/clamav ist das
# Signatur-Volume. clamd laeuft als Benutzer clamav und braucht Schreibrechte.
mkdir -p /run/clamav /var/log/clamav /tmp
chown clamav:clamav /run/clamav /var/log/clamav /var/lib/clamav

# Das Wurzelverzeichnis des Containers ist schreibgeschuetzt (read_only im
# docker-compose.yml). Die mitgelieferten Konfigurationen liegen daher
# unveraendert im Image und werden zum Start in das fluechtige /run/clamav
# kopiert; angepasst und verwendet wird nur diese Kopie.
cp /etc/clamav/clamd.conf /run/clamav/clamd.conf
cp /etc/clamav/freshclam.conf /run/clamav/freshclam.conf
chown clamav:clamav /run/clamav/clamd.conf /run/clamav/freshclam.conf
clamd_conf=/run/clamav/clamd.conf
freshclam_conf=/run/clamav/freshclam.conf

# Einstellungen aus der Umgebung in die Konfiguration uebernehmen. Nur einfache
# Groessen- bzw. Zahlenangaben, damit keine ungueltigen Werte in die
# Konfigurationsdatei gelangen.
max_stream_length="${CLAMAV_MAX_STREAM_LENGTH:-100M}"
checks="${CLAMAV_FRESHCLAM_CHECKS:-24}"

if ! echo "${max_stream_length}" | grep -Eq '^[0-9]+[KMG]?$'; then
    log "ungueltiges CLAMAV_MAX_STREAM_LENGTH '${max_stream_length}', verwende 100M"
    max_stream_length="100M"
fi

if ! echo "${checks}" | grep -Eq '^[0-9]+$' || [ "${checks}" -lt 1 ]; then
    log "ungueltiges CLAMAV_FRESHCLAM_CHECKS '${checks}', verwende 24"
    checks="24"
fi

sed -i "s|^StreamMaxLength .*|StreamMaxLength ${max_stream_length}|" "${clamd_conf}"
sed -i "s|^Checks .*|Checks ${checks}|" "${freshclam_conf}"

# freshclam stoesst nach einer Aktualisierung ein RELOAD der laufenden
# clamd-Instanz an. Dazu muss es die tatsaechlich verwendete Konfiguration
# kennen, also die Kopie in /run/clamav.
sed -i "s|^NotifyClamd .*|NotifyClamd ${clamd_conf}|" "${freshclam_conf}"

# clamd startet ohne Signaturdatenbank nicht. Beim ersten Start (leeres Volume)
# wird sie deshalb einmal vollstaendig geladen; das dauert einige Minuten.
if ! ls /var/lib/clamav/*.c[vl]d > /dev/null 2>&1; then
    log "Signaturdatenbank fehlt, lade sie erstmalig (kann einige Minuten dauern) ..."
    if ! freshclam --stdout --config-file="${freshclam_conf}"; then
        log "WARNUNG: Signaturdatenbank konnte nicht geladen werden."
        log "WARNUNG: Ohne Signaturen kann die Anwendung keine Anhaenge pruefen."
    fi
fi

if ls /var/lib/clamav/*.c[vl]d > /dev/null 2>&1; then
    log "starte Aktualisierungs-Daemon (Intervall: alle ${checks} Stunden)"
    freshclam --daemon --stdout --config-file="${freshclam_conf}" &
else
    log "WARNUNG: starte ohne Aktualisierungs-Daemon (keine Signaturen vorhanden)"
fi

log "starte clamd (TCP 3310, INSTREAM-Obergrenze ${max_stream_length})"
exec clamd --foreground --config-file="${clamd_conf}"
