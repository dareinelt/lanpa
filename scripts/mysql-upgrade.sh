#!/bin/sh
# Hebt einen bestehenden MySQL-8.0-Datenbestand (Volume <projekt>_db_data)
# auf MySQL 9.7 LTS an.
#
# MySQL erlaubt Upgrades nur von LTS zu LTS: 8.0 -> 8.4 LTS -> 9.7 LTS. Ein
# direkter Start von mysql:9.7 auf 8.0-Daten bricht ab ("Cannot upgrade from
# 80xxx to 907xx"). Das Skript fuehrt den Zwischenschritt 8.4 mit einem
# temporaeren Container aus; den Schritt auf 9.7 erledigt anschliessend der
# db-Dienst selbst beim naechsten Start.
#
#   ./scripts/mysql-upgrade.sh              Pruefen, sichern, anheben, Stack starten
#   ./scripts/mysql-upgrade.sh --check      nur pruefen (Exit 0 = kein Upgrade noetig,
#                                           10 = Zwischenschritt 8.4 erforderlich)
#   Optionen: --yes (ohne Rueckfrage), --no-backup, --no-start,
#             --project <name> (Compose-Projektname)
#
# Vor dem Upgrade wird der Stack gestoppt und das Datenverzeichnis als
# tar.gz unter backups/mysql/ gesichert (Rueckweg: Volume leeren, Archiv
# zurueckspielen, DB_IMAGE_TAG=8.0 setzen).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

DOCKER="${DOCKER:-docker}"
COMPOSE="${COMPOSE:-$DOCKER compose}"
STEP_IMAGE="mysql:8.4"
HELPER_IMAGE="mysql:${DB_IMAGE_TAG:-9.7.2}"
BACKUP_DIR="${ROOT_DIR}/backups/mysql"

CHECK=0
YES=0
BACKUP=1
START=1
PROJECT=""
while [ $# -gt 0 ]; do
    case "$1" in
        --check) CHECK=1; shift ;;
        --yes|-y) YES=1; shift ;;
        --no-backup) BACKUP=0; shift ;;
        --no-start) START=0; shift ;;
        --project) PROJECT="${2:?Projektname fehlt}"; shift 2 ;;
        -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
    esac
done

info() { printf '[mysql-upgrade] %s\n' "$*"; }
die() { printf '[mysql-upgrade] FEHLER: %s\n' "$*" >&2; exit 1; }

env_get() {
    [ -f .env ] || return 0
    sed -n "s/^$1=//p" .env | tail -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

if [ -z "$PROJECT" ]; then
    PROJECT="$(env_get COMPOSE_PROJECT_NAME)"
    [ -z "$PROJECT" ] && PROJECT="${COMPOSE_PROJECT_NAME:-$(basename "$ROOT_DIR")}"
fi
PROJECT="$(printf '%s' "$PROJECT" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
VOLUME="${PROJECT}_db_data"
CONTAINER="${PROJECT}-mysql-upgrade-84"

# Liefert die Version des Datenbestands: leer (kein/leeres Volume),
# "8.0" (kein Upgrade-Verlauf, vor 8.4) oder die zuletzt eingetragene Version.
data_version() {
    $DOCKER volume inspect "$VOLUME" >/dev/null 2>&1 || return 0
    $DOCKER run --rm --network none -v "${VOLUME}:/data:ro" --entrypoint sh "$HELPER_IMAGE" -c '
        if [ -f /data/mysql_upgrade_history ]; then
            tr "{}," "\n\n\n" < /data/mysql_upgrade_history | sed -n "s/^\"version\":\"\(.*\)\"$/\1/p" | tail -n1
        elif [ -f /data/mysql.ibd ]; then
            echo 8.0
        elif [ -d /data/mysql ]; then
            echo 5.7
        fi'
}

# 0 = 8.4 oder neuer, 1 = aelter.
at_least_84() {
    major="${1%%.*}"
    rest="${1#*.}"
    minor="${rest%%.*}"
    [ "$major" -gt 8 ] 2>/dev/null && return 0
    [ "$major" -eq 8 ] 2>/dev/null && [ "$minor" -ge 4 ] 2>/dev/null && return 0
    return 1
}

VERSION="$(data_version)"
if [ -z "$VERSION" ]; then
    info "Kein bestehender Datenbestand (${VOLUME}) - nichts zu tun."
    exit 0
fi
case "$VERSION" in
    5.*) die "Datenbestand von MySQL ${VERSION} gefunden. Bitte zuerst auf 8.0 anheben (siehe MySQL-Dokumentation)." ;;
esac
case "$VERSION" in
    9.*) info "Datenbestand ${VOLUME} laeuft bereits mit MySQL ${VERSION} - nichts zu tun."; exit 0 ;;
esac
if at_least_84 "$VERSION"; then
    info "Datenbestand ${VOLUME} hat Version ${VERSION} - der db-Dienst hebt ihn beim Start selbst auf 9.7 an."
    exit 0
fi

info "Datenbestand ${VOLUME} hat Version ${VERSION}: Zwischenschritt ueber MySQL 8.4 LTS erforderlich."
[ "$CHECK" = "1" ] && exit 10

if [ "$YES" != "1" ]; then
    printf 'Stack "%s" stoppen und Datenbank auf 8.4 anheben? [j/N] ' "$PROJECT"
    read -r answer
    case "$answer" in j|J|ja|Ja|y|Y|yes) ;; *) info "Abgebrochen."; exit 1 ;; esac
fi

info "Stoppe den Stack ..."
$COMPOSE -p "$PROJECT" stop
$DOCKER rm -f "$CONTAINER" >/dev/null 2>&1 || true

if [ "$BACKUP" = "1" ]; then
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR" 2>/dev/null || true
    archive="mysql-${VERSION}-datadir-$(date +%Y%m%d-%H%M%S).tar.gz"
    info "Sichere das Datenverzeichnis nach backups/mysql/${archive} ..."
    $DOCKER run --rm --network none -v "${VOLUME}:/data:ro" -v "${BACKUP_DIR}:/backup" \
        --entrypoint tar "$HELPER_IMAGE" czf "/backup/${archive}" -C /data . \
        || die "Sicherung fehlgeschlagen - Upgrade nicht ausgefuehrt."
    chmod 600 "${BACKUP_DIR}/${archive}" 2>/dev/null || true
fi

info "Starte MySQL 8.4 LTS auf dem Datenbestand (Upgrade des Data Dictionary) ..."
set -- --network none -v "${VOLUME}:/var/lib/mysql"
[ -f docker/mysql/my.cnf ] && set -- "$@" -v "${ROOT_DIR}/docker/mysql/my.cnf:/etc/mysql/conf.d/intranet.cnf:ro"
# Langsames Herunterfahren und mysql_native_password nur fuer die Pruefung der Konten.
$DOCKER run -d --name "$CONTAINER" "$@" "$STEP_IMAGE" \
    --innodb-fast-shutdown=0 --mysql-native-password=ON >/dev/null

i=0
until $DOCKER logs "$CONTAINER" 2>&1 | grep -q "ready for connections. Version: '8\.4"; do
    if [ "$($DOCKER inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" != "true" ]; then
        $DOCKER logs "$CONTAINER" 2>&1 | tail -n 20 >&2
        $DOCKER rm -f "$CONTAINER" >/dev/null 2>&1 || true
        die "MySQL 8.4 konnte den Datenbestand nicht anheben (siehe Ausgabe oben)."
    fi
    i=$((i + 1))
    [ "$i" -ge 900 ] && { $DOCKER rm -f "$CONTAINER" >/dev/null 2>&1 || true; die "Zeitueberschreitung beim Upgrade."; }
    sleep 2
done

# MySQL 9.7 kennt mysql_native_password nicht mehr. Betroffene Konten der
# Anwendung (DB_USER, root) werden mit den Passwoertern aus .env auf
# caching_sha2_password umgestellt, weitere nur gemeldet.
root_pw="$(env_get DB_ROOT_PASSWORD)"
db_user="$(env_get DB_USER)"; db_user="${db_user:-intranet}"
db_pw="$(env_get DB_PASSWORD)"
sql_quote() { printf '%s' "$1" | sed "s/'/''/g"; }
root_sql() { # sql (stdin), Passwort ueber stdin statt Kommandozeile
    { printf '%s\n' "$root_pw"; cat; } | $DOCKER exec -i "$CONTAINER" sh -c \
        'read -r MYSQL_PWD; export MYSQL_PWD; exec mysql -uroot -N -B' 2>/dev/null
}
if [ -n "$root_pw" ] && native="$(echo "SELECT CONCAT(user,'@',host) FROM mysql.user WHERE plugin='mysql_native_password';" | root_sql)"; then
    for account in $native; do
        user="${account%@*}"; host="${account#*@}"
        pw=""
        [ "$user" = "root" ] && pw="$root_pw"
        [ "$user" = "$db_user" ] && [ -n "$db_pw" ] && pw="$db_pw"
        if [ -n "$pw" ]; then
            echo "ALTER USER '$(sql_quote "$user")'@'$(sql_quote "$host")' IDENTIFIED WITH caching_sha2_password BY '$(sql_quote "$pw")';" | root_sql \
                && info "Konto ${account} auf caching_sha2_password umgestellt." \
                || info "WARNUNG: Konto ${account} konnte nicht umgestellt werden."
        else
            info "WARNUNG: Konto ${account} nutzt mysql_native_password (in MySQL 9.7 entfernt) und muss manuell umgestellt werden."
        fi
    done
else
    info "WARNUNG: Anmeldung als root mit DB_ROOT_PASSWORD nicht moeglich - Konten mit mysql_native_password wurden nicht geprueft."
fi

info "Fahre MySQL 8.4 sauber herunter ..."
$DOCKER stop -t 600 "$CONTAINER" >/dev/null
$DOCKER logs "$CONTAINER" 2>&1 | grep -q "Shutdown complete (mysqld 8\.4" \
    || { $DOCKER logs "$CONTAINER" 2>&1 | tail -n 20 >&2; $DOCKER rm -f "$CONTAINER" >/dev/null 2>&1 || true; die "MySQL 8.4 wurde nicht sauber beendet."; }
$DOCKER rm "$CONTAINER" >/dev/null

VERSION="$(data_version)"
at_least_84 "$VERSION" || die "Unerwartete Version nach dem Upgrade: ${VERSION}"
info "Datenbestand hat jetzt Version ${VERSION}."

if [ "$START" = "1" ]; then
    info "Starte den Stack (der db-Dienst hebt den Datenbestand auf 9.7 an) ..."
    $COMPOSE -p "$PROJECT" up -d
else
    info "Fertig. Start mit: docker compose up -d"
fi
