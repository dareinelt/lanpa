#!/bin/sh
# Erzeugt docker-compose.sso.yml mit je einer auth-Instanz fuer jede weitere
# Domaene ohne Vertrauensstellung (Zweigstellen, Tochtergesellschaften, ...),
# fuer die in der Verwaltung die Windows-Anmeldung aktiviert ist
# (Active Directory -> Identitaetsquelle bearbeiten -> Windows-Anmeldung).
#
#   ./scripts/sso-domains.sh
#
# Domaene, Domaenencontroller, Konto fuer den Domaenenbeitritt, Client-Netze
# und Hostnamen werden ausschliesslich in der Verwaltung gepflegt
# (verschluesselt gespeichert). Jede Instanz ruft ihre Konfiguration beim
# Start von der Anwendung ab; die erzeugte Datei enthaelt nur die Dienste,
# keine Zugangsdaten. Das Skript muss nur nach dem Aktivieren bzw. Entfernen
# einer Domaene erneut ausgefuehrt werden; bei geaenderten Zugangsdaten oder
# Netzen genuegt "docker compose restart auth auth-<kennung>".
#
# Voraussetzung: der Container "app" laeuft (docker compose up -d).
set -eu

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"
OUT_FILE="$ROOT_DIR/docker-compose.sso.yml"
cd "$ROOT_DIR"

if ! sources="$(docker compose exec -T app php scripts/credentials.php --sso-sources)"; then
    echo "Fehler: Die Identitaetsquellen konnten nicht vom Container app gelesen werden (laeuft er?)." >&2
    exit 1
fi

services=""
volumes=""
keys=""

compose_file=""
if [ -f "$ENV_FILE" ]; then
    compose_file="$(grep -E '^[[:space:]]*COMPOSE_FILE=' "$ENV_FILE" | tail -n 1 | cut -d= -f2- || true)"
fi
# LLMInt auf demselben Host (docker-compose.llmint.yml): die Instanzen
# brauchen wie die Hauptinstanz Zugang zum gemeinsamen Netz.
llmint_network=""
case "$compose_file" in
    *docker-compose.llmint.yml*) llmint_network="
      - llmint" ;;
esac

while read -r key service; do
    [ -z "${key:-}" ] && continue
    if ! printf '%s' "$key" | grep -Eq '^[A-Z][A-Z0-9_]{0,31}$' \
        || ! printf '%s' "$service" | grep -Eq '^auth-[a-z0-9-]{1,32}$'; then
        echo "Fehler: Ungueltige Kennung '$key'." >&2
        exit 1
    fi
    keys="${keys:+$keys }${key}"
    volume="$(printf '%s' "$service" | tr '-' '_')_samba"
    volumes="${volumes}
  ${volume}:"
    services="${services}
  ${service}:
    build:
      context: .
      dockerfile: docker/auth/Dockerfile
    restart: unless-stopped
    hostname: \${SSO_NETBIOS_NAME:-lanpa-sso}
    cap_add:
      - SYS_TIME
    depends_on:
      app:
        condition: service_healthy
    environment:
      SSO_ENABLED: \${SSO_ENABLED:-false}
      SSO_SOURCE: ${key}
      SSO_PROXY_PROTOCOL: \"true\"
      OFFICE_ENABLED: \${OFFICE_ENABLED:-false}
      LLMINT_ENABLED: \${LLMINT_ENABLED:-false}
      LLMINT_UPSTREAM: \${LLMINT_UPSTREAM:-}
      LLMINT_PATH: \${LLMINT_PATH:-/ki}
      APP_URL: \${APP_URL:-http://localhost:8080}
      # Kerberos-SPNs wie die Hauptinstanz (Hostname aus APP_URL, SSO_SPN_HOSTS);
      # der Realm dieser Domaene wird am Domaenencontroller ermittelt.
      SSO_SPN_HOSTS: \${SSO_SPN_HOSTS:-}
    volumes:
      - sso_token:/run/intranet-sso:ro
      - ${volume}:/var/lib/samba
    networks:
      - intranet
      - office${llmint_network}
"
done <<LIST
$sources
LIST

if [ -z "$keys" ]; then
    rm -f "$OUT_FILE"
    echo "Keine weiteren Domaenen mit Windows-Anmeldung - $OUT_FILE entfernt."
    case "$compose_file" in
        *docker-compose.sso.yml*) echo "Hinweis: docker-compose.sso.yml aus COMPOSE_FILE in der .env entfernen." ;;
    esac
    echo "Danach: docker compose up -d --remove-orphans && docker compose restart auth"
    exit 0
fi

cat > "$OUT_FILE" <<YAML
# Automatisch erzeugt von scripts/sso-domains.sh - nicht von Hand bearbeiten.
# Windows-Anmeldung (NTLM) fuer weitere Domaenen: ${keys}
# Die Konfiguration (inkl. Zugangsdaten) ruft jede Instanz beim Start von der
# Anwendung ab; sie wird in der Verwaltung gepflegt.
services:${services}
volumes:${volumes}
YAML

echo "Erzeugt: $OUT_FILE (Domaenen: ${keys})"
case "$compose_file" in
    *docker-compose.sso.yml*) ;;
    *) echo "Bitte in der .env docker-compose.sso.yml zu COMPOSE_FILE hinzufuegen, z. B. COMPOSE_FILE=docker-compose.yml:docker-compose.sso.yml (ggf. :docker-compose.llmint.yml)" ;;
esac
echo "Danach: docker compose up -d --build --remove-orphans && docker compose restart auth"
