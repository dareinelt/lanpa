#!/bin/sh
# SNMP-Statuspruefung fuer die Intranet-Landingpage.
#
# Verwendung: check_status.sh <app|db|sync|sync_workflow|phpmyadmin|
#                              nextcloud|nextcloud_db|nextcloud_redis|eurooffice|office_workflow|
#                              tls_certificate|tls_certificate_days>
#
# Rueckgabe (Exit-Code, landet als extResult in der SNMP-Tabelle):
#   0 = OK        Dienst laeuft/gesund bzw. Workflow frisch erfolgreich
#   1 = WARNING   eingeschraenkt (startend, laufend, veraltet)
#   2 = CRITICAL  nicht erreichbar/gestoppt/fehlgeschlagen
#   3 = UNKNOWN   nicht anwendbar (z. B. optionaler Dienst nicht bereitgestellt)
#
# Die erste Zeile der Standardausgabe wird als extOutput ausgeliefert.
set -u

DOCKER_BIN="${DOCKER_BIN:-docker}"
PROJECT="${COMPOSE_PROJECT_NAME:-}"

# IDs aller Container eines Compose-Dienstes (Label-basiert).
service_ids() {
    local svc="$1" filter
    filter="label=com.docker.compose.service=${svc}"
    [ -n "$PROJECT" ] && filter="label=com.docker.compose.project=${PROJECT},${filter}"
    "$DOCKER_BIN" ps -aq --filter "$filter" 2>/dev/null
}

# Gibt "state|health" des juengsten Containers des Dienstes aus,
# Rueckgabe != 0 wenn kein Container existiert.
inspect() {
    local svc="$1" id
    id="$(service_ids "$svc" | head -n1)"
    [ -z "$id" ] && return 1
    "$DOCKER_BIN" inspect -f '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id" 2>/dev/null
}

# Prueft einen Container-Dienst.
# $1 = Dienstname, $2 = Healthcheck relevant (1) oder nur Laufzustand (0)
check_container() {
    local svc="$1" require_health="${2:-1}" info state health
    if ! info="$(inspect "$svc")"; then
        echo "${svc}: nicht gefunden"
        return 2
    fi
    state="${info%%|*}"
    health="${info##*|}"

    if [ "$state" != "running" ]; then
        echo "${svc}: state=${state}"
        return 2
    fi

    if [ "$require_health" = "1" ]; then
        case "$health" in
            healthy)  echo "${svc}: healthy"; return 0 ;;
            starting) echo "${svc}: starting"; return 1 ;;
            none)     echo "${svc}: running (kein Healthcheck)"; return 0 ;;
            *)        echo "${svc}: ${health}"; return 2 ;;
        esac
    fi

    echo "${svc}: running"
    return 0
}

# Fuehrt eine Abfrage in der Intranet-Datenbank aus (tabulatorgetrennt, ohne
# Kopfzeile). Der Alpine-MariaDB-Client beherrscht caching_sha2_password
# (Standard ab MySQL 8) nicht; dann wird die Abfrage per Docker-Socket im
# db-Container ausgefuehrt (mit dessen Zugangsdaten MYSQL_USER/MYSQL_PASSWORD).
db_query() {
    local host="${DB_HOST:-db}" port="${DB_PORT:-3306}" user="${DB_USER:-intranet}"
    local name="${DB_NAME:-intranet}" pass="${DB_PASSWORD:-}" passfile="${DB_PASSWORD_FILE:-}" id
    [ -n "$passfile" ] && [ -r "$passfile" ] && pass="$(cat "$passfile")"

    MYSQL_PWD="$pass" mysql --protocol=tcp -h "$host" -P "$port" -u "$user" -N -B -D "$name" \
        -e "$1" 2>/dev/null && return 0

    id="$(service_ids db | head -n1)"
    [ -n "$id" ] || return 1
    "$DOCKER_BIN" exec -i "$id" sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysql -u"$MYSQL_USER" -N -B -D "$MYSQL_DATABASE"' \
        <<SQL 2>/dev/null
$1
SQL
}

# Liest eine einzelne Spalte der letzten Zeile aus sync_log.
sync_log_value() {
    db_query "SELECT ${1} FROM sync_log ORDER BY id DESC LIMIT 1"
}

# Status des AD-Synchronisations-Workflows (letzter Lauf + Alter).
check_sync_workflow() {
    local interval="${LDAP_SYNC_INTERVAL:-3600}" status age threshold
    local row
    row="$(sync_log_value "status, TIMESTAMPDIFF(SECOND, COALESCE(finished_at, started_at), NOW())")" || {
        echo "sync_workflow: Datenbank nicht erreichbar"
        return 2
    }

    [ -z "$row" ] && { echo "sync_workflow: keine Laeufe"; return 3; }

    status="$(printf '%s' "$row" | cut -f1)"
    age="$(printf '%s' "$row" | cut -f2)"
    [ -z "$age" ] && age=0
    threshold=$(( interval * 2 ))

    case "$status" in
        success)
            if [ "$age" -gt "$threshold" ] 2>/dev/null; then
                echo "sync_workflow: success, aber veraltet (${age}s)"
                return 1
            fi
            echo "sync_workflow: success (vor ${age}s)"
            return 0 ;;
        running) echo "sync_workflow: running"; return 1 ;;
        error)   echo "sync_workflow: error"; return 2 ;;
        *)       echo "sync_workflow: ${status}"; return 3 ;;
    esac
}

# Optionaler Dienst phpMyAdmin (Profil "tools").
check_phpmyadmin() {
    local info state health
    if ! info="$(inspect phpmyadmin)"; then
        echo "phpmyadmin: nicht bereitgestellt (optional)"
        return 3
    fi
    state="${info%%|*}"
    health="${info##*|}"
    if [ "$state" = "running" ]; then
        echo "phpmyadmin: running"
        return 0
    fi
    echo "phpmyadmin: state=${state}"
    return 2
}

# Optionaler Office-Dienst (Profil "office"): nicht bereitgestellt -> UNKNOWN.
check_office_container() {
    local svc="$1"
    if [ -z "$(service_ids "$svc" | head -n1)" ]; then
        echo "${svc}: nicht bereitgestellt (optional)"
        return 3
    fi
    check_container "$svc" 1
}

# HTTP-Abruf innerhalb des Office-Netzes (Antworttext auf stdout).
http_get() {
    wget -q -T 5 -O - --header "Host: ${2:-nextcloud}" "$1" 2>/dev/null
}

# Gesamt-Workflow Office: Container gesund, Nextcloud installiert und nicht
# im Wartungsmodus, DocumentServer-Healthcheck ok.
check_office_workflow() {
    local svc body
    if [ -z "$(service_ids nextcloud | head -n1)" ] && [ -z "$(service_ids eurooffice | head -n1)" ]; then
        echo "office_workflow: nicht bereitgestellt (optional)"
        return 3
    fi
    for svc in nextcloud-db nextcloud-redis nextcloud eurooffice; do
        if ! check_container "$svc" 1 >/dev/null; then
            echo "office_workflow: ${svc} nicht bereit"
            return 2
        fi
    done
    body="$(http_get http://nextcloud/office/status.php nextcloud)" || body=""
    case "$body" in
        *'"installed":true'*) ;;
        *) echo "office_workflow: Nextcloud nicht erreichbar"; return 2 ;;
    esac
    case "$body" in
        *'"maintenance":true'*) echo "office_workflow: Nextcloud im Wartungsmodus"; return 1 ;;
    esac
    body="$(http_get http://eurooffice/healthcheck eurooffice)" || body=""
    if [ "$body" != "true" ]; then
        echo "office_workflow: DocumentServer-Healthcheck fehlgeschlagen"
        return 2
    fi
    echo "office_workflow: ok"
    return 0
}


# Gueltigkeit des aktiven HTTPS-Zertifikats des auth-Containers
# (Adminbereich -> Zertifikate). $1 = "text" (Klartext) oder "days"
# (nur Resttage als Zahl, negativ = abgelaufen, -9999 = kein Zertifikat).
#   0 = gueltig > TLS_WARN_DAYS (30) Tage
#   1 = laeuft bald ab bzw. kein Zertifikat aktiv (Notfall-Zertifikat)
#   2 = abgelaufen oder noch nicht gueltig / Datenbank nicht erreichbar
check_tls_certificate() {
    local format="${1:-text}" warn_days="${TLS_WARN_DAYS:-30}" row left before until cn days
    row="$(db_query "SELECT cert_not_after - UNIX_TIMESTAMP(), cert_not_before - UNIX_TIMESTAMP(), DATE_FORMAT(DATE_ADD('1970-01-01 00:00:00', INTERVAL cert_not_after SECOND), '%Y-%m-%d %H:%i UTC'), common_name FROM tls_certificates WHERE kind = 'csr' AND active = 1 AND certificate_pem IS NOT NULL ORDER BY id DESC LIMIT 1")" || {
        [ "$format" = "days" ] && echo "-9999" || echo "tls_certificate: Datenbank nicht erreichbar"
        return 2
    }
    if [ -z "$row" ]; then
        [ "$format" = "days" ] && echo "-9999" || echo "tls_certificate: kein Zertifikat aktiv (Notfall-Zertifikat)"
        return 1
    fi

    left="$(printf '%s' "$row" | cut -f1)"
    before="$(printf '%s' "$row" | cut -f2)"
    until="$(printf '%s' "$row" | cut -f3)"
    cn="$(printf '%s' "$row" | cut -f4)"
    if [ "$left" -ge 0 ]; then
        days=$((left / 86400))
    else
        days=$(( -((-left + 86399) / 86400) ))
    fi
    [ "$format" = "days" ] && echo "$days"

    if [ "$before" -gt 0 ]; then
        [ "$format" = "days" ] || echo "tls_certificate: ${cn} noch nicht gueltig"
        return 2
    fi
    if [ "$left" -le 0 ]; then
        [ "$format" = "days" ] || echo "tls_certificate: ${cn} abgelaufen seit ${until}"
        return 2
    fi
    if [ "$days" -le "$warn_days" ]; then
        [ "$format" = "days" ] || echo "tls_certificate: ${cn} laeuft in ${days} Tagen ab (${until})"
        return 1
    fi
    [ "$format" = "days" ] || echo "tls_certificate: ${cn} gueltig bis ${until} (${days} Tage)"
    return 0
}

case "${1:-}" in
    app)           check_container app 1 ;;
    db)            check_container db 1 ;;
    sync)          check_container sync 0 ;;
    sync_workflow) check_sync_workflow ;;
    phpmyadmin)    check_phpmyadmin ;;
    nextcloud)       check_office_container nextcloud ;;
    nextcloud_db)    check_office_container nextcloud-db ;;
    nextcloud_redis) check_office_container nextcloud-redis ;;
    eurooffice)      check_office_container eurooffice ;;
    office_workflow) check_office_workflow ;;
    tls_certificate)      check_tls_certificate text ;;
    tls_certificate_days) check_tls_certificate days ;;
    *) echo "unbekannte Pruefung: ${1:-}"; exit 3 ;;
esac
