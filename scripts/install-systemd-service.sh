#!/bin/sh
# Installiert die Intranet-Landingpage als systemd-Service.
# Ziel: Ubuntu 22.04 LTS und neuer (systemd, Docker mit Compose-Plugin).
#
# Der Service fuehrt beim Start `docker compose up -d` aus und stoppt die
# Container bei `systemctl stop` sauber (`docker compose down`). Da die
# Container selbst `restart: unless-stopped` setzen, uebernimmt Docker das
# Wiederanlaufen einzelner Container – systemd sorgt nur fuer den Start
# beim Boot.
#
# Voraussetzungen:
#   - Docker inkl. Compose-Plugin installiert (`docker compose version`)
#   - .env vorhanden (cp .env.example .env) und mindestens DB_PASSWORD
#     sowie DB_ROOT_PASSWORD gesetzt
#
# Verwendung:
#   sudo ./scripts/install-systemd-service.sh
#
# Konfigurierbar ueber Umgebungsvariablen:
#   SERVICE_NAME          Name des systemd-Services (Standard: intranet)
#   COMPOSE_DIR           Verzeichnis mit der docker-compose.yml
#                         (Standard: Repository-Root)
#   COMPOSE_PROJECT_NAME  Compose-Projektname (Standard: Verzeichnisname)
#   INSTALL_ONLY=1        Unit nur installieren/aktivieren, nicht starten

set -eu

SERVICE_NAME="${SERVICE_NAME:-intranet}"
COMPOSE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-$(basename "$COMPOSE_DIR")}"
UNIT_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

# Nur als root ausfuehren
if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte mit root-Rechten ausfuehren: sudo $0" >&2
    exit 1
fi

# Docker- und Compose-Kommando erkennen (v2 Plugin vs. eigenstaendiges docker-compose)
DOCKER_BIN="$(command -v docker || true)"
if [ -n "$DOCKER_BIN" ] && docker compose version >/dev/null 2>&1; then
    COMPOSE_EXEC="$DOCKER_BIN compose"
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_EXEC="$(command -v docker-compose)"
else
    echo "Fehler: Weder 'docker compose' noch 'docker-compose' gefunden." >&2
    echo "Docker inkl. Compose-Plugin installieren, z. B.:" >&2
    echo "  sudo apt-get update && sudo apt-get install -y docker.io docker-compose-v2" >&2
    exit 1
fi

[ -f "$COMPOSE_DIR/docker-compose.yml" ] || {
    echo "Fehler: docker-compose.yml nicht gefunden in $COMPOSE_DIR" >&2
    exit 1
}

echo "Installiere systemd-Service '${SERVICE_NAME}.service' ..."
echo "  Compose-Verzeichnis: $COMPOSE_DIR"
echo "  Compose-Projekt:     $COMPOSE_PROJECT_NAME"
echo "  Compose-Befehl:      $COMPOSE_EXEC"

cat > "$UNIT_FILE" <<EOF
[Unit]
Description=Intranet-Landingpage (Docker Compose)
Requires=docker.service
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=$COMPOSE_DIR
ExecStart=$COMPOSE_EXEC --project-name $COMPOSE_PROJECT_NAME up -d
ExecStop=$COMPOSE_EXEC --project-name $COMPOSE_PROJECT_NAME down
ExecReload=$COMPOSE_EXEC --project-name $COMPOSE_PROJECT_NAME up -d
TimeoutStartSec=300

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable "$SERVICE_NAME.service"

if [ "${INSTALL_ONLY:-0}" = "1" ]; then
    echo "Unit installiert und aktiviert (nicht gestartet)."
    echo "Start mit: systemctl start $SERVICE_NAME"
else
    systemctl start "$SERVICE_NAME.service"
    systemctl --no-pager status "$SERVICE_NAME.service" || true
fi

echo ""
echo "Fertig. Nuetzliche Befehle:"
echo "  systemctl status $SERVICE_NAME    # Status anzeigen"
echo "  systemctl stop $SERVICE_NAME      # Container stoppen"
echo "  systemctl disable $SERVICE_NAME   # Autostart deaktivieren"
echo "  docker compose logs -f app        # App-Protokolle"
