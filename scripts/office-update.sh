#!/bin/sh
# Aktualisiert Euro-Office und Nextcloud aus den offiziellen Quellen.
#
#   ./scripts/office-update.sh                    Apps (App-Store) + Images der
#                                                 in .env festgelegten Versionen
#   ./scripts/office-update.sh --eurooffice v9.3.5 --nextcloud 34.0.5-apache
#                                                 neue Versionen festlegen
#   ./scripts/office-update.sh --check            nur verfuegbare Versionen anzeigen
#
# Quellen:
#   - DocumentServer: ghcr.io/euro-office/documentserver (GitHub Releases
#     https://github.com/Euro-Office/DocumentServer/releases)
#   - Nextcloud:      Docker Hub "nextcloud" (offizielles Image)
#   - Connector:      Nextcloud-App-Store, App "eurooffice"
#
# Vor dem Update wird automatisch eine Sicherung erstellt (--no-backup
# ueberspringt das). Nextcloud-Upgrades nur schrittweise (eine Hauptversion
# nach der anderen); das offizielle Image fuehrt "occ upgrade" selbst aus.
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

EO_TAG=""
NC_TAG=""
BACKUP=1
CHECK=0
while [ $# -gt 0 ]; do
    case "$1" in
        --eurooffice) EO_TAG="${2:?Version fehlt}"; shift 2 ;;
        --nextcloud) NC_TAG="${2:?Version fehlt}"; shift 2 ;;
        --no-backup) BACKUP=0; shift ;;
        --check) CHECK=1; shift ;;
        -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
        *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
    esac
done

info() { printf '\033[1m[office-update]\033[0m %s\n' "$*"; }
env_get() { sed -n "s/^$1=//p" .env | tail -n1; }
env_set() {
    tmp="$(mktemp)"
    if grep -q "^$1=" .env; then
        awk -v k="$1" -v v="$2" 'BEGIN{FS=OFS="="} $1==k{print k"="v; next} {print}' .env > "$tmp"
    else
        cat .env > "$tmp"; printf '%s=%s\n' "$1" "$2" >> "$tmp"
    fi
    cat "$tmp" > .env; rm -f "$tmp"
}
occ() { docker compose exec -T -u www-data nextcloud php occ "$@"; }

[ -f .env ] || { echo ".env fehlt - zuerst ./scripts/office-setup.sh ausfuehren." >&2; exit 1; }

current_eo="$(env_get EUROOFFICE_IMAGE_TAG)"; current_eo="${current_eo:-v9.3.4-hotfix.1}"
current_nc="$(env_get NEXTCLOUD_IMAGE_TAG)"; current_nc="${current_nc:-34.0.4-apache}"

if [ "$CHECK" = "1" ]; then
    info "Installiert: DocumentServer ${current_eo}, Nextcloud ${current_nc}"
    if command -v curl >/dev/null 2>&1; then
        latest="$(curl -fsSL https://api.github.com/repos/Euro-Office/DocumentServer/releases/latest 2>/dev/null \
            | sed -n 's/.*"tag_name": *"\([^"]*\)".*/\1/p' | head -n1)"
        info "Neueste DocumentServer-Version (GitHub): ${latest:-unbekannt}"
    fi
    info "Connector/Apps (App-Store):"
    occ app:update --showonly || true
    exit 0
fi

if [ "$BACKUP" = "1" ]; then
    info "Sicherung vor dem Update ..."
    ./scripts/office-backup.sh
fi

[ -n "$EO_TAG" ] && env_set EUROOFFICE_IMAGE_TAG "$EO_TAG" && info "DocumentServer: ${current_eo} -> ${EO_TAG}"
[ -n "$NC_TAG" ] && env_set NEXTCLOUD_IMAGE_TAG "$NC_TAG" && info "Nextcloud: ${current_nc} -> ${NC_TAG}"

info "Lade Images der festgelegten Versionen ..."
docker compose pull nextcloud nextcloud-cron eurooffice nextcloud-db nextcloud-redis
info "Starte aktualisierte Container ..."
docker compose up -d

info "Warte auf Nextcloud ..."
i=0
until [ "$(docker compose ps --format '{{.Health}}' nextcloud)" = "healthy" ]; do
    i=$((i + 1)); [ "$i" -gt 90 ] && { info "Nextcloud wird nicht gesund - Logs pruefen."; exit 1; }
    sleep 10
done

info "Aktualisiere Nextcloud-Apps (inkl. Euro-Office-Connector) aus dem App-Store ..."
occ app:update --all
occ upgrade || true
occ maintenance:repair --include-expensive >/dev/null || true
occ eurooffice:documentserver --check || info "Connector-Pruefung meldet einen Fehler - Intranet-Adminbereich > Office > Diagnose."
info "Update abgeschlossen."
