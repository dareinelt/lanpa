#!/bin/sh
# Assistierte Komplettinstallation der Intranet-Landingpage (pseudo-grafisch).
#
#   ./scripts/install.sh
#
# Ablauf:
#   1. Voraussetzungen pruefen (curl, Dialog-Werkzeug, Docker, Compose, Dienst,
#      Rechte, Speicher) und fehlende Pakete automatisch nachinstallieren.
#   2. .env automatisch aus .env.example kopieren (bestehende .env wird auf
#      Wunsch gesichert oder weiterverwendet, fehlende Schluessel ergaenzt).
#   3. Gefuehrte Abfrage aller Einstellungen, Passwoerter und Secrets
#      (Datenbank, Administrator, AD/LDAP, Windows-Anmeldung, SMS-Gateway,
#      SNMP, Office, phpMyAdmin, systemd-Autostart) und Eintrag in die .env.
#   4. Container bauen und starten, auf Betriebsbereitschaft warten,
#      Administrationskonto setzen, optional Autostart einrichten.
#   5. Abschlussbericht anzeigen und als Markdown-Protokoll ablegen
#      (./install-reports/, nie versioniert) - geeignet fuer die Doku.
#
# Oberflaeche: whiptail oder dialog (wird bei Bedarf installiert), sonst
# Textmodus. Das Skript ist idempotent und kann erneut ausgefuehrt werden.
#
# Optionen:
#   --defaults       keine Rueckfragen: Standardwerte, Zufallspasswoerter
#   --text           Textmodus erzwingen (ohne whiptail/dialog)
#   --no-deps        Paketinstallation ueberspringen (nur pruefen)
#   --no-start       nur konfigurieren, keine Container starten
#   --with-secrets   Zugangsdaten im Klartext in das Protokoll aufnehmen
#   -h, --help       diese Hilfe anzeigen
#
# Siehe docs/installation.md.
set -u

SCRIPT_VERSION="1.0.0"
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR" || exit 1

NONINTERACTIVE=0
FORCE_TEXT=0
INSTALL_DEPS=1
START=1
INCLUDE_SECRETS=""
for arg in "$@"; do
    case "$arg" in
        --defaults|-y) NONINTERACTIVE=1 ;;
        --text) FORCE_TEXT=1 ;;
        --no-deps) INSTALL_DEPS=0 ;;
        --no-start) START=0 ;;
        --with-secrets) INCLUDE_SECRETS=1 ;;
        -h|--help) sed -n '2,31p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unbekannte Option: $arg (Hilfe: $0 --help)" >&2; exit 2 ;;
    esac
done

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p storage/logs
LOG_FILE="$ROOT_DIR/storage/logs/install-$TIMESTAMP.log"
: > "$LOG_FILE"
chmod 600 "$LOG_FILE" 2>/dev/null || true
REPORT_DIR="$ROOT_DIR/install-reports"
REPORT_FILE="$REPORT_DIR/installation-$TIMESTAMP.md"
TMP_DIR="$(mktemp -d 2>/dev/null || mktemp -d -t intranet-install)"
BACKTITLE="Intranet-Landingpage - Assistierte Installation v$SCRIPT_VERSION"
INSTALLED_PKGS=""
ENV_BACKUP=""
KEEPALIVE_PID=""

cleanup() {
    [ -n "$KEEPALIVE_PID" ] && kill "$KEEPALIVE_PID" 2>/dev/null
    rm -rf "$TMP_DIR"
    stty echo 2>/dev/null || true
}
trap cleanup EXIT
trap 'echo; echo "Abgebrochen."; exit 130' INT TERM

log() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >> "$LOG_FILE"; }
run_logged() { log "+ $*"; "$@" >> "$LOG_FILE" 2>&1; }
have() { command -v "$1" >/dev/null 2>&1; }

# =============================================================================
# Oberflaeche (whiptail | dialog | text)
# =============================================================================
UI="text"
H=20
W=76

ui_init() {
    lines="$(tput lines 2>/dev/null || echo 24)"
    cols="$(tput cols 2>/dev/null || echo 80)"
    H=$((lines - 4)); [ "$H" -gt 22 ] && H=22
    W=$((cols - 4)); [ "$W" -gt 78 ] && W=78
    UI="text"
    if [ "$FORCE_TEXT" = "0" ] && [ -t 1 ] && [ "$H" -ge 16 ] && [ "$W" -ge 60 ]; then
        if have whiptail; then UI="whiptail"; elif have dialog; then UI="dialog"; fi
    fi
    LIST_H=$((H - 9)); [ "$LIST_H" -lt 4 ] && LIST_H=4
    if [ "$UI" = "whiptail" ]; then
        L_YES="--yes-button"; L_NO="--no-button"; L_OK="--ok-button"; L_CANCEL="--cancel-button"
    else
        L_YES="--yes-label"; L_NO="--no-label"; L_OK="--ok-label"; L_CANCEL="--cancel-label"
    fi
    log "Oberflaeche: $UI (${W}x${H})"
}

ui_run() { "$UI" --backtitle "$BACKTITLE" "$@" 3>&1 1>&2 2>&3; }

tty_read() {
    # Liest eine Zeile vom Terminal (auch wenn stdin umgeleitet ist).
    if [ -r /dev/tty ]; then IFS= read -r REPLY < /dev/tty || REPLY=""; else IFS= read -r REPLY || REPLY=""; fi
}

text_header() { printf '\n\033[1;36m== %s ==\033[0m\n' "$1"; }

ui_msg() { # titel text
    log "[msg] $1"
    [ "$NONINTERACTIVE" = "1" ] && { printf '%s\n' "$2" >> "$LOG_FILE"; return 0; }
    if [ "$UI" = "text" ]; then
        text_header "$1"; printf '%s\n' "$2"; printf '[Enter] weiter '; tty_read
    else
        if [ "$UI" = "whiptail" ]; then
            ui_run --title "$1" "$L_OK" "OK" --scrolltext --msgbox "$2" "$H" "$W" >/dev/null
        else
            ui_run --title "$1" "$L_OK" "OK" --msgbox "$2" "$H" "$W" >/dev/null
        fi
    fi
}

ui_info() { # titel text (nicht blockierend)
    log "[info] $1: $2"
    if [ "$UI" = "text" ] || [ "$NONINTERACTIVE" = "1" ]; then
        printf '\033[1m[install]\033[0m %s\n' "$2"
    else
        "$UI" --backtitle "$BACKTITLE" --title "$1" --infobox "$2" 8 "$W"
    fi
}

ui_yesno() { # titel text [yes|no] -> rc 0 = Ja
    default="${3:-yes}"
    if [ "$NONINTERACTIVE" = "1" ]; then [ "$default" = "yes" ]; return; fi
    if [ "$UI" = "text" ]; then
        text_header "$1"; printf '%s\n' "$2"
        if [ "$default" = "yes" ]; then hint="[J/n]"; else hint="[j/N]"; fi
        while :; do
            printf '%s ' "$hint"; tty_read
            case "$REPLY" in
                '') [ "$default" = "yes" ]; return ;;
                j|J|ja|Ja|y|Y|yes) return 0 ;;
                n|N|nein|Nein|no) return 1 ;;
            esac
        done
    fi
    if [ "$default" = "no" ]; then
        ui_run --title "$1" "$L_YES" "Ja" "$L_NO" "Nein" --defaultno --yesno "$2" "$H" "$W" >/dev/null
    else
        ui_run --title "$1" "$L_YES" "Ja" "$L_NO" "Nein" --yesno "$2" "$H" "$W" >/dev/null
    fi
}

ui_input() { # titel text vorgabe -> REPLY, rc 1 = Abbruch
    REPLY="$3"
    [ "$NONINTERACTIVE" = "1" ] && return 0
    if [ "$UI" = "text" ]; then
        text_header "$1"; printf '%s\n' "$2"; printf '[%s]: ' "$3"; tty_read
        [ -z "$REPLY" ] && REPLY="$3"
        return 0
    fi
    REPLY="$(ui_run --title "$1" "$L_OK" "OK" "$L_CANCEL" "Abbrechen" --inputbox "$2" "$H" "$W" "$3")"
}

ui_password() { # titel text -> REPLY, rc 1 = Abbruch
    REPLY=""
    [ "$NONINTERACTIVE" = "1" ] && return 0
    if [ "$UI" = "text" ]; then
        text_header "$1"; printf '%s\n' "$2"; printf 'Eingabe (verdeckt): '
        stty -echo 2>/dev/null; tty_read; stty echo 2>/dev/null; printf '\n'
        return 0
    fi
    if [ "$UI" = "dialog" ]; then
        REPLY="$(ui_run --title "$1" --insecure "$L_OK" "OK" "$L_CANCEL" "Abbrechen" --passwordbox "$2" "$H" "$W")"
    else
        REPLY="$(ui_run --title "$1" "$L_OK" "OK" "$L_CANCEL" "Abbrechen" --passwordbox "$2" "$H" "$W")"
    fi
}

ui_menu() { # titel text vorgabe tag eintrag [tag eintrag ...] -> REPLY
    title="$1"; text="$2"; default="$3"; shift 3
    REPLY="$default"
    [ "$NONINTERACTIVE" = "1" ] && return 0
    if [ "$UI" = "text" ]; then
        text_header "$title"; printf '%s\n' "$text"
        i=1; set -- "$@"
        while [ "$#" -ge 2 ]; do
            mark=" "; [ "$1" = "$default" ] && mark="*"
            printf ' %s %d) %s\n' "$mark" "$i" "$2"
            eval "menu_tag_$i=\$1"
            i=$((i + 1)); shift 2
        done
        while :; do
            printf 'Auswahl [Enter = *]: '; tty_read
            [ -z "$REPLY" ] && { REPLY="$default"; return 0; }
            case "$REPLY" in *[!0-9]*) continue ;; esac
            if [ "$REPLY" -ge 1 ] && [ "$REPLY" -lt "$i" ]; then eval "REPLY=\$menu_tag_$REPLY"; return 0; fi
        done
    fi
    REPLY="$(ui_run --title "$title" "$L_OK" "OK" "$L_CANCEL" "Abbrechen" --default-item "$default" \
        --menu "$text" "$H" "$W" "$LIST_H" "$@")"
}

ui_checklist() { # titel text tag eintrag ON|OFF ... -> REPLY (Leerzeichen-getrennt)
    title="$1"; text="$2"; shift 2
    if [ "$NONINTERACTIVE" = "1" ] || [ "$UI" = "text" ]; then
        [ "$UI" = "text" ] && [ "$NONINTERACTIVE" = "0" ] && { text_header "$title"; printf '%s\n' "$text"; }
        result=""
        while [ "$#" -ge 3 ]; do
            if [ "$NONINTERACTIVE" = "1" ]; then
                [ "$3" = "ON" ] && result="$result $1"
            else
                if [ "$3" = "ON" ]; then hint="[J/n]"; else hint="[j/N]"; fi
                printf '  %s? %s ' "$2" "$hint"; tty_read
                case "$REPLY" in
                    j|J|ja|y|Y) result="$result $1" ;;
                    '') [ "$3" = "ON" ] && result="$result $1" ;;
                esac
            fi
            shift 3
        done
        REPLY="$result"
        return 0
    fi
    REPLY="$(ui_run --title "$title" "$L_OK" "OK" "$L_CANCEL" "Abbrechen" --separate-output \
        --checklist "$text" "$H" "$W" "$LIST_H" "$@")" || return 1
    REPLY="$(printf '%s' "$REPLY" | tr -d '"' | tr '\n' ' ')"
}

ui_textbox() { # titel datei
    if [ "$NONINTERACTIVE" = "1" ]; then text_header "$1"; cat "$2"; return 0; fi
    if [ "$UI" = "text" ]; then
        text_header "$1"; cat "$2"; printf '\n[Enter] weiter '; tty_read
    elif [ "$UI" = "whiptail" ]; then
        ui_run --title "$1" "$L_OK" "Weiter" --scrolltext --textbox "$2" "$H" "$W" >/dev/null
    else
        ui_run --title "$1" "$L_OK" "Weiter" --textbox "$2" "$H" "$W" >/dev/null
    fi
}

ui_gauge() { # titel text  (liest "XXX\n<prozent>\n<text>\nXXX" von stdin)
    if [ "$UI" = "text" ] || [ "$NONINTERACTIVE" = "1" ]; then
        last=""
        while IFS= read -r line; do
            case "$line" in
                XXX|[0-9]|[0-9][0-9]|100) ;;
                *) [ "$line" != "$last" ] && printf '  %s\n' "$line"; last="$line" ;;
            esac
        done
    else
        "$UI" --backtitle "$BACKTITLE" --title "$1" --gauge "$2" 9 "$W" 0
    fi
}

confirm_abort() {
    if [ "$NONINTERACTIVE" = "1" ] || ui_yesno "Abbrechen?" "Installation wirklich abbrechen?

Bereits geschriebene Dateien (.env) bleiben erhalten." no; then
        [ "$UI" != "text" ] && clear
        echo "Installation abgebrochen. Protokoll: $LOG_FILE"
        exit 1
    fi
}

ask_input() {
    while :; do
        if ui_input "$@"; then
            case "$REPLY" in
                *"'"*) ui_msg "Ungueltiges Zeichen" "Einfache Anfuehrungszeichen (') sind nicht erlaubt." ;;
                *) return 0 ;;
            esac
        else confirm_abort; fi
    done
}
ask_menu() { while :; do ui_menu "$@" && return 0; confirm_abort; done; }
ask_checklist() { while :; do ui_checklist "$@" && return 0; confirm_abort; done; }

# =============================================================================
# Hilfsfunktionen .env / Secrets / Validierung
# =============================================================================
random_secret() { # [laenge]
    # Begrenzte Lesemenge statt Endlos-Pipe: funktioniert auch, wenn SIGPIPE ignoriert wird.
    len="${1:-32}"; out=""
    while [ "${#out}" -lt "$len" ]; do
        out="$out$(head -c 512 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9')"
    done
    printf '%s' "$out" | cut -c1-"$len"
}

env_get() {
    [ -f .env ] || return 0
    sed -n "s/^$1=//p" .env | tail -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

env_or() { # schluessel vorgabe
    v="$(env_get "$1")"
    if [ -n "$v" ]; then printf '%s' "$v"; else printf '%s' "$2"; fi
}

env_quote() {
    # Werte mit Sonderzeichen in einfache Anfuehrungszeichen setzen: Docker
    # Compose interpretiert sie dann woertlich (kein $-Ersetzen, kein #-Kommentar).
    case "$1" in
        *[[:space:]\#\$\"\\\`]*) printf "'%s'" "$1" ;;
        *) printf '%s' "$1" ;;
    esac
}

env_set() { # schluessel wert
    ENV_LINE="$1=$(env_quote "$2")"
    export ENV_LINE
    awk -v k="$1" 'BEGIN { done = 0 }
        index($0, k "=") == 1 { if (!done) print ENVIRON["ENV_LINE"]; done = 1; next }
        { print }
        END { if (!done) print ENVIRON["ENV_LINE"] }' .env > "$TMP_DIR/env" && cat "$TMP_DIR/env" > .env
    unset ENV_LINE
    log "env: $1 gesetzt"
}

env_del() { # schluessel...
    for k in "$@"; do
        grep -q "^$k=" .env 2>/dev/null || continue
        awk -v k="$k" 'index($0, k "=") != 1 { print }' .env > "$TMP_DIR/env" && cat "$TMP_DIR/env" > .env
        log "env: $k entfernt"
    done
}

env_merge_missing() {
    # Ergaenzt Schluessel, die in .env.example neu hinzugekommen sind.
    added=""
    while IFS= read -r line; do
        case "$line" in
            [A-Z]*=*)
                key="${line%%=*}"
                if ! grep -q "^${key}=" .env; then
                    printf '%s\n' "$line" >> .env
                    added="$added $key"
                fi ;;
        esac
    done < .env.example
    [ -n "$added" ] && log "env: aus .env.example ergaenzt:$added"
    MERGED_KEYS="$added"
}

env_prev() { # schluessel -> aktueller Wert, sonst Wert aus der gesicherten .env
    v="$(env_get "$1")"
    if is_placeholder "$v" && [ -n "$ENV_BACKUP" ] && [ -f "$ENV_BACKUP" ]; then
        v="$(sed -n "s/^$1=//p" "$ENV_BACKUP" | tail -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/")"
    fi
    printf '%s' "$v"
}

is_placeholder() { case "$1" in ''|bitte-aendern*|public) return 0 ;; esac; return 1; }
is_port() { case "$1" in ''|*[!0-9]*) return 1 ;; esac; [ "$1" -ge 1 ] && [ "$1" -le 65535 ]; }
is_true() { case "$1" in 1|true|TRUE|yes|on) return 0 ;; esac; return 1; }
valid_secret() { # Einfache Anfuehrungszeichen und Zeilenumbrueche sind nicht erlaubt.
    case "$1" in *"'"*) return 1 ;; esac
    [ "$(printf '%s' "$1" | wc -l | tr -d ' ')" = "0" ]
}

ask_port() { # titel text vorgabe -> REPLY
    while :; do
        ask_input "$1" "$2" "$3"
        is_port "$REPLY" && return 0
        ui_msg "Ungueltiger Port" "\"$REPLY\" ist kein gueltiger Port (1-65535)."
    done
}

ask_required() { # titel text vorgabe -> REPLY (nicht leer)
    while :; do
        ask_input "$1" "$2" "$3"
        [ -n "$REPLY" ] && return 0
        [ "$NONINTERACTIVE" = "1" ] && return 0
        ui_msg "Pflichtfeld" "Dieser Wert darf nicht leer sein."
    done
}

ask_secret() { # titel beschreibung aktueller_wert minlaenge erzeugbar(1|0) -> REPLY
    title="$1"; desc="$2"; current="$3"; minlen="${4:-1}"; gen="${5:-1}"
    default="gen"
    set --
    if [ -n "$current" ] && ! is_placeholder "$current"; then
        default="keep"; set -- keep "Bestehenden Wert beibehalten"
    fi
    [ "$gen" = "1" ] && set -- "$@" gen "Zufaellig erzeugen (empfohlen)"
    set -- "$@" manual "Selbst eingeben"
    [ "$gen" = "0" ] && set -- "$@" empty "Leer lassen (spaeter eintragen)"
    if [ "$gen" = "0" ] && [ "$default" != "keep" ]; then
        if [ "$NONINTERACTIVE" = "1" ]; then default="empty"; else default="manual"; fi
    fi
    ask_menu "$title" "$desc" "$default" "$@"
    case "$REPLY" in
        keep) REPLY="$current"; return 0 ;;
        gen) REPLY="$(random_secret 32)"; return 0 ;;
        empty) REPLY=""; return 0 ;;
    esac
    while :; do
        while :; do ui_password "$title" "$desc

Bitte eingeben (mind. $minlen Zeichen):" && break; confirm_abort; done
        first="$REPLY"
        if [ "${#first}" -lt "$minlen" ]; then ui_msg "Zu kurz" "Mindestens $minlen Zeichen erforderlich."; continue; fi
        if ! valid_secret "$first"; then ui_msg "Ungueltiges Zeichen" "Einfache Anfuehrungszeichen (') und Zeilenumbrueche sind nicht erlaubt."; continue; fi
        while :; do ui_password "$title" "Zur Bestaetigung erneut eingeben:" && break; confirm_abort; done
        if [ "$REPLY" = "$first" ]; then return 0; fi
        ui_msg "Keine Uebereinstimmung" "Die Eingaben stimmen nicht ueberein. Bitte erneut versuchen."
    done
}

# =============================================================================
# System- und Paketverwaltung
# =============================================================================
OS_KIND="$(uname -s)"
OS_ID=""; OS_LIKE=""; OS_NAME="$OS_KIND"
PKG=""
SUDO=""
APT_UPDATED=0

detect_system() {
    if [ "$OS_KIND" = "Linux" ] && [ -r /etc/os-release ]; then
        # shellcheck disable=SC1091
        OS_ID="$(. /etc/os-release; echo "${ID:-}")"
        OS_LIKE="$(. /etc/os-release; echo "${ID_LIKE:-}")"
        OS_NAME="$(. /etc/os-release; echo "${PRETTY_NAME:-Linux}")"
    elif [ "$OS_KIND" = "Darwin" ]; then
        OS_ID="macos"; OS_NAME="macOS $(sw_vers -productVersion 2>/dev/null)"
    fi
    for p in apt-get dnf yum zypper pacman apk brew; do
        if have "$p"; then PKG="$p"; break; fi
    done
    if [ "$(id -u)" -ne 0 ] && [ "$PKG" != "brew" ]; then
        have sudo && SUDO="sudo"
    fi
    log "System: $OS_NAME ($OS_KIND, id=$OS_ID like=$OS_LIKE), Paketverwaltung: ${PKG:-keine}, sudo: ${SUDO:-nein}"
}

ensure_root_access() {
    [ "$(id -u)" -eq 0 ] && return 0
    [ "$PKG" = "brew" ] && return 0
    if [ -z "$SUDO" ]; then
        ui_msg "Keine Root-Rechte" "Fuer die Paketinstallation werden root-Rechte benoetigt, 'sudo' ist aber nicht vorhanden.

Bitte als root ausfuehren oder die fehlenden Pakete manuell installieren."
        return 1
    fi
    if ! sudo -n true 2>/dev/null; then
        [ "$UI" != "text" ] && clear
        printf '\n\033[1m[install]\033[0m Fuer die Installation werden Administratorrechte (sudo) benoetigt.\n'
        sudo -v || return 1
    fi
    if [ -z "$KEEPALIVE_PID" ]; then
        ( while kill -0 $$ 2>/dev/null; do sudo -n true 2>/dev/null; sleep 50; done ) &
        KEEPALIVE_PID=$!
    fi
}

pkg_install() { # pakete...
    [ "$#" -eq 0 ] && return 0
    case "$PKG" in
        apt-get)
            if [ "$APT_UPDATED" = "0" ]; then run_logged $SUDO env DEBIAN_FRONTEND=noninteractive apt-get update -q; APT_UPDATED=1; fi
            run_logged $SUDO env DEBIAN_FRONTEND=noninteractive apt-get install -y -q "$@" ;;
        dnf) run_logged $SUDO dnf install -y "$@" ;;
        yum) run_logged $SUDO yum install -y "$@" ;;
        zypper) run_logged $SUDO zypper --non-interactive install "$@" ;;
        pacman) run_logged $SUDO pacman -Sy --noconfirm --needed "$@" ;;
        apk) run_logged $SUDO apk add --no-cache "$@" ;;
        brew) run_logged brew install "$@" ;;
        *) return 1 ;;
    esac
    rc=$?
    [ "$rc" -eq 0 ] && INSTALLED_PKGS="$INSTALLED_PKGS $*"
    return "$rc"
}

pkg_name() { # logischer_name -> paketname fuer $PKG
    case "$1:$PKG" in
        ui:apt-get) echo whiptail ;;
        ui:pacman) echo libnewt ;;
        ui:*) echo newt ;;
        curl:apt-get) echo "curl ca-certificates" ;;
        curl:*) echo curl ;;
        awk:apt-get|awk:apk) echo gawk ;;
        awk:*) echo gawk ;;
    esac
}

bootstrap_ui() {
    # Dialog-Werkzeug vor dem Start der Oberflaeche nachinstallieren.
    [ "$FORCE_TEXT" = "1" ] || [ "$NONINTERACTIVE" = "1" ] && return 0
    have whiptail || have dialog && return 0
    [ "$INSTALL_DEPS" = "1" ] && [ -n "$PKG" ] && [ -t 1 ] || return 0
    printf '\033[1m[install]\033[0m Installiere Dialog-Werkzeug (%s) fuer die grafische Oberflaeche ...\n' "$(pkg_name ui)"
    ensure_root_access || return 0
    # shellcheck disable=SC2046
    pkg_install $(pkg_name ui) || printf 'Hinweis: Installation fehlgeschlagen, verwende Textmodus.\n'
}

# --- Docker ---------------------------------------------------------------------
DOCKER="docker"
COMPOSE=""
COMPOSE_KIND=""
COMPOSE_UP_OPTS=""
BUILDKIT_PROGRESS=plain
export BUILDKIT_PROGRESS

detect_compose() {
    COMPOSE=""; COMPOSE_KIND=""
    if have docker && $DOCKER compose version >/dev/null 2>&1; then
        COMPOSE="$DOCKER compose"; COMPOSE_KIND="plugin"; COMPOSE_UP_OPTS="--ansi never --progress plain"
    elif have docker-compose; then
        if [ "$DOCKER" = "docker" ]; then COMPOSE="docker-compose"; else COMPOSE="$SUDO docker-compose"; fi
        COMPOSE_KIND="standalone"
    elif [ "$DOCKER" = "docker" ] && [ -n "$SUDO" ] && have docker && sudo -n docker compose version >/dev/null 2>&1; then
        COMPOSE="sudo docker compose"; COMPOSE_KIND="plugin"; COMPOSE_UP_OPTS="--ansi never --progress plain"
    fi
}

docker_daemon_ok() { $DOCKER info >/dev/null 2>&1; }

detect_docker_access() {
    DOCKER="docker"
    have docker || return 1
    docker_daemon_ok && return 0
    if [ -n "$SUDO" ] && sudo -n docker info >/dev/null 2>&1; then DOCKER="sudo docker"; return 0; fi
    return 1
}

install_docker() {
    ui_info "Docker" "Installiere Docker Engine und Compose-Plugin ... (siehe $LOG_FILE)"
    case "$PKG" in
        apt-get)
            if [ "$OS_ID" = "ubuntu" ] && pkg_install docker.io docker-compose-v2; then return 0; fi
            install_docker_script ;;
        dnf|yum) install_docker_script ;;
        zypper) pkg_install docker docker-compose ;;
        pacman) pkg_install docker docker-compose ;;
        apk) pkg_install docker docker-cli-compose && run_logged $SUDO rc-update add docker default ;;
        brew) run_logged brew install --cask docker && INSTALLED_PKGS="$INSTALLED_PKGS docker-desktop" ;;
        *) return 1 ;;
    esac
}

install_docker_script() {
    # Offizielles Installationsskript von Docker (Docker CE + Compose-Plugin).
    have curl || pkg_install curl || return 1
    curl -fsSL https://get.docker.com -o "$TMP_DIR/get-docker.sh" >> "$LOG_FILE" 2>&1 || return 1
    run_logged $SUDO sh "$TMP_DIR/get-docker.sh" && INSTALLED_PKGS="$INSTALLED_PKGS docker-ce(get.docker.com)"
}

install_compose() {
    ui_info "Docker Compose" "Installiere Docker Compose ..."
    case "$PKG" in
        apt-get) pkg_install docker-compose-v2 || pkg_install docker-compose-plugin || pkg_install docker-compose ;;
        dnf|yum) pkg_install docker-compose-plugin ;;
        zypper|pacman) pkg_install docker-compose ;;
        apk) pkg_install docker-cli-compose ;;
        brew) pkg_install docker-compose ;;
        *) return 1 ;;
    esac
}

start_docker_daemon() {
    ui_info "Docker" "Starte den Docker-Dienst ..."
    if [ "$OS_KIND" = "Darwin" ]; then
        open -a Docker >> "$LOG_FILE" 2>&1 || return 1
    elif have systemctl; then
        run_logged $SUDO systemctl enable --now docker || run_logged $SUDO systemctl start docker
    elif have rc-service; then
        run_logged $SUDO rc-service docker start
    elif have service; then
        run_logged $SUDO service docker start
    fi
    i=0
    while [ "$i" -lt 60 ]; do
        docker_daemon_ok && return 0
        [ -n "$SUDO" ] && sudo -n docker info >/dev/null 2>&1 && { DOCKER="sudo docker"; return 0; }
        sleep 2; i=$((i + 1))
    done
    return 1
}

# =============================================================================
# 1. Voraussetzungen
# =============================================================================
CHECK_FILE="$TMP_DIR/checks.txt"
MISSING=""

status_line() { printf '  %-10s %-26s %s\n' "$1" "$2" "$3" >> "$CHECK_FILE"; }

run_checks() {
    : > "$CHECK_FILE"
    MISSING=""
    printf 'System: %s\nPaketverwaltung: %s\nVerzeichnis: %s\n\n' "$OS_NAME" "${PKG:-nicht erkannt}" "$ROOT_DIR" >> "$CHECK_FILE"

    for tool in sed awk grep tr head; do
        if have "$tool"; then status_line "[ OK ]" "$tool" "vorhanden"; else status_line "[FEHLT]" "$tool" "Basiswerkzeug"; MISSING="$MISSING $tool"; fi
    done
    if have curl; then status_line "[ OK ]" "curl" "$(curl --version 2>/dev/null | head -n1 | cut -d' ' -f1-2)"
    else status_line "[FEHLT]" "curl" "Download/Healthcheck"; MISSING="$MISSING curl"; fi

    if have whiptail; then status_line "[ OK ]" "whiptail" "Oberflaeche"
    elif have dialog; then status_line "[ OK ]" "dialog" "Oberflaeche"
    else status_line "[ -- ]" "whiptail/dialog" "optional (Textmodus aktiv)"; fi

    if have docker; then
        status_line "[ OK ]" "docker" "$(docker --version 2>/dev/null | sed 's/Docker version //')"
    else
        status_line "[FEHLT]" "docker" "Docker Engine"; MISSING="$MISSING docker"
    fi
    detect_docker_access >/dev/null 2>&1
    detect_compose
    if [ -n "$COMPOSE" ]; then
        status_line "[ OK ]" "docker compose" "$($COMPOSE version --short 2>/dev/null || $COMPOSE version 2>/dev/null | head -n1)"
    else
        status_line "[FEHLT]" "docker compose" "Compose v2 (Plugin)"; MISSING="$MISSING compose"
    fi
    if have docker; then
        if docker_daemon_ok; then status_line "[ OK ]" "Docker-Dienst" "laeuft"
        elif [ "$DOCKER" = "sudo docker" ]; then status_line "[ OK ]" "Docker-Dienst" "laeuft (Zugriff via sudo)"
        else status_line "[FEHLT]" "Docker-Dienst" "nicht erreichbar/gestoppt"; MISSING="$MISSING daemon"; fi
    fi

    free_kb="$(df -Pk "$ROOT_DIR" 2>/dev/null | awk 'NR==2 {print $4}')"
    if [ -n "$free_kb" ]; then
        free_gb=$((free_kb / 1024 / 1024))
        if [ "$free_gb" -ge 5 ]; then status_line "[ OK ]" "Speicherplatz" "${free_gb} GB frei"
        else status_line "[WARN]" "Speicherplatz" "${free_gb} GB frei (empfohlen >= 5 GB, Office >= 15 GB)"; fi
    fi
    mem_mb=""
    if [ -r /proc/meminfo ]; then mem_mb="$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo)"
    elif have sysctl; then mem_mb="$(( $(sysctl -n hw.memsize 2>/dev/null || echo 0) / 1024 / 1024 ))"; fi
    if [ -n "$mem_mb" ] && [ "$mem_mb" -gt 0 ]; then
        if [ "$mem_mb" -ge 2048 ]; then status_line "[ OK ]" "Arbeitsspeicher" "${mem_mb} MB"
        else status_line "[WARN]" "Arbeitsspeicher" "${mem_mb} MB (empfohlen >= 2 GB, Office >= 4 GB)"; fi
    fi
    for f in docker-compose.yml .env.example docker/php/Dockerfile; do
        if [ -f "$f" ]; then status_line "[ OK ]" "$f" "vorhanden"; else status_line "[FEHLT]" "$f" "Repository unvollstaendig"; MISSING="$MISSING repo"; fi
    done
    log "Pruefung: fehlend =${MISSING:- nichts}"
}

step_requirements() {
    ui_info "Pruefung" "Pruefe Voraussetzungen ..."
    run_checks
    if [ -z "$MISSING" ]; then
        printf '\nAlle Voraussetzungen sind erfuellt.\n' >> "$CHECK_FILE"
        ui_textbox "Schritt 1/5: Voraussetzungen" "$CHECK_FILE"
        return 0
    fi
    case "$MISSING" in *repo*)
        ui_textbox "Schritt 1/5: Voraussetzungen" "$CHECK_FILE"
        ui_msg "Fehler" "Das Repository ist unvollstaendig. Bitte das Skript im vollstaendigen Projektverzeichnis ausfuehren."
        exit 1 ;;
    esac
    printf '\nFehlend:%s\n\nDiese werden im naechsten Schritt automatisch installiert.\n' "$MISSING" >> "$CHECK_FILE"
    ui_textbox "Schritt 1/5: Voraussetzungen" "$CHECK_FILE"

    if [ "$INSTALL_DEPS" = "0" ]; then
        ui_msg "Abhaengigkeiten fehlen" "Fehlend:$MISSING

Mit --no-deps werden keine Pakete installiert. Bitte nachinstallieren und erneut starten."
        exit 1
    fi
    if [ -z "$PKG" ]; then
        ui_msg "Keine Paketverwaltung" "Es wurde keine unterstuetzte Paketverwaltung gefunden (apt, dnf, yum, zypper, pacman, apk, brew).

Bitte installieren:$MISSING"
        exit 1
    fi
    if [ "$OS_KIND" = "Darwin" ] && [ "$PKG" != "brew" ]; then
        ui_msg "Homebrew fehlt" "Unter macOS wird Homebrew (https://brew.sh) fuer die automatische Installation benoetigt."
        exit 1
    fi
    ui_yesno "Automatische Installation" "Folgende Komponenten fehlen und werden jetzt installiert:
$MISSING

Paketverwaltung: $PKG
Fortfahren?" yes || confirm_abort
    ensure_root_access || exit 1

    for item in $MISSING; do
        case "$item" in
            sed|grep|tr|head) pkg_install coreutils sed grep ;;
            awk) pkg_install gawk ;;
            curl) ui_info "Pakete" "Installiere curl ..."; pkg_install $(pkg_name curl) ;;
            docker) install_docker ;;
            compose) have docker && [ -z "$COMPOSE" ] && install_compose ;;
            daemon) : ;;
        esac
    done

    detect_docker_access || start_docker_daemon
    detect_docker_access
    detect_compose
    [ -z "$COMPOSE" ] && install_compose && detect_compose

    if [ "$OS_KIND" = "Linux" ] && [ "$(id -u)" -ne 0 ] && [ "$DOCKER" = "sudo docker" ] && getent group docker >/dev/null 2>&1; then
        if ui_yesno "Docker-Gruppe" "Der Benutzer '$(id -un)' ist nicht in der Gruppe 'docker'. Die Installation verwendet daher sudo.

Benutzer jetzt der Gruppe 'docker' hinzufuegen? (wirksam nach erneuter Anmeldung)" yes; then
            run_logged $SUDO usermod -aG docker "$(id -un)"
        fi
    fi

    run_checks
    if [ -n "$MISSING" ]; then
        printf '\nWeiterhin fehlend:%s\n' "$MISSING" >> "$CHECK_FILE"
        ui_textbox "Voraussetzungen - erneute Pruefung" "$CHECK_FILE"
        if [ "$OS_KIND" = "Darwin" ]; then
            ui_msg "Docker Desktop" "Bitte Docker Desktop einmalig starten, die Lizenzbedingungen bestaetigen und das Skript anschliessend erneut ausfuehren."
        else
            ui_msg "Installation fehlgeschlagen" "Nicht alle Abhaengigkeiten konnten installiert werden.

Details im Protokoll:
$LOG_FILE"
        fi
        exit 1
    fi
    printf '\nAlle Voraussetzungen sind jetzt erfuellt.\n' >> "$CHECK_FILE"
    ui_textbox "Voraussetzungen - erneute Pruefung" "$CHECK_FILE"
}

# =============================================================================
# 2. .env anlegen
# =============================================================================
ENV_STATE=""
MERGED_KEYS=""

step_env_file() {
    if [ ! -f .env ]; then
        cp .env.example .env
        ENV_STATE="neu aus .env.example kopiert"
    else
        ask_menu "Schritt 2/5: Konfigurationsdatei" "Es existiert bereits eine .env.

Wie soll verfahren werden?" keep \
            keep "Weiterverwenden (Werte als Vorgabe)" \
            new "Neu aus .env.example (alte wird gesichert)"
        if [ "$REPLY" = "new" ]; then
            ENV_BACKUP=".env.bak-$TIMESTAMP"
            cp .env "$ENV_BACKUP"; chmod 600 "$ENV_BACKUP"
            cp .env.example .env
            ENV_STATE="neu aus .env.example kopiert (Sicherung: $ENV_BACKUP)"
        else
            env_merge_missing
            ENV_STATE="bestehende .env weiterverwendet"
            [ -n "$MERGED_KEYS" ] && ENV_STATE="$ENV_STATE, ergaenzt:$MERGED_KEYS"
        fi
    fi
    chmod 600 .env
    log ".env: $ENV_STATE"
    ui_msg "Schritt 2/5: Konfigurationsdatei" ".env: $ENV_STATE

Im naechsten Schritt werden alle Einstellungen, Passwoerter und Secrets abgefragt und eingetragen."
}

# =============================================================================
# 3. Konfiguration abfragen
# =============================================================================
project_name() {
    name="$(env_get COMPOSE_PROJECT_NAME)"
    [ -z "$name" ] && name="${COMPOSE_PROJECT_NAME:-$(basename "$ROOT_DIR")}"
    printf '%s' "$name" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-'
}

existing_db_volume() {
    [ -n "$COMPOSE" ] || return 1
    $DOCKER volume inspect "$(project_name)_db_data" >/dev/null 2>&1
}

host_name() { hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost; }
host_ip() {
    if [ "$OS_KIND" = "Darwin" ]; then ipconfig getifaddr en0 2>/dev/null || echo 127.0.0.1
    else hostname -I 2>/dev/null | awk '{print $1}'; fi
}

M_LDAP=0; M_SSO=0; M_ALARM=0; M_OFFICE=0; M_PMA=0; M_SYSTEMD=0
C_ADMIN_USER=""; C_ADMIN_PW=""; OFFICE_ENCRYPT=1
# AD-Zugangsdaten werden nicht in die .env geschrieben, sondern nach dem Start
# verschluesselt in der Datenbank gespeichert (scripts/credentials.php).
C_LDAP_PW=""; C_SSO_DOMAIN=""; C_SSO_DCS=""; C_SSO_JOIN_USER=""; C_SSO_JOIN_PW=""; CRED_OK=0

step_config() {
    t="Schritt 3/5: Konfiguration"

    # --- Anwendung ---
    ask_input "$t - Anwendung" "Name der Anwendung (Seitentitel):" "$(env_get APP_NAME)"
    env_set APP_NAME "${REPLY:-Intranet}"

    ask_menu "$t - Betriebsart" "Betriebsart der Anwendung:" "$(env_or APP_ENV production)" \
        production "Produktivbetrieb (empfohlen)" \
        development "Entwicklung/Test (Fehlerdetails sichtbar)"
    env_set APP_ENV "$REPLY"
    if [ "$REPLY" = "development" ]; then env_set APP_DEBUG true; else env_set APP_DEBUG false; fi

    cur_port="$(env_get APP_PORT)"
    ask_port "$t - Port" "Port, unter dem die Seite erreichbar ist (HTTP):" "${cur_port:-8080}"
    app_port="$REPLY"; env_set APP_PORT "$app_port"

    cur_url="$(env_get APP_URL)"
    case "$cur_url" in ''|http://localhost:8080) cur_url="http://$(host_name):$app_port" ;; esac
    [ "$app_port" = "80" ] && [ "$cur_url" = "http://$(host_name):80" ] && cur_url="http://$(host_name)"
    while :; do
        ask_input "$t - Adresse" "Oeffentliche Adresse (APP_URL), wie Benutzer die Seite aufrufen:" "$cur_url"
        case "$REPLY" in http://*|https://*) break ;; esac
        ui_msg "Ungueltige Adresse" "Die Adresse muss mit http:// oder https:// beginnen."
    done
    env_set APP_URL "$REPLY"
    case "$REPLY" in
        https://*)
            if ui_yesno "$t - HTTPS" "Die Adresse verwendet HTTPS. Session-Cookies nur ueber HTTPS senden (APP_FORCE_SECURE_COOKIES)?" yes; then
                env_set APP_FORCE_SECURE_COOKIES true
            else env_set APP_FORCE_SECURE_COOKIES false; fi ;;
        *) env_set APP_FORCE_SECURE_COOKIES false ;;
    esac

    tz="$(env_get APP_TIMEZONE)"
    ask_input "$t - Zeitzone" "Zeitzone (z. B. Europe/Berlin):" "${tz:-Europe/Berlin}"
    env_set APP_TIMEZONE "${REPLY:-Europe/Berlin}"

    seed_default=yes; is_true "$(env_get SEED_ON_START)" || seed_default=no
    existing_db_volume && seed_default=no
    if ui_yesno "$t - Beispieldaten" "Beim Start Beispielnavigation und Standardeinstellungen anlegen (nur wenn die Navigation leer ist)?" "$seed_default"; then
        env_set SEED_ON_START true
    else env_set SEED_ON_START false; fi

    # --- Datenbank ---
    if existing_db_volume; then
        ui_msg "$t - Datenbank" "Es existiert bereits ein Datenbank-Volume ($(project_name)_db_data).

Die Datenbank-Passwoerter werden nur beim ersten Start uebernommen. Aendern Sie sie hier nur, wenn Sie das Volume zuvor geloescht haben - sonst ist die Datenbank nicht mehr erreichbar."
    fi
    ask_input "$t - Datenbank" "Name der Datenbank:" "$(env_or DB_NAME intranet)"; env_set DB_NAME "$REPLY"
    ask_input "$t - Datenbank" "Datenbank-Benutzer:" "$(env_or DB_USER intranet)"; env_set DB_USER "$REPLY"
    ask_secret "$t - Datenbank" "Passwort des Datenbank-Benutzers (DB_PASSWORD):" "$(env_prev DB_PASSWORD)" 12 1
    env_set DB_PASSWORD "$REPLY"
    ask_secret "$t - Datenbank" "Root-Passwort der Datenbank (DB_ROOT_PASSWORD):" "$(env_prev DB_ROOT_PASSWORD)" 12 1
    env_set DB_ROOT_PASSWORD "$REPLY"

    # --- Administrator ---
    while :; do
        ask_input "$t - Administrator" "Benutzername des ersten Administrationskontos
(3-64 Zeichen: A-Z a-z 0-9 . _ -):" "$(env_or ADMIN_USERNAME admin)"
        printf '%s' "$REPLY" | grep -Eq '^[A-Za-z0-9._-]{3,64}$' && break
        ui_msg "Ungueltiger Benutzername" "Erlaubt sind 3-64 Zeichen aus A-Z a-z 0-9 . _ -"
    done
    C_ADMIN_USER="$REPLY"; env_set ADMIN_USERNAME "$C_ADMIN_USER"
    ask_secret "$t - Administrator" "Passwort fuer '$C_ADMIN_USER' (mind. 12 Zeichen).
Bei bestehender Installation wird das Passwort damit zurueckgesetzt." "$(env_prev ADMIN_PASSWORD)" 12 1
    C_ADMIN_PW="$REPLY"; env_set ADMIN_PASSWORD "$C_ADMIN_PW"

    # --- Module ---
    on() { if [ "$1" = "1" ]; then echo ON; else echo OFF; fi; }
    ldap_on=0; [ -n "$(env_get LDAP_HOST)" ] && ldap_on=1
    sso_on=0; is_true "$(env_get SSO_ENABLED)" && sso_on=1
    alarm_on=0; [ -n "$(env_get ALARM_HOST)" ] && alarm_on=1
    office_on=0; is_true "$(env_get OFFICE_ENABLED)" && office_on=1
    set -- \
        LDAP "Active Directory / LDAP (Telefonliste, Gruppen)" "$(on $ldap_on)" \
        SSO "Automatische Windows-Anmeldung (NTLM)" "$(on $sso_on)" \
        ALARM "SMS-Gateway (Alarmierung, Zugangscodes)" "$(on $alarm_on)" \
        OFFICE "Office: Nextcloud + Euro-Office" "$(on $office_on)" \
        PMA "phpMyAdmin (Datenbankwerkzeug)" OFF
    if [ "$OS_KIND" = "Linux" ] && have systemctl && [ -d /run/systemd/system ]; then
        set -- "$@" SYSTEMD "Autostart beim Booten (systemd-Dienst)" ON
    fi
    ask_checklist "$t - Module" "Optionale Module auswaehlen (Leertaste = an/aus, Enter = bestaetigen):" "$@"
    for m in $REPLY; do
        case "$m" in LDAP) M_LDAP=1 ;; SSO) M_SSO=1 ;; ALARM) M_ALARM=1 ;; OFFICE) M_OFFICE=1 ;; PMA) M_PMA=1 ;; SYSTEMD) M_SYSTEMD=1 ;; esac
    done
    if [ "$M_SSO" = "1" ] && [ "$M_LDAP" = "0" ]; then
        ui_msg "$t - Hinweis" "Die Windows-Anmeldung benoetigt die AD-Anbindung. LDAP wird zusaetzlich aktiviert."
        M_LDAP=1
    fi

    [ "$M_LDAP" = "1" ] && config_ldap
    [ "$M_SSO" = "1" ] && config_sso
    [ "$M_SSO" = "1" ] || env_set SSO_ENABLED false
    if [ "$M_ALARM" = "1" ]; then config_alarm; fi
    config_snmp
    [ "$M_OFFICE" = "1" ] && config_office
    [ "$M_PMA" = "1" ] && config_pma
    return 0
}

config_ldap() {
    t="Schritt 3/5: Active Directory"
    ask_required "$t" "Hostname des Domaenencontrollers (LDAP_HOST):" "$(env_get LDAP_HOST)"; env_set LDAP_HOST "$REPLY"
    tls_default=yes; is_true "$(env_get LDAP_USE_TLS)" || tls_default=no
    if ui_yesno "$t" "Verschluesselte Verbindung verwenden (LDAPS/TLS, empfohlen)?" "$tls_default"; then
        env_set LDAP_USE_TLS true; port_default=636
        if ui_yesno "$t" "Zertifikat des Domaenencontrollers pruefen (LDAP_VERIFY_CERT, empfohlen)?" yes; then
            env_set LDAP_VERIFY_CERT true
        else env_set LDAP_VERIFY_CERT false; fi
    else
        env_set LDAP_USE_TLS false; env_set LDAP_VERIFY_CERT false; port_default=389
    fi
    cur="$(env_get LDAP_PORT)"
    case "$cur:$port_default" in 636:389|389:636|:*) cur="$port_default" ;; esac
    ask_port "$t" "LDAP-Port:" "$cur"; env_set LDAP_PORT "$REPLY"
    ask_required "$t" "Basis-DN der Benutzer (LDAP_BASE_DN):" "$(env_get LDAP_BASE_DN)"; env_set LDAP_BASE_DN "$REPLY"
    ask_required "$t" "Bind-DN des Dienstkontos (LDAP_BIND_DN):" "$(env_get LDAP_BIND_DN)"; env_set LDAP_BIND_DN "$REPLY"
    ask_secret "$t" "Passwort des Dienstkontos.
Es wird verschluesselt in der Datenbank gespeichert (nicht in der .env) und
kann spaeter unter Verwaltung -> Active Directory geaendert werden." "$(env_prev LDAP_PASSWORD)" 1 0
    C_LDAP_PW="$REPLY"
    env_del LDAP_PASSWORD LDAP_PASSWORD_FILE
    ask_input "$t" "Pfad(e) der AD-Gruppen fuer die Rechtevergabe (LDAP_GROUP_BASE_DN, mehrere mit ';', leer = keine):" "$(env_get LDAP_GROUP_BASE_DN)"
    env_set LDAP_GROUP_BASE_DN "$REPLY"
    ask_input "$t" "Synchronisationsintervall in Sekunden:" "$(env_or LDAP_SYNC_INTERVAL 3600)"
    env_set LDAP_SYNC_INTERVAL "$REPLY"
}

config_sso() {
    t="Schritt 3/5: Windows-Anmeldung"
    env_set SSO_ENABLED true
    ui_msg "$t" "Domaene, Domaenencontroller und Konto fuer den Domaenenbeitritt werden
verschluesselt in der Datenbank gespeichert (nicht in der .env) und koennen
spaeter unter Verwaltung -> Active Directory geaendert werden. Weitere
Domaenen (Zweigstellen, Tochtergesellschaften) werden ebenfalls dort angelegt."
    while :; do
        ask_required "$t" "NetBIOS-Name der Domaene (z. B. FIRMA):" "$(env_get SSO_DOMAIN)"
        C_SSO_DOMAIN="$(printf '%s' "$REPLY" | tr '[:lower:]' '[:upper:]')"
        printf '%s' "$C_SSO_DOMAIN" | grep -Eq '^[A-Z0-9][A-Z0-9._-]{0,14}$' && break
        [ "$NONINTERACTIVE" = "1" ] && break
        ui_msg "Ungueltiger Name" "1-15 Zeichen aus A-Z 0-9 . _ -"
    done
    dc="$(env_get SSO_DC)"; [ -z "$dc" ] && dc="$(env_get LDAP_HOST | tr ',;' '  ' | awk '{print $1}')"
    ask_required "$t" "Domaenencontroller (mehrere durch Leerzeichen getrennt, Ausfallreserve):" "$dc"; dcs="$REPLY"
    ask_input "$t" "IP-Adressen der Domaenencontroller in gleicher Reihenfolge (optional, falls kein DNS):" "$(env_get SSO_DC_IP)"; ips="$REPLY"
    C_SSO_DCS=""
    # shellcheck disable=SC2086
    set -- $(printf '%s' "$ips" | tr ',;' '  ')
    for d in $(printf '%s' "$dcs" | tr ',;' '  '); do
        line="$d"
        if [ $# -gt 0 ]; then line="$d $1"; shift; fi
        C_SSO_DCS="${C_SSO_DCS:+$C_SSO_DCS
}$line"
    done
    ask_required "$t" "Konto fuer den Domaenenbeitritt:" "$(env_get SSO_JOIN_USER)"; C_SSO_JOIN_USER="$REPLY"
    ask_secret "$t" "Passwort fuer den Domaenenbeitritt:" "$(env_prev SSO_JOIN_PASSWORD)" 1 0
    C_SSO_JOIN_PW="$REPLY"
    env_del SSO_DOMAIN SSO_REALM SSO_DC SSO_DC_IP SSO_JOIN_USER SSO_JOIN_PASSWORD
}

# Uebertraegt die AD-Zugangsdaten verschluesselt in die Datenbank (ueber stdin,
# nicht ueber die Prozessliste).
store_credentials() {
    [ -n "$C_LDAP_PW$C_SSO_DOMAIN$C_SSO_JOIN_PW" ] || return 0
    {
        [ -n "$C_LDAP_PW" ] && echo "ldap_bind_password=$(b64 "$C_LDAP_PW")"
        if [ -n "$C_SSO_DOMAIN" ]; then
            echo "sso_domain=$(b64 "$C_SSO_DOMAIN")"
            echo "sso_dcs=$(b64 "$C_SSO_DCS")"
            echo "sso_join_user=$(b64 "$C_SSO_JOIN_USER")"
        fi
        [ -n "$C_SSO_JOIN_PW" ] && echo "sso_join_password=$(b64 "$C_SSO_JOIN_PW")"
        :
    } > "$TMP_DIR/cred.in"
    log "+ $COMPOSE exec -T app php scripts/credentials.php --set-primary"
    if $COMPOSE exec -T app php scripts/credentials.php --set-primary < "$TMP_DIR/cred.in" >> "$LOG_FILE" 2>&1; then
        CRED_OK=1
        # auth ruft die Domaenen-Konfiguration beim Start ab.
        [ -n "$C_SSO_DOMAIN" ] && run_logged $COMPOSE restart auth
    fi
    rm -f "$TMP_DIR/cred.in"
}

b64() { printf '%s' "$1" | base64 | tr -d '\n'; }

config_alarm() {
    t="Schritt 3/5: SMS-Gateway"
    ask_required "$t" "Host/Adresse des SMS-Gateways (ALARM_HOST):" "$(env_get ALARM_HOST)"; env_set ALARM_HOST "$REPLY"
    ask_input "$t" "Benutzername am SMS-Gateway (ALARM_USERNAME):" "$(env_get ALARM_USERNAME)"; env_set ALARM_USERNAME "$REPLY"
    ask_secret "$t" "Passwort am SMS-Gateway (ALARM_PASSWORD):" "$(env_prev ALARM_PASSWORD)" 1 0
    env_set ALARM_PASSWORD "$REPLY"
}

config_snmp() {
    t="Schritt 3/5: SNMP-Ueberwachung"
    ask_secret "$t" "SNMP-Community-String (read-only). Der Standard 'public' ist unsicher und wird ersetzt." "$(env_prev SNMP_COMMUNITY)" 8 1
    env_set SNMP_COMMUNITY "$REPLY"
    ask_input "$t" "Standort (sysLocation):" "$(env_get SNMP_SYS_LOCATION)"; env_set SNMP_SYS_LOCATION "$REPLY"
    ask_input "$t" "Kontakt (sysContact):" "$(env_get SNMP_SYS_CONTACT)"; env_set SNMP_SYS_CONTACT "$REPLY"
    ask_port "$t" "UDP-Port des SNMP-Agenten auf dem Host:" "$(env_or SNMP_PORT 161)"; env_set SNMP_PORT "$REPLY"
}

config_office() {
    t="Schritt 3/5: Office"
    ask_input "$t" "Benutzername des Nextcloud-Administrators:" "$(env_or NEXTCLOUD_ADMIN_USER ncadmin)"
    env_set NEXTCLOUD_ADMIN_USER "$REPLY"
    ask_input "$t" "Weitere Hostnamen des Intranets (Leerzeichen-getrennt, optional):" "$(env_get NEXTCLOUD_EXTRA_TRUSTED_DOMAINS)"
    env_set NEXTCLOUD_EXTRA_TRUSTED_DOMAINS "$REPLY"
    ask_input "$t" "Verzeichnis fuer Office-Sicherungen:" "$(env_or OFFICE_BACKUP_DIR ./backups)"
    env_set OFFICE_BACKUP_DIR "$REPLY"
    if ui_yesno "$t" "Sicherungen verschluesseln (empfohlen)? Die Passphrase wird unter ./secrets/ abgelegt." yes; then
        OFFICE_ENCRYPT=1; else OFFICE_ENCRYPT=0; fi
}

config_pma() {
    ask_port "Schritt 3/5: phpMyAdmin" "Port fuer phpMyAdmin:" "$(env_or PMA_PORT 8081)"
    env_set PMA_PORT "$REPLY"
}

dbmask() { # wert (verschluesselt in der Datenbank gespeichert)
    if [ -z "$1" ]; then echo "(nicht eingegeben - Verwaltung -> Active Directory)"
    elif [ "${REPORT_SECRETS:-0}" = "1" ]; then echo "$1"
    elif [ "$CRED_OK" = "1" ]; then echo "******** (verschluesselt in der Datenbank)"
    else echo "******** (nicht gespeichert - bitte unter Verwaltung -> Active Directory eintragen)"; fi
}

mask() { # wert schluessel
    if [ -z "$1" ]; then echo "(leer)"
    elif [ "${REPORT_SECRETS:-0}" = "1" ]; then echo "$1"
    else echo "******** (siehe .env: $2)"; fi
}

step_review() {
    f="$TMP_DIR/review.txt"
    {
        echo "Bitte die Einstellungen pruefen:"
        echo
        echo "Adresse:        $(env_get APP_URL)"
        echo "Port:           $(env_get APP_PORT)"
        echo "Betriebsart:    $(env_get APP_ENV)"
        echo "Zeitzone:       $(env_get APP_TIMEZONE)"
        echo "Datenbank:      $(env_get DB_NAME) / Benutzer $(env_get DB_USER)"
        echo "Administrator:  $C_ADMIN_USER"
        echo
        echo "AD/LDAP:        $( [ "$M_LDAP" = 1 ] && echo "ja ($(env_get LDAP_HOST):$(env_get LDAP_PORT))" || echo nein)"
        echo "Windows-SSO:    $( [ "$M_SSO" = 1 ] && echo "ja ($C_SSO_DOMAIN)" || echo nein)"
        echo "SMS-Gateway:    $( [ "$M_ALARM" = 1 ] && echo "ja ($(env_get ALARM_HOST))" || echo nein)"
        echo "SNMP:           UDP $(env_get SNMP_PORT)"
        echo "Office:         $( [ "$M_OFFICE" = 1 ] && echo ja || echo nein)"
        echo "phpMyAdmin:     $( [ "$M_PMA" = 1 ] && echo "ja (Port $(env_get PMA_PORT))" || echo nein)"
        echo "Autostart:      $( [ "$M_SYSTEMD" = 1 ] && echo "ja (systemd)" || echo nein)"
        echo
        echo "Passwoerter und Secrets sind in .env eingetragen (Rechte 0600);"
        echo "AD-Zugangsdaten werden verschluesselt in der Datenbank gespeichert."
        [ "$START" = "0" ] && echo "Hinweis: --no-start - Container werden nicht gestartet."
    } > "$f"
    ui_textbox "Zusammenfassung vor der Installation" "$f"
    ui_yesno "Installation starten" "Konfiguration uebernehmen und die Installation jetzt durchfuehren?" yes || confirm_abort
}

# =============================================================================
# 4. Installation / Start
# =============================================================================
START_OK=0
HEALTH_OK=0
ADMIN_OK=0
SYSTEMD_OK=0
OFFICE_SETUP_OK=0

port_in_use() { # port tcp|udp
    if have ss; then
        if [ "$2" = "udp" ]; then ss -Hlun 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"
        else ss -Hltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]$1\$"; fi
    elif have lsof; then
        if [ "$2" = "udp" ]; then lsof -nP -iUDP:"$1" >/dev/null 2>&1
        else lsof -nP -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1; fi
    elif have netstat; then
        netstat -an 2>/dev/null | grep -i "$2" | grep -Eq "[:.]$1[[:space:]]"
    else return 1; fi
}

stack_running() { [ -n "$($COMPOSE ps -q 2>/dev/null)" ]; }

step_ports() {
    stack_running && return 0
    [ -n "$(env_get APP_HTTPS_PORT)" ] || env_set APP_HTTPS_PORT 8443
    for spec in "APP_PORT:tcp:Webseite" "APP_HTTPS_PORT:tcp:Webseite (HTTPS)" "SNMP_PORT:udp:SNMP"; do
        key="${spec%%:*}"; rest="${spec#*:}"; proto="${rest%%:*}"; label="${rest#*:}"
        while port_in_use "$(env_get "$key")" "$proto"; do
            ask_port "Port belegt" "Der Port $(env_get "$key")/$proto ($label) ist bereits belegt.

Bitte einen freien Port angeben:" "$(( $(env_get "$key") + 1 ))"
            env_set "$key" "$REPLY"
            if [ "$key" = "APP_PORT" ]; then
                url="$(env_get APP_URL | sed -E "s#:[0-9]+(/|\$)#:$REPLY\1#")"
                env_set APP_URL "$url"
            fi
            [ "$NONINTERACTIVE" = "1" ] && break
        done
    done
}

run_with_gauge() { # titel text max_sekunden befehl...
    title="$1"; text="$2"; maxs="$3"; shift 3
    rcfile="$TMP_DIR/rc"; rm -f "$rcfile"
    log "+ $*"
    ( "$@" >> "$LOG_FILE" 2>&1; echo $? > "$rcfile" ) &
    pid=$!
    start_ts="$(date +%s)"
    {
        while kill -0 "$pid" 2>/dev/null; do
            el=$(( $(date +%s) - start_ts ))
            p=$(( el * 95 / maxs )); [ "$p" -gt 95 ] && p=95
            last="$(tail -n 1 "$LOG_FILE" 2>/dev/null | tr -cd '[:print:]' | cut -c1-$((W - 8)))"
            printf 'XXX\n%d\n%s\n\n[%ds] %s\nXXX\n' "$p" "$text" "$el" "$last"
            sleep 2
        done
        printf 'XXX\n100\n%s\nXXX\n' "Abgeschlossen."
    } | ui_gauge "$title" "$text"
    wait "$pid" 2>/dev/null
    rc="$(cat "$rcfile" 2>/dev/null || echo 1)"
    return "$rc"
}

container_health() { # dienst
    cid="$($COMPOSE ps -q "$1" 2>/dev/null | head -n1)"
    [ -z "$cid" ] && { echo "fehlt"; return; }
    $DOCKER inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null || echo unbekannt
}

wait_healthy() { # titel max_sekunden dienste...
    title="$1"; maxs="$2"; shift 2
    services="$*"
    okfile="$TMP_DIR/healthy"; rm -f "$okfile"
    start_ts="$(date +%s)"
    {
        while :; do
            el=$(( $(date +%s) - start_ts ))
            state=""; all=1
            for s in $services; do
                h="$(container_health "$s")"
                state="$state $s=$h"
                [ "$h" = "healthy" ] || all=0
            done
            if [ "$all" = "1" ]; then touch "$okfile"; break; fi
            [ "$el" -ge "$maxs" ] && break
            p=$(( el * 99 / maxs ))
            printf 'XXX\n%d\nWarte auf Betriebsbereitschaft ...\n\n[%ds]%s\nXXX\n' "$p" "$el" "$state"
            sleep 5
        done
        printf 'XXX\n100\nFertig.\nXXX\n'
    } | ui_gauge "$title" "Warte ..."
    [ -f "$okfile" ]
}

step_install() {
    t="Schritt 4/5: Installation"
    chmod 600 .env
    mkdir -p storage/logs storage/uploads

    if [ "$M_OFFICE" = "1" ] && [ "$M_LDAP" = "1" ] && [ -n "$C_LDAP_PW" ]; then
        # Bind-Passwort fuer die Nextcloud-LDAP-Anbindung (Docker-Secret).
        (umask 077 && mkdir -p ./secrets && printf '%s' "$C_LDAP_PW" > ./secrets/nextcloud_ldap_password)
        chmod 700 ./secrets; chmod 644 ./secrets/nextcloud_ldap_password
    fi

    if [ "$M_OFFICE" = "1" ]; then
        set -- --no-start
        [ "$OFFICE_ENCRYPT" = "0" ] && set -- "$@" --no-encryption
        [ "$M_LDAP" = "1" ] && set -- "$@" --with-ad
        [ "$M_SSO" = "1" ] && set -- "$@" --with-sso
        ui_info "$t" "Richte Office-Konfiguration und Secrets ein ..."
        if run_logged sh scripts/office-setup.sh "$@"; then OFFICE_SETUP_OK=1
        else ui_msg "$t" "Die Office-Einrichtung ist fehlgeschlagen. Details: $LOG_FILE"; fi
    elif is_true "$(env_get OFFICE_ENABLED)"; then
        env_set OFFICE_ENABLED false
        profiles="$(env_get COMPOSE_PROFILES | tr ',' '\n' | grep -v '^office$' | paste -sd, -)"
        env_set COMPOSE_PROFILES "$profiles"
    fi

    if [ "$START" = "0" ]; then
        ui_msg "$t" "Konfiguration abgeschlossen. Container wurden nicht gestartet (--no-start).

Start spaeter mit:  docker compose up -d --build"
        return 0
    fi

    case "$COMPOSE" in sudo*) ensure_root_access || return 1 ;; esac
    step_ports

    ui_info "$t" "Pruefe docker-compose.yml ..."
    if ! run_logged $COMPOSE config -q; then
        ui_msg "$t - Fehler" "Die Compose-Konfiguration ist ungueltig. Details:
$LOG_FILE"
        return 1
    fi

    maxs=600; [ "$M_OFFICE" = "1" ] && maxs=1200
    if run_with_gauge "$t" "Lade Images, baue und starte die Container (erster Lauf: einige Minuten) ..." "$maxs" \
        $COMPOSE $COMPOSE_UP_OPTS up -d --build --remove-orphans; then
        START_OK=1
    else
        tail -n 30 "$LOG_FILE" > "$TMP_DIR/err.txt"
        ui_textbox "$t - Fehler beim Start" "$TMP_DIR/err.txt"
        return 1
    fi

    if wait_healthy "$t" 600 db app auth; then HEALTH_OK=1; fi
    if [ "$HEALTH_OK" = "1" ] && have curl; then
        curl -fsS -o /dev/null --max-time 10 "http://127.0.0.1:$(env_get APP_PORT)/health" >> "$LOG_FILE" 2>&1 || HEALTH_OK=0
    fi
    if [ "$HEALTH_OK" = "0" ]; then
        $COMPOSE ps > "$TMP_DIR/ps.txt" 2>&1
        printf '\nDie Anwendung ist (noch) nicht erreichbar. Protokolle:\n  %s logs app auth db\n' "$COMPOSE" >> "$TMP_DIR/ps.txt"
        ui_textbox "$t - Warnung" "$TMP_DIR/ps.txt"
    fi

    if [ "$HEALTH_OK" = "1" ]; then
        ui_info "$t" "Setze das Administrationskonto '$C_ADMIN_USER' ..."
        # Passwort ueber stdin uebergeben, damit es nicht in der Prozessliste erscheint.
        log "+ $COMPOSE exec -T app php scripts/create_admin.php $C_ADMIN_USER"
        if printf '%s' "$C_ADMIN_PW" | $COMPOSE exec -T app sh -c \
            'ADMIN_PASSWORD="$(cat)" php scripts/create_admin.php "$0"' "$C_ADMIN_USER" > "$TMP_DIR/admin.out" 2>&1; then
            ADMIN_OK=1
        fi
        grep -v -i 'passwort:' "$TMP_DIR/admin.out" >> "$LOG_FILE"

        ui_info "$t" "Speichere die AD-Zugangsdaten verschluesselt ..."
        store_credentials
    fi

    if [ "$M_OFFICE" = "1" ] && [ "$OFFICE_SETUP_OK" = "1" ]; then
        wait_healthy "$t - Office" 900 nextcloud eurooffice || \
            ui_msg "$t - Office" "Nextcloud/Euro-Office sind noch nicht bereit. Der erste Start kann laenger dauern.
Status: $COMPOSE ps"
    fi

    if [ "$M_PMA" = "1" ]; then
        ui_info "$t" "Starte phpMyAdmin ..."
        run_logged $COMPOSE --profile tools up -d phpmyadmin
    fi

    if [ "$M_SYSTEMD" = "1" ]; then
        ui_info "$t" "Richte den systemd-Dienst ein ..."
        ensure_root_access && run_logged $SUDO env INSTALL_ONLY=1 sh scripts/install-systemd-service.sh && SYSTEMD_OK=1
    fi

    if [ "$ADMIN_OK" = "1" ] && [ "$NONINTERACTIVE" = "0" ] && [ -n "$(env_get ADMIN_PASSWORD)" ]; then
        if ui_yesno "$t - Sicherheit" "Das Administrationskonto ist eingerichtet.

ADMIN_PASSWORD jetzt aus der .env entfernen (empfohlen)? Das Passwort wird im Anschluss im Abschlussbericht angezeigt." yes; then
            env_set ADMIN_PASSWORD ""
            ADMIN_PW_REMOVED=1
        fi
    fi
    return 0
}
ADMIN_PW_REMOVED=0

# =============================================================================
# 5. Abschlussbericht
# =============================================================================
result_text() {
    if [ "$START" = "0" ]; then echo "nur konfiguriert (--no-start)"
    elif [ "$START_OK" = "0" ]; then echo "Start fehlgeschlagen"
    elif [ "$HEALTH_OK" = "1" ]; then echo "betriebsbereit"
    else echo "gestartet, Healthcheck fehlgeschlagen"; fi
}

yn() { if [ "$1" = "1" ]; then echo "aktiv"; else echo "nicht aktiv"; fi; }

write_report() { # ziel include_secrets(1|0)
    target="$1"; REPORT_SECRETS="$2"
    app_url="$(env_get APP_URL)"; app_port="$(env_get APP_PORT)"
    git_rev="$(git rev-parse --short HEAD 2>/dev/null || echo '-')"
    git_branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '-')"
    secrets_dir="$(env_get OFFICE_SECRETS_DIR)"; [ -z "$secrets_dir" ] && secrets_dir="./secrets"
    {
        echo "# Installationsprotokoll - Intranet-Landingpage"
        echo
        echo "| Eigenschaft | Wert |"
        echo "| --- | --- |"
        echo "| Datum | $(date '+%d.%m.%Y %H:%M:%S %Z') |"
        echo "| Installiert von | $(id -un) |"
        echo "| Host | $(host_name) ($(host_ip)) |"
        echo "| Betriebssystem | $OS_NAME |"
        echo "| Installationsverzeichnis | \`$ROOT_DIR\` |"
        echo "| Git-Stand | $git_branch @ $git_rev |"
        echo "| Compose-Projekt | $(project_name) |"
        echo "| Installationsskript | scripts/install.sh v$SCRIPT_VERSION |"
        echo "| Ergebnis | $(result_text) |"
        echo
        echo "## 1. Systemumgebung"
        echo
        echo "| Komponente | Version / Status |"
        echo "| --- | --- |"
        echo "| Docker | $($DOCKER --version 2>/dev/null | sed 's/Docker version //') |"
        echo "| Docker Compose | $($COMPOSE version --short 2>/dev/null || echo '-') ($COMPOSE_KIND) |"
        echo "| Docker-Aufruf | \`$DOCKER\` |"
        echo "| Nachinstallierte Pakete | ${INSTALLED_PKGS:- keine} |"
        echo "| Oberflaeche | $UI |"
        echo
        echo "## 2. Zugriff"
        echo
        echo "| Dienst | Adresse |"
        echo "| --- | --- |"
        echo "| Landingpage | $app_url |"
        echo "| Administration | $app_url/admin/login |"
        echo "| Healthcheck | http://127.0.0.1:$app_port/health |"
        [ "$M_OFFICE" = "1" ] && echo "| Office (Nextcloud) | $app_url/office/ |"
        [ "$M_PMA" = "1" ] && echo "| phpMyAdmin | http://$(host_name):$(env_get PMA_PORT) |"
        echo "| SNMP-Agent | udp://$(host_name):$(env_get SNMP_PORT) |"
        echo
        echo "## 3. Zugangsdaten und Secrets"
        echo
        [ "$REPORT_SECRETS" = "1" ] && echo "> **Vertraulich:** Dieses Dokument enthaelt Passwoerter im Klartext. Nur geschuetzt ablegen (z. B. Passwort-Tresor)." && echo
        echo "| Zweck | Benutzer | Passwort / Secret |"
        echo "| --- | --- | --- |"
        if [ "$ADMIN_PW_REMOVED" = "1" ]; then
            if [ "$REPORT_SECRETS" = "1" ]; then pw="$C_ADMIN_PW"; else pw="******** (aus .env entfernt - im Passwort-Tresor ablegen)"; fi
        else pw="$(mask "$C_ADMIN_PW" ADMIN_PASSWORD)"; fi
        echo "| Intranet-Administrator | $C_ADMIN_USER | $pw |"
        echo "| Datenbank (Anwendung) | $(env_get DB_USER) | $(mask "$(env_get DB_PASSWORD)" DB_PASSWORD) |"
        echo "| Datenbank (root) | root | $(mask "$(env_get DB_ROOT_PASSWORD)" DB_ROOT_PASSWORD) |"
        [ "$M_LDAP" = "1" ] && echo "| AD-Dienstkonto | $(env_get LDAP_BIND_DN) | $(dbmask "$C_LDAP_PW") |"
        [ "$M_SSO" = "1" ] && echo "| Domaenenbeitritt (NTLM) | $C_SSO_JOIN_USER | $(dbmask "$C_SSO_JOIN_PW") |"
        [ "$M_ALARM" = "1" ] && echo "| SMS-Gateway | $(env_get ALARM_USERNAME) | $(mask "$(env_get ALARM_PASSWORD)" ALARM_PASSWORD) |"
        echo "| SNMP-Community | - | $(mask "$(env_get SNMP_COMMUNITY)" SNMP_COMMUNITY) |"
        if [ "$M_OFFICE" = "1" ]; then
            ncpw="$(cat "$secrets_dir/nextcloud_admin_password" 2>/dev/null)"
            if [ "$REPORT_SECRETS" != "1" ] && [ -n "$ncpw" ]; then ncpw="******** (siehe $secrets_dir/nextcloud_admin_password)"; fi
            echo "| Nextcloud-Administrator | $(env_get NEXTCLOUD_ADMIN_USER) | ${ncpw:-(leer)} |"
            [ "$OFFICE_ENCRYPT" = "1" ] && echo "| Office-Sicherung (Passphrase) | - | siehe $secrets_dir/office_backup_passphrase (zusaetzlich sicher verwahren!) |"
        fi
        echo
        echo "## 4. Konfiguration"
        echo
        echo "| Einstellung | Wert |"
        echo "| --- | --- |"
        for k in APP_NAME APP_ENV APP_DEBUG APP_URL APP_PORT APP_HTTPS_PORT TLS_ENABLED APP_TIMEZONE APP_FORCE_SECURE_COOKIES SEED_ON_START \
                 DB_HOST DB_PORT DB_NAME DB_USER ADMIN_USERNAME SNMP_PORT SNMP_SYS_LOCATION SNMP_SYS_CONTACT; do
            echo "| \`$k\` | $(env_get "$k") |"
        done
        if [ "$M_LDAP" = "1" ]; then
            for k in LDAP_HOST LDAP_PORT LDAP_USE_TLS LDAP_VERIFY_CERT LDAP_BASE_DN LDAP_BIND_DN LDAP_GROUP_BASE_DN LDAP_SYNC_INTERVAL; do
                echo "| \`$k\` | $(env_get "$k") |"
            done
        fi
        if [ "$M_SSO" = "1" ]; then
            echo "| \`SSO_ENABLED\` | $(env_get SSO_ENABLED) |"
            echo "| Domaene (Verwaltung) | $C_SSO_DOMAIN |"
            echo "| Domaenencontroller (Verwaltung) | $(printf '%s' "$C_SSO_DCS" | tr '\n' ';') |"
        fi
        [ "$M_ALARM" = "1" ] && echo "| \`ALARM_HOST\` | $(env_get ALARM_HOST) |"
        if [ "$M_OFFICE" = "1" ]; then
            for k in OFFICE_ENABLED COMPOSE_PROFILES NEXTCLOUD_IMAGE_TAG EUROOFFICE_IMAGE_TAG NEXTCLOUD_LDAP_ENABLED OFFICE_BACKUP_DIR OFFICE_BACKUP_RETENTION; do
                echo "| \`$k\` | $(env_get "$k") |"
            done
        fi
        echo
        echo "## 5. Module"
        echo
        echo "| Modul | Status |"
        echo "| --- | --- |"
        echo "| Active Directory / LDAP | $(yn $M_LDAP) |"
        echo "| Windows-Anmeldung (NTLM) | $(yn $M_SSO) |"
        echo "| SMS-Gateway / Alarmierung | $(yn $M_ALARM) |"
        echo "| Office (Nextcloud + Euro-Office) | $(yn $M_OFFICE) |"
        echo "| phpMyAdmin | $(yn $M_PMA) |"
        if [ "$M_SYSTEMD" = "1" ]; then
            echo "| Autostart (systemd \`intranet.service\`) | $( [ "$SYSTEMD_OK" = "1" ] && echo aktiv || echo fehlgeschlagen) |"
        else echo "| Autostart (systemd) | nicht aktiv |"; fi
        echo
        echo "## 6. Container-Status"
        echo
        echo '```'
        if [ "$START" = "1" ]; then $COMPOSE ps 2>/dev/null; else echo "nicht gestartet"; fi
        echo '```'
        echo
        echo "## 7. Dateien"
        echo
        echo "| Datei | Inhalt |"
        echo "| --- | --- |"
        echo "| \`$ROOT_DIR/.env\` | Konfiguration inkl. Passwoerter (Rechte 0600, nicht versioniert) |"
        [ -n "$ENV_BACKUP" ] && echo "| \`$ROOT_DIR/$ENV_BACKUP\` | Sicherung der vorherigen .env |"
        [ "$M_OFFICE" = "1" ] && echo "| \`$ROOT_DIR/${secrets_dir#./}/\` | Office-Secrets (0700, nicht versioniert) |"
        echo "| \`$LOG_FILE\` | Ausfuehrliches Installationsprotokoll |"
        echo "| \`$REPORT_FILE\` | Dieses Protokoll |"
        echo
        echo "## 8. Betrieb - nuetzliche Befehle"
        echo
        echo '```bash'
        echo "cd $ROOT_DIR"
        echo "$COMPOSE ps                     # Status der Container"
        echo "$COMPOSE logs -f app            # Protokolle der Anwendung"
        echo "$COMPOSE restart                # Neustart"
        echo "$COMPOSE up -d --build          # Aktualisieren/Neu bauen"
        echo "$COMPOSE exec app php scripts/create_admin.php <name>   # Admin anlegen/Passwort zuruecksetzen"
        echo "$COMPOSE exec sync php scripts/sync_ad.php              # AD-Synchronisation sofort ausfuehren"
        [ "$M_SYSTEMD" = "1" ] && echo "systemctl status intranet      # Autostart-Dienst"
        [ "$M_OFFICE" = "1" ] && echo "./scripts/office-backup.sh     # Office-Sicherung"
        echo "./scripts/install.sh           # Installation erneut ausfuehren / Konfiguration aendern"
        echo '```'
        echo
        echo "## 9. Naechste Schritte"
        echo
        echo "1. Unter $app_url/admin/login mit \`$C_ADMIN_USER\` anmelden und das Passwort im Passwort-Tresor ablegen."
        is_true "$(env_get SEED_ON_START)" && echo "1. Nach der Ersteinrichtung \`SEED_ON_START=false\` in der .env setzen."
        case "$app_url" in http://*) echo "1. Fuer den Produktivbetrieb HTTPS vorschalten und \`APP_FORCE_SECURE_COOKIES=true\` setzen." ;; esac
        [ "$M_LDAP" = "1" ] && echo "1. Im Adminbereich unter **AD-Konfiguration** die Verbindung testen und die erste Synchronisation pruefen."
        [ "$M_LDAP" = "0" ] && echo "1. AD-Anbindung bei Bedarf im Adminbereich oder durch erneutes Ausfuehren des Skripts einrichten."
        [ "$M_OFFICE" = "1" ] && echo "1. Im Adminbereich unter **Office** den Status pruefen und die Office-Kachel anlegen."
        echo "1. \`.env\`, \`secrets/\` und die Datenbank (Volume \`$(project_name)_db_data\`) in die Datensicherung aufnehmen."
    } > "$target"
    chmod 600 "$target" 2>/dev/null || true
}

step_summary() {
    mkdir -p "$REPORT_DIR"; chmod 700 "$REPORT_DIR" 2>/dev/null || true
    if [ -z "$INCLUDE_SECRETS" ]; then
        if [ "$NONINTERACTIVE" = "0" ] && ui_yesno "Schritt 5/5: Abschlussbericht" "Der Abschlussbericht wird als Markdown-Dokument fuer die Dokumentation gespeichert:
$REPORT_FILE

Zugangsdaten im Klartext in das Dokument aufnehmen?
(Nein = Passwoerter werden maskiert, empfohlen fuer die allgemeine Doku)" no; then
            INCLUDE_SECRETS=1
        else INCLUDE_SECRETS=0; fi
    fi
    write_report "$REPORT_FILE" "$INCLUDE_SECRETS"
    # Bildschirmfassung immer mit Zugangsdaten, damit sie notiert werden koennen.
    write_report "$TMP_DIR/screen.md" 1
    if [ "$NONINTERACTIVE" = "1" ]; then
        ui_textbox "Schritt 5/5: Abschlussbericht" "$REPORT_FILE"
    else
        ui_textbox "Schritt 5/5: Abschlussbericht (mit Zugangsdaten - bitte notieren)" "$TMP_DIR/screen.md"
    fi
    [ "$UI" != "text" ] && [ "$NONINTERACTIVE" = "0" ] && clear
    printf '\n\033[1;32mInstallation abgeschlossen.\033[0m\n'
    printf '  Landingpage:      %s\n' "$(env_get APP_URL)"
    printf '  Administration:   %s/admin/login (Benutzer: %s)\n' "$(env_get APP_URL)" "$C_ADMIN_USER"
    printf '  Abschlussbericht: %s%s\n' "$REPORT_FILE" "$( [ "$INCLUDE_SECRETS" = "1" ] && echo ' (enthaelt Passwoerter!)')"
    printf '  Protokoll:        %s\n\n' "$LOG_FILE"
    if [ "$NONINTERACTIVE" = "1" ] && [ "$ADMIN_PW_REMOVED" = "0" ]; then
        printf '  Zugangsdaten stehen in der .env (ADMIN_PASSWORD, DB_PASSWORD, ...).\n\n'
    fi
}

# =============================================================================
# Hauptablauf
# =============================================================================
main() {
    detect_system
    bootstrap_ui
    ui_init
    log "Start scripts/install.sh v$SCRIPT_VERSION in $ROOT_DIR (Optionen: $*)"

    ui_msg "Willkommen" "Dieser Assistent installiert die Intranet-Landingpage vollstaendig:

  1. Voraussetzungen pruefen und fehlende Pakete installieren
  2. .env aus .env.example anlegen
  3. Einstellungen, Passwoerter und Secrets abfragen
  4. Container bauen, starten und pruefen
  5. Abschlussbericht fuer die Dokumentation erstellen

Bedienung: Pfeiltasten/Tab, Leertaste waehlt aus, Enter bestaetigt, Esc bricht ab.
Protokoll: storage/logs/install-$TIMESTAMP.log"

    step_requirements
    step_env_file
    step_config
    step_review
    step_install
    step_summary
    if [ "$START" = "1" ] && [ "$HEALTH_OK" = "0" ]; then exit 1; fi
    exit 0
}

main "$@"
