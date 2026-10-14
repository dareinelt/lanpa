#!/usr/bin/env bash
# Anwenden und Ueberwachen der Windows-Anmeldung (Kerberos/NTLM) fuer alle
# Identitaetsquellen.
#
#   sso-agent agent    Dienstbetrieb: wartet auf Auftraege des Adminbereichs
#                      (request.json) und meldet den Zustand (status.json).
#   sso-agent apply    Sofort anwenden (ohne Adminbereich).
#   sso-agent status   Zustand als JSON ausgeben.
#
# Dies ist der einzige Dienst mit Zugriff auf den Docker-Socket; die Anwendung
# selbst hat keinen. Der Adminbereich legt im gemeinsamen Speicher einen Auftrag
# ab (request.json), der Agent fuehrt ihn aus und meldet das Ergebnis ueber
# status.json zurueck. Damit laesst sich eine neue Identitaetsquelle vollstaendig
# im Adminbereich anwenden (Active Directory -> Windows-Anmeldung).
#
# Ablauf beim Anwenden:
#   1. scripts/sso-domains.sh erzeugt docker-compose.sso.yml: je weiterer
#      Domaene mit Windows-Anmeldung eine eigene auth-Instanz.
#   2. docker-compose.sso.yml wird in COMPOSE_FILE der .env ergaenzt bzw. nach
#      dem Entfernen der letzten weiteren Domaene wieder entfernt. Fehlt der
#      Eintrag, existiert der Dienst nicht im Compose-Projekt: die Anmeldung
#      landet dann auf der Hauptinstanz einer anderen Domaene, die Pruefung
#      schlaegt fehl und der Browser fragt endlos nach Zugangsdaten.
#   3. Die auth-Instanzen werden gebaut, neu erstellt und neu gestartet; jede
#      Instanz holt ihre Konfiguration beim Start von der Anwendung ab.
#   4. Der Zustand je Instanz (Domaene, Domaenenbeitritt, Kerberos, Uhrzeit)
#      wird aus dem Container-Protokoll gelesen und als status.json gemeldet.
#
# Zugangsdaten stehen nicht in dieser Datei: sie werden verschluesselt in der
# Anwendung gepflegt und von jeder Instanz ueber /internal/sso-config abgerufen.
set -euo pipefail
umask 022

PROJECT_DIR=${SSO_AGENT_PROJECT_DIR:-/project}
CONTROL_DIR=${SSO_AGENT_CONTROL_DIR:-/storage/sso-agent}
POLL_SECONDS=${SSO_AGENT_POLL_SECONDS:-5}
STATUS_SECONDS=${SSO_AGENT_STATUS_SECONDS:-60}
APPLY_TIMEOUT=${SSO_AGENT_APPLY_TIMEOUT:-1800}
APP_UID=${SSO_AGENT_APP_UID:-33}
COMPOSE_ENTRY=docker-compose.sso.yml

log() { printf '[sso-agent] %s %s\n' "$(date -Iseconds)" "$*"; }

# Name des Compose-Projekts: der Agent laeuft selbst darin, deshalb genuegt die
# Kennzeichnung eines Containers. Ohne Treffer (manueller Aufruf) dient der
# Verzeichnisname als Notbehelf.
project_name() {
    local name=""
    name=$(docker ps --filter 'label=com.docker.compose.service=app' \
        --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null | head -n 1) || name=""
    if [ -z "$name" ]; then
        name=$(docker ps --filter 'label=com.docker.compose.service=sso-agent' \
            --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null | head -n 1) || name=""
    fi
    if [ -z "$name" ]; then
        name=$(basename "$PROJECT_DIR")
    fi
    printf '%s' "$name"
}

compose() {
    ( cd "$PROJECT_DIR" && COMPOSE_PROJECT_NAME="$PROJECT" docker compose "$@" )
}

compose_timeout() {
    local seconds="$1"
    shift
    ( cd "$PROJECT_DIR" && COMPOSE_PROJECT_NAME="$PROJECT" timeout "$seconds" docker compose "$@" )
}

# COMPOSE_FILE aus der Umgebung (der Dienst erbt sie aus der .env), sonst aus
# der .env selbst lesen.
env_compose_file() {
    local value="${COMPOSE_FILE:-}"
    if [ -z "$value" ] && [ -f "$PROJECT_DIR/.env" ]; then
        value=$(grep -E '^[[:space:]]*COMPOSE_FILE=' "$PROJECT_DIR/.env" | tail -n 1 | cut -d= -f2-) || value=""
    fi
    printf '%s' "$value"
}

# COMPOSE_FILE-Liste bilden: $1 = add (Eintrag sicherstellen) oder remove
# (Eintrag entfernen).
compose_file_with() {
    local mode="$1" current list="" item
    current="$(env_compose_file)"
    [ -n "$current" ] || current="docker-compose.yml"
    IFS=':'
    for item in $current; do
        if [ "$item" = "$COMPOSE_ENTRY" ]; then
            [ "$mode" = "add" ] && list="${list:+$list:}$item"
            continue
        fi
        [ -n "$item" ] && list="${list:+$list:}$item"
    done
    unset IFS
    if [ "$mode" = "add" ]; then
        case ":$list:" in
            *":$COMPOSE_ENTRY:"*) ;;
            *) list="${list:+$list:}$COMPOSE_ENTRY" ;;
        esac
    fi
    printf '%s' "$list"
}

# COMPOSE_FILE in der .env pflegen (nur bei Abweichung) und in die eigene
# Umgebung uebernehmen, damit die folgenden Aufrufe dieselbe Dateiliste nutzen.
ensure_compose_file() {
    local value="$1" env_file="$PROJECT_DIR/.env" tmp="" current
    current="$(env_compose_file)"
    if [ "$current" = "$value" ]; then
        export COMPOSE_FILE="$value"
        return 0
    fi
    if [ ! -f "$env_file" ]; then
        log "Warnung: ${env_file} fehlt - COMPOSE_FILE wird nur fuer diesen Lauf gesetzt."
        export COMPOSE_FILE="$value"
        return 0
    fi
    tmp="${env_file}.sso-agent.tmp"
    if ! awk -v value="$value" '
        /^[[:space:]]*COMPOSE_FILE=/ && !written { print "COMPOSE_FILE=" value; written = 1; next }
        { print }
        END { if (!written) print "COMPOSE_FILE=" value }
    ' "$env_file" > "$tmp"; then
        rm -f "$tmp"
        log "Warnung: ${env_file} konnte nicht geschrieben werden."
        export COMPOSE_FILE="$value"
        return 0
    fi
    chmod "$(stat -c %a "$env_file")" "$tmp" 2>/dev/null || chmod 0600 "$tmp"
    mv -f "$tmp" "$env_file"
    export COMPOSE_FILE="$value"
    log "COMPOSE_FILE in ${env_file} angepasst: ${value}"
}

# Alle auth-Instanzen des Compose-Projekts (Hauptinstanz und je Domaene eine).
auth_services() {
    compose config --services 2>/dev/null | grep -E '^auth(-[a-z0-9-]+)?$' | sort || true
}

# Letzte Fehlerzeilen als kurze Meldung.
summarize() {
    { printf '%s\n' "$1" | grep -v '^[[:space:]]*$' | tail -n 3 | tr '\n' ' ' | cut -c1-400; } || true
}

status_field() {
    local value=""
    if [ -r "${CONTROL_DIR}/status.json" ]; then
        value="$(jq -r --arg f "$1" '.[$f] // empty' "${CONTROL_DIR}/status.json" 2>/dev/null)" || value=""
    fi
    [ -n "$value" ] || value="${2:-}"
    printf '%s' "$value"
}

declare -A FACT_FINGERPRINT=()
declare -A FACT_ACTIVE=()
declare -A FACT_ENABLED=()
declare -A FACT_DOMAIN=()
declare -A FACT_KERBEROS=()
declare -A FACT_JOIN=()
declare -A FACT_NTP=()
declare -A FACT_NOTES=()

# Zustand einer Instanz aus ihrem Protokoll lesen. Die entscheidenden Zeilen
# schreibt der Einstieg der Instanz beim Start, deshalb genuegt der aktuelle
# Lauf (--since) und das erste Vorkommen (head).
read_facts() {
    local service="$1" id="$2" started="" line="" lines="" active=false enabled=null
    local kerberos=false join=unknown ntp="" domain="" notes="" join_ok=false join_failed=false
    started="$(docker inspect -f '{{.State.StartedAt}}' "$id" 2>/dev/null)" || started=""
    if [ -n "$started" ] && [ "${FACT_FINGERPRINT[$service]:-}" = "$started" ]; then
        return 0
    fi
    FACT_FINGERPRINT[$service]="$started"
    [ -n "$started" ] || started=24h
    # Nur den laufenden Start betrachten und die Ausgabe begrenzen: die
    # entscheidenden Zeilen stehen am Anfang, das Protokoll kann aber gross sein.
    lines="$(timeout 15 docker logs --since "$started" "$id" 2>/dev/null \
        | grep -a '^\[auth\]' | head -n 200)" || true
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        line="${line#*\[auth\] }"
        case "$line" in
            "Windows-Anmeldung aktiv:"*)
                active=true
                enabled=true
                domain="${line#*Domaene }"
                domain="${domain%%,*}"
                domain="${domain%%.*}"
                case "$line" in *Kerberos*) kerberos=true ;; esac
                ;;
            "SSO_ENABLED ist nicht gesetzt"*) enabled=false ;;
            "Domaenenbeitritt "*"besteht bereits"*) join_ok=true ;;
            *"WARNUNG: Instanz "*"ohne Konfiguration"*) enabled=false ;;
            *"WARNUNG: Kein gueltiger Domaenenbeitritt"*) join_failed=true ;;
            *"WARNUNG: Domaenenbeitritt"*"fehlgeschlagen"*) join_failed=true ;;
            *"WARNUNG: ADS-Domaenenbeitritt"*"fehlgeschlagen"*) join_failed=true ;;
            *"WARNUNG: Kerberos nicht verfuegbar"*) kerberos=false ;;
            *"Uhrzeit mit "*"abgeglichen"*) ntp=ok ;;
            *"Uhrzeit geprueft"*) ntp=ok ;;
            # Uhr nicht abgeglichen, Kerberos laeuft aber: der Domaenenbeitritt
            # beweist eine Abweichung unter 5 Minuten - unkritisch.
            *"Uhrzeit nicht abgeglichen, Kerberos"*) ntp=warn ;;
            *"WARNUNG: Zeitserver "*"nicht erreichbar"*) ntp=failed ;;
            *"WARNUNG: Uhrzeit weicht um "*) ntp=failed ;;
            *"WARNUNG: Uhrzeit nicht geprueft"*) ntp=failed ;;
        esac
        case "$line" in
            *WARNUNG*|*FEHLER*) notes="${notes:+$notes
}${line}" ;;
        esac
    done <<< "$lines"
    [ "$join_ok" = true ] && join=ok
    [ "$join_failed" = true ] && join=failed
    if [ "$active" = true ] && [ "$join_failed" = false ]; then
        join=ok
    fi
    FACT_ACTIVE[$service]="$active"
    FACT_ENABLED[$service]="$enabled"
    FACT_DOMAIN[$service]="$domain"
    FACT_KERBEROS[$service]="$kerberos"
    FACT_JOIN[$service]="$join"
    FACT_NTP[$service]="$ntp"
    FACT_NOTES[$service]="$notes"
}

# Zustand aller Instanzen als JSON-Feld.
instances_json() {
    local services="" service="" id="" state="" health="" info="" first=1
    services="$(auth_services)"
    printf '['
    while IFS= read -r service; do
        [ -n "$service" ] || continue
        state=missing
        health=""
        id="$(compose ps -q "$service" 2>/dev/null | head -n 1)" || id=""
        if [ -n "$id" ]; then
            state=unknown
            info="$(docker inspect -f '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}-{{end}}' "$id" 2>/dev/null)" || info=""
            if [ -n "$info" ]; then
                state="${info%%|*}"
                health="${info#*|}"
                [ "$health" = "-" ] && health=""
                read_facts "$service" "$id" || true
            fi
        fi
        [ "$first" -eq 1 ] || printf ','
        first=0
        jq -nc --arg service "$service" --arg state "$state" --arg health "$health" \
            --arg domain "${FACT_DOMAIN[$service]:-}" \
            --argjson active "${FACT_ACTIVE[$service]:-false}" \
            --argjson enabled "${FACT_ENABLED[$service]:-null}" \
            --argjson kerberos "${FACT_KERBEROS[$service]:-false}" \
            --arg join "${FACT_JOIN[$service]:-unknown}" \
            --arg ntp "${FACT_NTP[$service]:-}" \
            --arg notes "${FACT_NOTES[$service]:-}" \
            '{service:$service, state:$state, health:$health, domain:$domain, active:$active,
              enabled:$enabled, kerberos:$kerberos, join:$join, ntp:$ntp,
              notes:($notes | if . == "" then [] else split("\n") end)}'
    done <<< "$services"
    printf ']'
}

# Zustand schreiben; die Anwendung liest die Datei ueber das Admininterface.
write_status() {
    local tmp="${CONTROL_DIR}/.status.$$.tmp" instances=""
    mkdir -p "$CONTROL_DIR"
    instances="$(instances_json 2>/dev/null)" || instances=""
    if ! printf '%s' "$instances" | jq -e . >/dev/null 2>&1; then
        instances="[]"
    fi
    jq -n \
        --arg state "$1" --arg action "$2" --arg message "$3" --arg id "${4:-}" \
        --arg updated "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg project "$PROJECT" --arg compose_file "$(env_compose_file)" \
        --argjson instances "$instances" \
        '{state:$state, action:$action, message:$message, request_id:$id,
          updated_at:$updated, project:$project, compose_file:$compose_file,
          instances:$instances}' > "$tmp"
    chmod 0644 "$tmp"
    mv -f "$tmp" "${CONTROL_DIR}/status.json"
}

# Zustand neu einlesen und dabei den bisherigen Zustand beibehalten.
refresh_status() {
    write_status "$(status_field state idle)" "$(status_field action '')" \
        "$(status_field message '')" "$(status_field request_id '')"
}

# Neue oder geaenderte Identitaetsquellen anwenden.
do_apply() {
    local id="${1:-}" out="" rc=0 services=() placeholder=0
    if [ ! -x "$PROJECT_DIR/scripts/sso-domains.sh" ]; then
        write_status failed apply "scripts/sso-domains.sh fehlt in ${PROJECT_DIR} - bitte die Intranet-Dateien aktualisieren." "$id"
        return 1
    fi

    # COMPOSE_FILE vorab pflegen: der Generator prueft am Ende selbst, ob die
    # erzeugten Dienste im Compose-Projekt liegen. Fehlt die Datei noch, wird
    # ein gueltiger Platzhalter angelegt (der Generator ersetzt oder entfernt
    # ihn) - sonst waere COMPOSE_FILE kurzzeitig ungueltig.
    if [ ! -f "$PROJECT_DIR/$COMPOSE_ENTRY" ]; then
        printf '%s\n' '# Platzhalter - wird von scripts/sso-domains.sh ersetzt.' 'services: {}' \
            > "$PROJECT_DIR/$COMPOSE_ENTRY"
        placeholder=1
    fi
    ensure_compose_file "$(compose_file_with add)"

    log "Identitaetsquellen anwenden ..."
    rc=0
    out=$( cd "$PROJECT_DIR" && COMPOSE_PROJECT_NAME="$PROJECT" ./scripts/sso-domains.sh 2>&1 ) || rc=$?
    printf '%s\n' "$out"
    if [ "$rc" -ne 0 ]; then
        # Nur wenn der Generator nichts erzeugt hat (unser Platzhalter steht
        # noch), wird der Platzhalter samt Eintrag wieder entfernt.
        if [ "$placeholder" -eq 1 ] && grep -q '^# Platzhalter' "$PROJECT_DIR/$COMPOSE_ENTRY" 2>/dev/null; then
            rm -f "$PROJECT_DIR/$COMPOSE_ENTRY"
            case "$(env_compose_file)" in
                *"$COMPOSE_ENTRY"*) ensure_compose_file "$(compose_file_with remove)" ;;
            esac
        fi
        write_status failed apply "$(summarize "$out")" "$id"
        return 1
    fi

    # Ohne weitere Domaene entfernt der Generator die Datei; dann gehoert der
    # Eintrag auch nicht mehr in COMPOSE_FILE.
    if [ ! -f "$PROJECT_DIR/$COMPOSE_ENTRY" ]; then
        case "$(env_compose_file)" in
            *"$COMPOSE_ENTRY"*) ensure_compose_file "$(compose_file_with remove)" ;;
        esac
    fi

    mapfile -t services < <(auth_services)
    if [ "${#services[@]}" -eq 0 ]; then
        write_status failed apply "Keine auth-Instanz im Compose-Projekt gefunden - bitte die Compose-Dateien pruefen." "$id"
        return 1
    fi

    # --no-deps: nur die auth-Instanzen anfassen. Die Anwendung laeuft bereits
    # (depends_on), und so werden keine anderen Dienste neu erstellt.
    log "Bauen und starten: ${services[*]}"
    rc=0
    out=$(compose_timeout "$APPLY_TIMEOUT" up -d --build --no-deps --remove-orphans -- "${services[@]}" 2>&1) || rc=$?
    printf '%s\n' "$out"
    if [ "$rc" -ne 0 ]; then
        write_status failed apply "$(summarize "$out")" "$id"
        return 1
    fi

    log "Neu starten (Konfiguration und Verteilung neu laden): ${services[*]}"
    rc=0
    out=$(compose_timeout 300 restart -- "${services[@]}" 2>&1) || rc=$?
    printf '%s\n' "$out"
    if [ "$rc" -ne 0 ]; then
        write_status failed apply "$(summarize "$out")" "$id"
        return 1
    fi

    write_status "done" apply "Windows-Anmeldung angewendet (${#services[@]} Instanz(en))." "$id"
    log "Fertig."
    return 0
}

# Auftrag des Adminbereichs abarbeiten.
process_request() {
    local request="${CONTROL_DIR}/request.json" processing="${CONTROL_DIR}/.request.processing"
    local action="" id=""
    [ -f "$request" ] || return 0
    mv -f "$request" "$processing" 2>/dev/null || return 0
    action="$(jq -r '.action // ""' "$processing" 2>/dev/null)" || action=""
    id="$(jq -r '.id // ""' "$processing" 2>/dev/null | tr -cd 'a-zA-Z0-9-' | cut -c1-64)" || id=""
    rm -f "$processing"
    case "$action" in
        apply)
            write_status running apply "Windows-Anmeldung wird angewendet (Bauen und Neustart der Instanzen) ..." "$id"
            do_apply "$id" || true
            ;;
        refresh)
            write_status "$(status_field state idle)" refresh "$(status_field message '')" "$id"
            ;;
        *)
            write_status failed "$action" "Unbekannter Auftrag." "$id"
            ;;
    esac
}

agent() {
    mkdir -p "$CONTROL_DIR"
    # Der Adminbereich (uid 33) muss Auftragsdateien anlegen koennen.
    chown "${APP_UID}:${APP_UID}" "$CONTROL_DIR" 2>/dev/null || true
    chmod 0775 "$CONTROL_DIR"
    # Ein abgebrochener Lauf darf nicht als "laeuft" stehen bleiben.
    if [ "$(status_field state idle)" = "running" ]; then
        write_status failed apply "Der vorherige Lauf wurde unterbrochen." "$(status_field request_id '')"
    else
        refresh_status
    fi
    log "Bereit (Projekt ${PROJECT}, Auftraege in ${CONTROL_DIR})."
    local last_status=0 now
    while true; do
        touch /tmp/sso-agent-alive
        process_request || true
        now=$(date +%s)
        if [ "$((now - last_status))" -ge "$STATUS_SECONDS" ]; then
            last_status="$now"
            refresh_status || true
        fi
        sleep "$POLL_SECONDS"
    done
}

PROJECT="$(project_name)"

case "${1:-agent}" in
    agent) agent ;;
    apply) do_apply "cli" ;;
    status)
        refresh_status
        jq . "${CONTROL_DIR}/status.json"
        ;;
    *)
        printf 'Verwendung: sso-agent {agent|apply|status}\n' >&2
        exit 2
        ;;
esac
