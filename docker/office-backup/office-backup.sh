#!/usr/bin/env bash
# Sicherung und Wiederherstellung der Office-Erweiterung.
#
#   office-backup agent            Dienstbetrieb: wartet auf Auftraege des
#                                  Intranet-Adminbereichs (request.json) und
#                                  fuehrt optional eine taegliche Sicherung aus.
#   office-backup backup           Sofortige Sicherung.
#   office-backup list             Vorhandene Sicherungen anzeigen.
#   office-backup restore NAME [--with-intranet]
#                                  Wiederherstellung (Nextcloud, Euro-Office und
#                                  optional Intranet-Datenbank/-Dateien). Nur bei
#                                  gestoppten nextcloud/nextcloud-cron/eurooffice
#                                  aufrufen - siehe scripts/office-restore.sh.
#
# Inhalt einer Sicherung (ein Archiv je Sicherung, optional verschluesselt):
#   manifest.json, nextcloud-db.dump (pg_dump -Fc), intranet-db.sql,
#   nextcloud-config.tar (config/, custom_apps/, themes/), nextcloud-data.tar,
#   eurooffice-data.tar (ohne .private), intranet-storage.tar
#
# Geheimnisse werden nie protokolliert. Archive erhalten die Rechte 0600.
set -euo pipefail
umask 077

BACKUP_DIR=${OFFICE_BACKUP_DIR_INTERNAL:-/backups}
NC_HTML=/data/nextcloud-html
NC_DATA=/data/nextcloud-data
EO_DATA=/data/eurooffice-data
APP_STORAGE=/data/intranet-storage
CONTROL_DIR="${APP_STORAGE}/office-backup"
RETENTION=${OFFICE_BACKUP_RETENTION:-7}
SCHEDULE_HOUR=${OFFICE_BACKUP_SCHEDULE_HOUR:-}
MAINTENANCE_FILE="${NC_HTML}/config/zz-intranet-backup.config.php"

log() { printf '[office-backup] %s %s\n' "$(date -Iseconds)" "$*"; }

secret() {
    local file="/run/secrets/$1"
    if [[ -r "$file" ]]; then tr -d '\r\n' < "$file"; fi
}

json_escape() { jq -Rn --arg v "$1" '$v'; }

# Sicherungsarchive, neueste zuerst. Ohne Treffer bleibt die Ausgabe leer
# ("ls" ohne Argumente wuerde sonst das Arbeitsverzeichnis auflisten).
list_archives() {
    local files=()
    shopt -s nullglob
    files=("$BACKUP_DIR"/office-*.tar "$BACKUP_DIR"/office-*.tar.enc)
    shopt -u nullglob
    [[ ${#files[@]} -gt 0 ]] || return 0
    ls -1t -- "${files[@]}"
}

list_json() {
    local first=1
    printf '['
    local f
    for f in $(list_archives); do
        [[ $first -eq 1 ]] || printf ','
        first=0
        local name size mtime enc=false
        name=$(basename "$f")
        size=$(stat -c %s "$f")
        mtime=$(date -u -d "@$(stat -c %Y "$f")" +%Y-%m-%dT%H:%M:%SZ)
        [[ "$name" == *.enc ]] && enc=true
        printf '{"name":%s,"size":%s,"created":"%s","encrypted":%s}' "$(json_escape "$name")" "$size" "$mtime" "$enc"
    done
    printf ']'
}

write_status() {
    # $1 state, $2 action, $3 message, $4 request id
    mkdir -p "$CONTROL_DIR"
    local tmp="${CONTROL_DIR}/.status.tmp"
    jq -n \
        --arg state "$1" --arg action "$2" --arg message "$3" --arg id "${4:-}" \
        --arg updated "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --argjson backups "$(list_json)" \
        --arg retention "$RETENTION" \
        --arg encryption "$([[ -n "$(secret office_backup_passphrase)" ]] && echo true || echo false)" \
        '{state:$state, action:$action, message:$message, request_id:$id, updated_at:$updated,
          retention:($retention|tonumber? // 7), encryption:($encryption=="true"), backups:$backups}' > "$tmp"
    chmod 0644 "$tmp"
    mv -f "$tmp" "${CONTROL_DIR}/status.json"
}

maintenance_on() {
    if [[ -d "${NC_HTML}/config" ]]; then
        printf '<?php\n// Vom Sicherungsdienst gesetzt, wird danach wieder entfernt.\n$CONFIG = [\x27maintenance\x27 => true];\n' > "$MAINTENANCE_FILE"
        chmod 0644 "$MAINTENANCE_FILE"
        log "Nextcloud-Wartungsmodus aktiv."
        sleep 3
    fi
}

maintenance_off() {
    if [[ -f "$MAINTENANCE_FILE" ]]; then
        rm -f "$MAINTENANCE_FILE"
        log "Nextcloud-Wartungsmodus beendet."
    fi
}

mysql_args() {
    MYSQL_ARGS=("--host=${DB_HOST:-db}" "--port=${DB_PORT:-3306}" "--user=${DB_USER:-intranet}" \
        "--skip-ssl-verify-server-cert")
}

do_backup() {
    mkdir -p "$BACKUP_DIR"
    local name
    name="office-$(date +%Y%m%d-%H%M%S)"
    local work="${BACKUP_DIR}/.work-${name}"
    rm -rf "$work"
    mkdir -p "$work"
    # RETURN greift bei einem Abbruch durch "set -e" nicht - daher auch EXIT,
    # damit Nextcloud nie im Wartungsmodus haengen bleibt.
    trap 'maintenance_off; rm -rf "'"$work"'"' RETURN EXIT

    log "Sicherung ${name} gestartet."
    maintenance_on

    PGPASSWORD="$(secret nextcloud_db_password)" pg_dump -h nextcloud-db -U nextcloud -d nextcloud -Fc -f "${work}/nextcloud-db.dump"

    if [[ -n "${DB_PASSWORD:-}" ]]; then
        mysql_args
        MYSQL_PWD="$DB_PASSWORD" mariadb-dump "${MYSQL_ARGS[@]}" --single-transaction --routines --no-tablespaces \
            "${DB_NAME:-intranet}" > "${work}/intranet-db.sql"
    else
        log "Keine Intranet-Datenbankzugangsdaten - Intranet-Datenbank wird nicht gesichert."
    fi

    if [[ -d "${NC_HTML}/config" ]]; then
        tar --numeric-owner -C "$NC_HTML" \
            --exclude='config/intranet.config.php' --exclude='config/zz-intranet-backup.config.php' \
            -cf "${work}/nextcloud-config.tar" config custom_apps themes 2>/dev/null \
            || tar --numeric-owner -C "$NC_HTML" --exclude='config/intranet.config.php' \
                --exclude='config/zz-intranet-backup.config.php' -cf "${work}/nextcloud-config.tar" config
    fi
    [[ -d "$NC_DATA" ]] && tar --numeric-owner -C "$NC_DATA" -cf "${work}/nextcloud-data.tar" .
    [[ -d "$EO_DATA" ]] && tar --numeric-owner -C "$EO_DATA" --exclude='./.private' -cf "${work}/eurooffice-data.tar" .
    [[ -d "$APP_STORAGE" ]] && tar --numeric-owner -C "$APP_STORAGE" --exclude='./office-backup' -cf "${work}/intranet-storage.tar" .

    maintenance_off

    local nc_version=""
    if [[ -f "${NC_HTML}/version.php" ]]; then
        nc_version=$(sed -n "s/^\$OC_VersionString = '\(.*\)';/\1/p" "${NC_HTML}/version.php" | head -n1)
    fi
    (cd "$work" && sha256sum -- * > SHA256SUMS)
    jq -n --arg name "$name" --arg created "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --arg nc "$nc_version" \
        '{format:1, name:$name, created_at:$created, nextcloud_version:$nc,
          parts:["nextcloud-db.dump","intranet-db.sql","nextcloud-config.tar","nextcloud-data.tar","eurooffice-data.tar","intranet-storage.tar"]}' \
        > "${work}/manifest.json"

    local target="${BACKUP_DIR}/${name}.tar"
    local passphrase
    passphrase="$(secret office_backup_passphrase)"
    if [[ -n "$passphrase" ]]; then
        target="${target}.enc"
        tar -C "$work" -cf - . | OFFICE_PASS="$passphrase" openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
            -pass env:OFFICE_PASS -out "${target}.part"
    else
        tar -C "$work" -cf "${target}.part" .
    fi
    chmod 0600 "${target}.part"
    mv -f "${target}.part" "$target"
    log "Sicherung ${name} abgeschlossen ($(du -h "$target" | cut -f1))."

    apply_retention
    }

apply_retention() {
    [[ "$RETENTION" =~ ^[0-9]+$ ]] || return 0
    [[ "$RETENTION" -gt 0 ]] || return 0
    local i=0 f
    for f in $(list_archives); do
        i=$((i + 1))
        if [[ $i -gt $RETENTION ]]; then
            rm -f -- "$f"
            log "Alte Sicherung $(basename "$f") entfernt (Aufbewahrung ${RETENTION})."
        fi
    done
}

do_restore() {
    local name="${1:-}" with_intranet="${2:-}"
    if [[ ! "$name" =~ ^office-[0-9]{8}-[0-9]{6}\.tar(\.enc)?$ ]]; then
        echo "Ungueltiger Sicherungsname. Verfuegbar:" >&2
        list_json | jq -r '.[].name' >&2
        return 2
    fi
    local archive="${BACKUP_DIR}/${name}"
    [[ -f "$archive" ]] || { echo "Sicherung ${name} nicht gefunden." >&2; return 2; }

    local work="${BACKUP_DIR}/.restore-$$"
    rm -rf "$work"; mkdir -p "$work"
    trap 'rm -rf "'"$work"'"' RETURN EXIT

    if [[ "$name" == *.enc ]]; then
        local passphrase
        passphrase="$(secret office_backup_passphrase)"
        [[ -n "$passphrase" ]] || { echo "Verschluesselte Sicherung, aber kein Passwort (office_backup_passphrase)." >&2; return 2; }
        OFFICE_PASS="$passphrase" openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:OFFICE_PASS -in "$archive" \
            | tar -C "$work" -xf -
    else
        tar -C "$work" -xf "$archive"
    fi
    (cd "$work" && sha256sum -c --quiet SHA256SUMS) || { echo "Pruefsummen stimmen nicht - Abbruch." >&2; return 3; }

    log "Wiederherstellung ${name} gestartet."
    PGPASSWORD="$(secret nextcloud_db_password)" pg_restore -h nextcloud-db -U nextcloud -d nextcloud \
        --clean --if-exists --no-owner --role=nextcloud "${work}/nextcloud-db.dump"

    if [[ -f "${work}/nextcloud-config.tar" ]]; then
        mkdir -p "${NC_HTML}/config"
        find "${NC_HTML}/config" -mindepth 1 -maxdepth 1 ! -name 'intranet.config.php' -exec rm -rf {} +
        tar --numeric-owner -C "$NC_HTML" -xf "${work}/nextcloud-config.tar" \
            --exclude='custom_apps/intranet_integration'
    fi
    if [[ -f "${work}/nextcloud-data.tar" ]]; then
        find "$NC_DATA" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
        tar --numeric-owner -C "$NC_DATA" -xf "${work}/nextcloud-data.tar"
    fi
    if [[ -f "${work}/eurooffice-data.tar" ]]; then
        find "$EO_DATA" -mindepth 1 -maxdepth 1 ! -name '.private' -exec rm -rf {} +
        tar --numeric-owner -C "$EO_DATA" -xf "${work}/eurooffice-data.tar"
    fi
    if [[ "$with_intranet" == "--with-intranet" ]]; then
        if [[ -s "${work}/intranet-db.sql" && -n "${DB_PASSWORD:-}" ]]; then
            mysql_args
            MYSQL_PWD="$DB_PASSWORD" mariadb "${MYSQL_ARGS[@]}" "${DB_NAME:-intranet}" < "${work}/intranet-db.sql"
        fi
        if [[ -f "${work}/intranet-storage.tar" ]]; then
            tar --numeric-owner -C "$APP_STORAGE" -xf "${work}/intranet-storage.tar" --exclude='./office-backup'
        fi
    fi
    maintenance_off
    log "Wiederherstellung ${name} abgeschlossen."
}

process_request() {
    local req="${CONTROL_DIR}/request.json"
    [[ -f "$req" ]] || return 0
    local processing="${CONTROL_DIR}/.request.processing"
    mv -f "$req" "$processing" 2>/dev/null || return 0
    local action id
    action=$(jq -r '.action // ""' "$processing" 2>/dev/null || echo "")
    id=$(jq -r '.id // ""' "$processing" 2>/dev/null | tr -cd 'a-zA-Z0-9-' | cut -c1-64)
    rm -f "$processing"
    case "$action" in
        backup)
            write_status running backup "Sicherung laeuft ..." "$id"
            if "$0" _backup >>/proc/1/fd/1 2>&1; then
                write_status "done" backup "Sicherung erfolgreich erstellt." "$id"
            else
                maintenance_off
                write_status failed backup "Sicherung fehlgeschlagen - Details im Log des Containers office-backup." "$id"
            fi
            ;;
        refresh)
            write_status idle refresh "" "$id"
            ;;
        *)
            write_status failed "$action" "Unbekannter Auftrag." "$id"
            ;;
    esac
}

agent() {
    mkdir -p "$CONTROL_DIR"
    # Die Intranet-Anwendung (www-data, UID 33) legt hier Auftraege ab.
    chown "${OFFICE_BACKUP_APP_UID:-33}:${OFFICE_BACKUP_APP_UID:-33}" "$CONTROL_DIR"
    chmod 0750 "$CONTROL_DIR"
    # Verwaisten Wartungsmodus eines abgebrochenen Laufs aufheben.
    maintenance_off
    write_status idle "" "" ""
    log "Bereit (Aufbewahrung ${RETENTION}, taeglich: ${SCHEDULE_HOUR:-aus})."
    local last_scheduled=""
    while true; do
        touch /tmp/office-backup-alive
        process_request || true
        if [[ "$SCHEDULE_HOUR" =~ ^[0-9]{1,2}$ ]] && (( 10#$(date +%H) == 10#$SCHEDULE_HOUR )); then
            local today
            today=$(date +%Y-%m-%d)
            if [[ "$last_scheduled" != "$today" ]]; then
                last_scheduled="$today"
                write_status running backup "Geplante Sicherung laeuft ..." "scheduled"
                if "$0" _backup; then
                    write_status "done" backup "Geplante Sicherung erfolgreich erstellt." "scheduled"
                else
                    maintenance_off
                    write_status failed backup "Geplante Sicherung fehlgeschlagen." "scheduled"
                fi
            fi
        fi
        sleep 5
    done
}

case "${1:-agent}" in
    agent) agent ;;
    backup) do_backup; write_status "done" backup "Sicherung erfolgreich erstellt." "cli" ;;
    # Intern: eigener Prozess, damit "set -e" auch aus if/||-Kontexten greift.
    _backup) do_backup ;;
    list) list_json | jq . ;;
    restore) shift; do_restore "${1:-}" "${2:-}"; write_status idle restore "Wiederherstellung abgeschlossen." "cli" ;;
    *) echo "Verwendung: office-backup {agent|backup|list|restore NAME [--with-intranet]}" >&2; exit 2 ;;
esac
