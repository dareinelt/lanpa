#!/bin/sh
# Richtet die Office-Erweiterung (Nextcloud + Euro-Office) ein - Einzeiler:
#
#   ./scripts/office-setup.sh
#
# Das Skript ist idempotent und kann jederzeit erneut ausgefuehrt werden:
#   1. legt .env aus .env.example an, falls sie fehlt (mit Zufallspasswoertern),
#   2. erzeugt fehlende Secrets unter ./secrets/ (niemals versioniert),
#   3. aktiviert Office in der .env (OFFICE_ENABLED, COMPOSE_PROFILES, ...),
#   4. startet bzw. aktualisiert alle Container und wartet auf Nextcloud.
#
# Optionen:
#   --no-start        nur konfigurieren, keine Container starten
#   --no-encryption   Sicherungen nicht verschluesseln (kein Backup-Passwort)
#   --with-ad         AD-Anbindung von Nextcloud aktivieren (LDAP_* aus .env)
#   --with-sso        zusaetzlich automatische Windows-Anmeldung (NTLM)
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

START=1
ENCRYPT=1
WITH_AD=""
WITH_SSO=""
for arg in "$@"; do
    case "$arg" in
        --no-start) START=0 ;;
        --no-encryption) ENCRYPT=0 ;;
        --with-ad) WITH_AD=1 ;;
        --with-sso) WITH_AD=1; WITH_SSO=1 ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) echo "Unbekannte Option: $arg" >&2; exit 2 ;;
    esac
done

info() { printf '\033[1m[office-setup]\033[0m %s\n' "$*"; }

random_secret() {
    # 48 Zeichen [A-Za-z0-9] - frei von Sonderzeichen fuer .env/Shell/URLs.
    LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 48
}

env_get() {
    [ -f .env ] || return 0
    sed -n "s/^$1=//p" .env | tail -n1 | sed -e 's/^"//' -e 's/"$//'
}

env_set() {
    key="$1"; value="$2"
    if grep -q "^${key}=" .env 2>/dev/null; then
        tmp="$(mktemp)"
        awk -v k="$key" -v v="$value" 'BEGIN{FS=OFS="="} $1==k{print k"="v; next} {print}' .env > "$tmp"
        cat "$tmp" > .env
        rm -f "$tmp"
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

# --- 1. .env -------------------------------------------------------------------
if [ ! -f .env ]; then
    info ".env fehlt - wird aus .env.example angelegt."
    cp .env.example .env
    chmod 600 .env
    env_set DB_PASSWORD "$(random_secret)"
    env_set DB_ROOT_PASSWORD "$(random_secret)"
fi
for key in DB_PASSWORD DB_ROOT_PASSWORD; do
    case "$(env_get "$key")" in
        ''|bitte-aendern*) env_set "$key" "$(random_secret)"; info "$key wurde zufaellig gesetzt." ;;
    esac
done

# --- 2. Secrets ------------------------------------------------------------------
SECRETS_DIR="./secrets"
mkdir -p "$SECRETS_DIR"
chmod 700 "$SECRETS_DIR"

ensure_secret() {
    file="$SECRETS_DIR/$1"
    if [ ! -s "$file" ]; then
        printf '%s' "$2" > "$file"
        info "Secret $1 erzeugt."
    fi
    # Lesbar fuer die Dienstkonten in den Containern; das Verzeichnis selbst
    # bleibt dem Eigentuemer vorbehalten (0700).
    chmod 644 "$file"
}

ensure_secret office_jwt_secret "$(random_secret)"
ensure_secret nextcloud_db_password "$(random_secret)"
ensure_secret nextcloud_admin_password "$(random_secret)"
ensure_secret nextcloud_redis_password "$(random_secret)"

# AD-Bind-Passwort: aus LDAP_PASSWORD bzw. LDAP_PASSWORD_FILE uebernehmen.
ldap_pw="$(env_get LDAP_PASSWORD)"
ldap_pw_file="$(env_get LDAP_PASSWORD_FILE)"
if [ -z "$ldap_pw" ] && [ -n "$ldap_pw_file" ] && [ -r "$ldap_pw_file" ]; then
    ldap_pw="$(cat "$ldap_pw_file")"
fi
if [ -n "$ldap_pw" ]; then
    printf '%s' "$ldap_pw" > "$SECRETS_DIR/nextcloud_ldap_password"
elif [ ! -f "$SECRETS_DIR/nextcloud_ldap_password" ]; then
    : > "$SECRETS_DIR/nextcloud_ldap_password"
fi
chmod 644 "$SECRETS_DIR/nextcloud_ldap_password"

if [ "$ENCRYPT" = "1" ]; then
    ensure_secret office_backup_passphrase "$(random_secret)"
elif [ ! -f "$SECRETS_DIR/office_backup_passphrase" ]; then
    : > "$SECRETS_DIR/office_backup_passphrase"
    chmod 644 "$SECRETS_DIR/office_backup_passphrase"
fi

# --- 3. .env: Office aktivieren ----------------------------------------------------
env_set OFFICE_ENABLED true
env_set OFFICE_SECRETS_DIR "$SECRETS_DIR"
profiles="$(env_get COMPOSE_PROFILES)"
case ",$profiles," in
    *,office,*) ;;
    ,,) env_set COMPOSE_PROFILES office ;;
    *) env_set COMPOSE_PROFILES "${profiles},office" ;;
esac
[ -n "$(env_get EUROOFFICE_IMAGE_TAG)" ] || env_set EUROOFFICE_IMAGE_TAG v9.3.4-hotfix.1
[ -n "$(env_get NEXTCLOUD_IMAGE_TAG)" ] || env_set NEXTCLOUD_IMAGE_TAG 34.0.4-apache
[ -n "$WITH_AD" ] && env_set NEXTCLOUD_LDAP_ENABLED true
[ -n "$WITH_SSO" ] && env_set SSO_ENABLED true
backup_dir="$(env_get OFFICE_BACKUP_DIR)"
mkdir -p "${backup_dir:-./backups}"
chmod 700 "${backup_dir:-./backups}"

info "Konfiguration abgeschlossen (.env, ./secrets)."

if [ "$START" = "0" ]; then
    info "Start uebersprungen. Starten mit: docker compose up -d --build"
    exit 0
fi

# --- 4. Start ----------------------------------------------------------------------
info "Starte Container (erster Start laedt Images und installiert Nextcloud, ca. 3-10 Minuten) ..."
docker compose up -d --build --remove-orphans

info "Warte auf Nextcloud und Euro-Office ..."
deadline=$(( $(date +%s) + 900 ))
while :; do
    nc="$(docker compose ps --format '{{.Health}}' nextcloud 2>/dev/null || true)"
    eo="$(docker compose ps --format '{{.Health}}' eurooffice 2>/dev/null || true)"
    if [ "$nc" = "healthy" ] && [ "$eo" = "healthy" ]; then
        break
    fi
    if [ "$(date +%s)" -gt "$deadline" ]; then
        info "Zeitlimit erreicht (nextcloud=$nc, eurooffice=$eo). Logs: docker compose logs nextcloud eurooffice"
        exit 1
    fi
    sleep 10
done

app_url="$(env_get APP_URL)"
info "Fertig. Office: ${app_url:-http://localhost:8080}/office/"
nc_admin="$(env_get NEXTCLOUD_ADMIN_USER)"
info "Nextcloud-Admin: ${nc_admin:-ncadmin}, Passwort in ${SECRETS_DIR}/nextcloud_admin_password"
if [ "$ENCRYPT" = "1" ]; then
    info "WICHTIG: ${SECRETS_DIR}/office_backup_passphrase zusaetzlich sicher verwahren - ohne sie sind Sicherungen nicht lesbar."
fi
info "Im Intranet-Adminbereich unter \"Office\" den Status pruefen und die Office-Kachel anlegen."
