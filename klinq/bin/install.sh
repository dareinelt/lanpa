#!/usr/bin/env bash
#
# Docker-freie Installation von KLINQ (PHP 8.4 + Apache, MySQL, Python-Dienste
# für PDF/E-Mail) auf einem Debian/Ubuntu-Host. Richtet alle Abhängigkeiten, eine Datenbank,
# die Anwendung samt Migrationen sowie ein SSL-Zertifikat für Apache ein.
#
# SSL-Varianten (siehe --ssl-mode):
#   selfsigned   (Standard) Erzeugt ein selbstsigniertes Zertifikat – sofort einsatzbereit.
#   csr          Erzeugt nur Schlüssel + CSR (database) zur Einreichung bei einer CA;
#                Apache läuft bis zum Import mit einem selbstsignierten Übergangszertifikat.
#   install-cert Spielt ein von der CA signiertes Zertifikat (passend zum zuvor erzeugten CSR)
#                ein und aktiviert es in Apache.
#
# Aufruf (Beispiele):
#   sudo bin/install.sh                                   # Komplettinstallation, selbstsigniert
#   sudo bin/install.sh --domain assets.example.local --ssl-mode csr
#   sudo bin/install.sh --ssl-mode install-cert --cert /pfad/zertifikat.crt --chain /pfad/kette.crt
#   sudo bin/install.sh --skip-packages --skip-mysql       # Wiederholter Lauf, nur Konfiguration
#
# Wiederholtes Ausführen ist unschädlich (idempotent), z. B. um nach einem `git pull` erneut
# zu migrieren oder ein neues Zertifikat einzuspielen.

set -euo pipefail

# --- Standardwerte -----------------------------------------------------------------------

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_DIR="/var/www/assets"
DOMAIN="$(hostname -f 2>/dev/null || hostname)"
SSL_MODE="selfsigned"
CERT_FILE=""
KEY_FILE=""
CHAIN_FILE=""
SKIP_PACKAGES=0
SKIP_MYSQL=0
SKIP_APACHE=0
SKIP_MIGRATE=0
NON_INTERACTIVE=0
SSL_DIR="/etc/ssl/assets"
PY_VENV_DIR="/opt/assets-services/venv"
APP_PORT=""
APP_TLS_PORT="443"

log()  { printf '\033[1;34m[install]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[install]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[install]\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'USAGE'
Verwendung: sudo bin/install.sh [Optionen]

Allgemein:
  --app-dir <pfad>        Zielverzeichnis der Anwendung (Standard: /var/www/assets)
  --domain <fqdn>         Domainname für APP_URL, Apache-ServerName und Zertifikat
  --app-port <port>       HTTP-Port (Standard: 80, mit TLS Weiterleitung auf --tls-port)
  --tls-port <port>       HTTPS-Port (Standard: 443)
  --skip-packages         Paketinstallation (apt) überspringen
  --skip-mysql            MySQL-Einrichtung (Datenbank/Benutzer) überspringen
  --skip-apache           Apache-Konfiguration überspringen
  --skip-migrate          Datenbankmigration/Seeder überspringen
  --non-interactive       Keine Rückfragen; fehlende Werte werden generiert/aus ENV gelesen
  -h, --help              Diese Hilfe anzeigen

SSL/TLS (--ssl-mode):
  selfsigned              (Standard) selbstsigniertes Zertifikat erzeugen
  csr                     nur privaten Schlüssel + CSR erzeugen (Ausgabe unter --ssl-dir)
  install-cert            von einer CA signiertes Zertifikat einspielen, benötigt --cert
                           (und optional --chain); der passende Schlüssel muss bereits unter
                           --ssl-dir liegen (aus einem vorherigen --ssl-mode csr Lauf)

  --ssl-dir <pfad>        Ablageort für Schlüssel/Zertifikat/CSR (Standard: /etc/ssl/assets)
  --cert <datei>          Von der CA signiertes Zertifikat (nur --ssl-mode install-cert)
  --key <datei>           Privater Schlüssel, falls nicht bereits unter --ssl-dir vorhanden
  --chain <datei>         Zwischenzertifikate/CA-Kette (optional, nur install-cert)

Umgebungsvariablen (nicht-interaktiv, siehe .env.example für die vollständige Liste):
  DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_ROOT_PASSWORD, ADMIN_USERNAME, ADMIN_PASSWORD
USAGE
}

# --- Argumente parsen ---------------------------------------------------------------------

while [ $# -gt 0 ]; do
    case "$1" in
        --app-dir) APP_DIR="$2"; shift 2 ;;
        --domain) DOMAIN="$2"; shift 2 ;;
        --app-port) APP_PORT="$2"; shift 2 ;;
        --tls-port) APP_TLS_PORT="$2"; shift 2 ;;
        --ssl-mode) SSL_MODE="$2"; shift 2 ;;
        --ssl-dir) SSL_DIR="$2"; shift 2 ;;
        --cert) CERT_FILE="$2"; shift 2 ;;
        --key) KEY_FILE="$2"; shift 2 ;;
        --chain) CHAIN_FILE="$2"; shift 2 ;;
        --skip-packages) SKIP_PACKAGES=1; shift ;;
        --skip-mysql) SKIP_MYSQL=1; shift ;;
        --skip-apache) SKIP_APACHE=1; shift ;;
        --skip-migrate) SKIP_MIGRATE=1; shift ;;
        --non-interactive) NON_INTERACTIVE=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die "Unbekannte Option: $1 (siehe --help)" ;;
    esac
done

case "$SSL_MODE" in
    selfsigned|csr|install-cert) ;;
    *) die "--ssl-mode muss selfsigned, csr oder install-cert sein (erhalten: $SSL_MODE)" ;;
esac

[ "$SSL_MODE" = "install-cert" ] && [ -z "$CERT_FILE" ] && die "--ssl-mode install-cert benötigt --cert <datei>"

if [ "$(id -u)" -ne 0 ]; then
    die "Bitte als root ausführen (sudo bin/install.sh ...)."
fi

# --- Hilfsfunktionen -----------------------------------------------------------------------

random_secret() { openssl rand -hex 16; }

prompt_default() {
    # prompt_default <var-name> <frage> <default>
    local __var="$1" __question="$2" __default="$3" __answer
    if [ "$NON_INTERACTIVE" = "1" ]; then
        printf -v "$__var" '%s' "${!__var:-$__default}"
        return
    fi
    read -r -p "$__question [$__default]: " __answer || true
    printf -v "$__var" '%s' "${__answer:-$__default}"
}

# --- 1) Betriebssystem-Pakete ---------------------------------------------------------------

detect_os() {
    [ -r /etc/os-release ] || die "Kein /etc/os-release gefunden – nur Debian/Ubuntu werden unterstützt."
    . /etc/os-release
    OS_ID="${ID:-}"
    OS_VERSION_CODENAME="${VERSION_CODENAME:-}"
    case "$OS_ID" in
        debian|ubuntu) ;;
        *) die "Nicht unterstützte Distribution '$OS_ID' – nur Debian/Ubuntu werden unterstützt." ;;
    esac
}

setup_php_repo() {
    # PHP 8.4 liegt in den Standard-Repositories von Debian/Ubuntu meist noch nicht vor.
    if php -v 2>/dev/null | grep -q '^PHP 8\.4'; then
        return
    fi
    install -d -m 0755 /etc/apt/keyrings
    if [ "$OS_ID" = "ubuntu" ]; then
        apt-get install -y --no-install-recommends software-properties-common
        add-apt-repository -y ppa:ondrej/php
    else
        curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/keyrings/sury-php.gpg
        echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${OS_VERSION_CODENAME} main" \
            > /etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update
}

install_packages() {
    if [ "$SKIP_PACKAGES" = "1" ]; then
        log "Paketinstallation übersprungen (--skip-packages)."
        return
    fi
    log "Installiere Systempakete (Apache, PHP 8.4, MySQL, Python, OpenSSL) ..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y --no-install-recommends ca-certificates curl gnupg lsb-release rsync openssl
    setup_php_repo

    apt-get install -y --no-install-recommends \
        apache2 \
        php8.4 php8.4-mysql php8.4-ldap php8.4-gd php8.4-zip php8.4-opcache php8.4-mbstring php8.4-curl php8.4-xml \
        mysql-server \
        python3 python3-venv python3-pip \
        libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz0b libffi8 libcairo2 libgdk-pixbuf-2.0-0 \
        fonts-dejavu-core fonts-liberation shared-mime-info

    a2enmod php8.4 rewrite headers substitute ssl >/dev/null
    systemctl enable --now apache2 mysql
}

# --- 2) MySQL-Datenbank und Anwendungsbenutzer ----------------------------------------------

configure_mysql() {
    if [ "$SKIP_MYSQL" = "1" ]; then
        log "MySQL-Einrichtung übersprungen (--skip-mysql)."
        return
    fi
    log "Richte MySQL-Datenbank und Anwendungsbenutzer ein ..."
    systemctl is-active --quiet mysql || systemctl start mysql

    # Root-Login über unix_socket (Standard bei mysql-server-Paketen von Debian/Ubuntu).
    mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}_test\`.* TO '${DB_USERNAME}'@'localhost';
FLUSH PRIVILEGES;
SQL
}

# --- 3) Anwendung bereitstellen und konfigurieren -------------------------------------------

deploy_app() {
    log "Kopiere Anwendung nach ${APP_DIR} ..."
    mkdir -p "$APP_DIR"
    rsync -a --delete \
        --exclude='.git' --exclude='.env' --exclude='storage/uploads' --exclude='storage/logs' \
        --exclude='storage/labels' --exclude='storage/tmp' \
        "$REPO_DIR"/ "$APP_DIR"/

    mkdir -p "$APP_DIR"/storage/uploads "$APP_DIR"/storage/logs "$APP_DIR"/storage/labels "$APP_DIR"/storage/tmp
    chown -R www-data:www-data "$APP_DIR"/storage
}

configure_env() {
    local env_file="$APP_DIR/.env"
    if [ -f "$env_file" ]; then
        log ".env existiert bereits – Werte werden übernommen, keine Passwörter neu erzeugt."
        # shellcheck disable=SC1090
        set -a; . "$env_file"; set +a
    else
        log "Erzeuge .env aus .env.example ..."
        cp "$REPO_DIR/.env.example" "$env_file"
    fi

    DB_DATABASE="${DB_DATABASE:-assets}"
    DB_USERNAME="${DB_USERNAME:-assets}"
    DB_PASSWORD="${DB_PASSWORD:-$(random_secret)}"
    DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-$(random_secret)}"
    ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
    ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(random_secret)}"

    prompt_default DOMAIN "Domainname/Hostname der Anwendung" "$DOMAIN"
    prompt_default DB_DATABASE "Datenbankname" "$DB_DATABASE"
    prompt_default DB_USERNAME "Datenbankbenutzer" "$DB_USERNAME"
    prompt_default ADMIN_USERNAME "Erster Administrator (Benutzername)" "$ADMIN_USERNAME"

    local scheme="http" port_suffix=""
    if [ "$SSL_MODE" != "none" ] && [ "$SKIP_APACHE" != "1" ]; then
        scheme="https"
        [ "$APP_TLS_PORT" != "443" ] && port_suffix=":${APP_TLS_PORT}"
    fi
    local app_url="${scheme}://${DOMAIN}${port_suffix}"

    python3 - "$env_file" <<PYEOF
import re
path = "$env_file"
values = {
    "APP_URL": "$app_url",
    "APP_PORT": "${APP_PORT:-80}",
    "DB_HOST": "127.0.0.1",
    "DB_DATABASE": "$DB_DATABASE",
    "DB_USERNAME": "$DB_USERNAME",
    "DB_PASSWORD": "$DB_PASSWORD",
    "DB_ROOT_PASSWORD": "$DB_ROOT_PASSWORD",
    "SESSION_SECURE": "true" if "$scheme" == "https" else "",
    "ADMIN_USERNAME": "$ADMIN_USERNAME",
    "ADMIN_PASSWORD": "$ADMIN_PASSWORD",
    "PDF_SERVICE_URL": "http://127.0.0.1:8000",
    "MAIL_SERVICE_URL": "http://127.0.0.1:8025",
}
with open(path, encoding="utf-8") as fh:
    lines = fh.readlines()
seen = set()
for i, line in enumerate(lines):
    m = re.match(r"^([A-Z0-9_]+)=", line)
    if m and m.group(1) in values:
        key = m.group(1)
        lines[i] = f"{key}={values[key]}\n"
        seen.add(key)
for key, value in values.items():
    if key not in seen:
        lines.append(f"{key}={value}\n")
with open(path, "w", encoding="utf-8") as fh:
    fh.writelines(lines)
PYEOF

    chown www-data:www-data "$env_file"
    chmod 640 "$env_file"

    log "Zugangsdaten (bitte notieren, ADMIN_PASSWORD wird nach dem ersten Login nicht mehr benötigt):"
    log "  Datenbank: ${DB_DATABASE} / ${DB_USERNAME} / ${DB_PASSWORD}"
    log "  Administrator: ${ADMIN_USERNAME} / ${ADMIN_PASSWORD}"
}

run_migrations() {
    if [ "$SKIP_MIGRATE" = "1" ]; then
        log "Migrationen übersprungen (--skip-migrate)."
        return
    fi
    log "Führe Datenbankmigrationen und Seeder aus ..."
    (cd "$APP_DIR" && sudo -u www-data php bin/migrate.php)
}

# --- 4) Python-Dienste (PDF/E-Mail) als systemd-Dienste --------------------------------------

setup_python_services() {
    log "Richte Python-Dienste für PDF-Erzeugung und E-Mail-Versand ein ..."
    python3 -m venv "$PY_VENV_DIR"
    "$PY_VENV_DIR/bin/pip" install --upgrade pip >/dev/null
    "$PY_VENV_DIR/bin/pip" install weasyprint==63.1

    install -d -m 0755 /opt/assets-services/pdf /opt/assets-services/mail
    install -m 0644 "$APP_DIR/docker/pdf/server.py" /opt/assets-services/pdf/server.py
    install -m 0644 "$APP_DIR/docker/mail/server.py" /opt/assets-services/mail/server.py

    id assetspdf >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin assetspdf
    id assetsmail >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin assetsmail

    cat > /etc/systemd/system/assets-pdf.service <<UNIT
[Unit]
Description=KLINQ PDF-Dienst (WeasyPrint)
After=network.target

[Service]
User=assetspdf
Environment=PDF_PORT=8000
ExecStart=${PY_VENV_DIR}/bin/python /opt/assets-services/pdf/server.py
Restart=on-failure
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT

    cat > /etc/systemd/system/assets-mail.service <<UNIT
[Unit]
Description=KLINQ E-Mail-Dienst (SMTP-Relay)
After=network.target

[Service]
User=assetsmail
EnvironmentFile=${APP_DIR}/.env
Environment=MAIL_PORT=8025
ExecStart=${PY_VENV_DIR}/bin/python /opt/assets-services/mail/server.py
Restart=on-failure
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT

    cat > /etc/systemd/system/assets-scheduler.service <<UNIT
[Unit]
Description=KLINQ Scheduler (AD-Sync, Help Desk)
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/local/bin/assets-scheduler
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT

    install -m 0755 "$APP_DIR/docker/php/scheduler.sh" /usr/local/bin/assets-scheduler

    systemctl daemon-reload
    systemctl enable --now assets-pdf.service assets-mail.service assets-scheduler.service
}

# --- 5) SSL/TLS-Zertifikat -------------------------------------------------------------------

ssl_selfsigned() {
    log "Erzeuge selbstsigniertes SSL-Zertifikat für ${DOMAIN} ..."
    install -d -m 0750 -o root -g ssl-cert "$SSL_DIR" 2>/dev/null || install -d -m 0750 "$SSL_DIR"
    openssl req -x509 -nodes -newkey rsa:4096 -days 825 \
        -keyout "$SSL_DIR/server.key" -out "$SSL_DIR/server.crt" \
        -subj "/CN=${DOMAIN}" \
        -addext "subjectAltName=DNS:${DOMAIN}"
    chmod 640 "$SSL_DIR/server.key"
    rm -f "$SSL_DIR/server-chain.crt"
}

ssl_csr() {
    log "Erzeuge privaten Schlüssel und CSR für ${DOMAIN} ..."
    install -d -m 0750 "$SSL_DIR"
    openssl req -new -nodes -newkey rsa:4096 \
        -keyout "$SSL_DIR/server.key" -out "$SSL_DIR/server.csr" \
        -subj "/CN=${DOMAIN}" \
        -addext "subjectAltName=DNS:${DOMAIN}"
    chmod 640 "$SSL_DIR/server.key"

    # Damit Apache sofort startfähig ist, zusätzlich ein Übergangszertifikat erzeugen –
    # es wird durch --ssl-mode install-cert später ersetzt.
    openssl req -x509 -nodes -key "$SSL_DIR/server.key" -days 30 \
        -out "$SSL_DIR/server.crt" \
        -subj "/CN=${DOMAIN}" \
        -addext "subjectAltName=DNS:${DOMAIN}"

    log "CSR erzeugt: ${SSL_DIR}/server.csr"
    log "Bei der Zertifizierungsstelle einreichen. Anschließend einspielen mit:"
    log "  sudo bin/install.sh --ssl-mode install-cert --cert <zertifikat.crt> [--chain <kette.crt>] --skip-packages --skip-mysql --skip-migrate"
}

ssl_install_cert() {
    [ -f "$CERT_FILE" ] || die "Zertifikatsdatei nicht gefunden: $CERT_FILE"
    [ -n "$KEY_FILE" ] && [ ! -f "$KEY_FILE" ] && die "Schlüsseldatei nicht gefunden: $KEY_FILE"
    local key_path="${KEY_FILE:-$SSL_DIR/server.key}"
    [ -f "$key_path" ] || die "Kein privater Schlüssel unter ${key_path} – zuerst --ssl-mode csr ausführen oder --key angeben."

    log "Prüfe, ob Zertifikat und Schlüssel zusammenpassen ..."
    local cert_mod key_mod
    cert_mod="$(openssl x509 -noout -modulus -in "$CERT_FILE" | openssl md5)"
    key_mod="$(openssl rsa -noout -modulus -in "$key_path" | openssl md5)"
    [ "$cert_mod" = "$key_mod" ] || die "Zertifikat und Schlüssel passen nicht zusammen."

    install -d -m 0750 "$SSL_DIR"
    install -m 0644 "$CERT_FILE" "$SSL_DIR/server.crt"
    [ "$key_path" != "$SSL_DIR/server.key" ] && install -m 0640 "$key_path" "$SSL_DIR/server.key"
    if [ -n "$CHAIN_FILE" ]; then
        [ -f "$CHAIN_FILE" ] || die "Zertifikatskette nicht gefunden: $CHAIN_FILE"
        install -m 0644 "$CHAIN_FILE" "$SSL_DIR/server-chain.crt"
    fi
    log "Zertifikat eingespielt: ${SSL_DIR}/server.crt"
}

configure_apache() {
    if [ "$SKIP_APACHE" = "1" ]; then
        log "Apache-Konfiguration übersprungen (--skip-apache)."
        return
    fi

    case "$SSL_MODE" in
        selfsigned) ssl_selfsigned ;;
        csr) ssl_csr ;;
        install-cert) ssl_install_cert ;;
    esac

    log "Richte Apache-Vhosts für ${DOMAIN} ein ..."
    local chain_directive=""
    [ -f "$SSL_DIR/server-chain.crt" ] && chain_directive="SSLCertificateChainFile ${SSL_DIR}/server-chain.crt"

    cat > /etc/apache2/sites-available/assets.conf <<VHOST
<VirtualHost *:${APP_PORT:-80}>
    ServerName ${DOMAIN}
    Redirect permanent / https://${DOMAIN}/
</VirtualHost>

<VirtualHost *:${APP_TLS_PORT}>
    ServerName ${DOMAIN}
    DocumentRoot ${APP_DIR}/public

    SSLEngine on
    SSLCertificateFile ${SSL_DIR}/server.crt
    SSLCertificateKeyFile ${SSL_DIR}/server.key
    ${chain_directive}

    <Directory ${APP_DIR}/public>
        AllowOverride None
        Require all granted
        Options -Indexes
        FallbackResource /index.php
    </Directory>

    AddType application/manifest+json .webmanifest
    AddType text/javascript .js .mjs

    <FilesMatch "\.(css|js|mjs|svg|png|webmanifest)$">
        Header set Cache-Control "no-cache, must-revalidate, max-age=0"
        Header set Expires "Thu, 01 Jan 1970 00:00:00 GMT"
    </FilesMatch>

    <Directory ${APP_DIR}/storage>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/assets-error.log
    CustomLog \${APACHE_LOG_DIR}/assets-access.log combined
</VirtualHost>
VHOST

    if [ -n "$APP_PORT" ] && [ "$APP_PORT" != "80" ]; then
        grep -q "^Listen ${APP_PORT}\$" /etc/apache2/ports.conf || echo "Listen ${APP_PORT}" >> /etc/apache2/ports.conf
    fi
    grep -q "^Listen ${APP_TLS_PORT}\$" /etc/apache2/ports.conf || echo "Listen ${APP_TLS_PORT}" >> /etc/apache2/ports.conf

    a2dissite 000-default >/dev/null 2>&1 || true
    a2ensite assets >/dev/null
    apache2ctl configtest
    systemctl reload apache2 2>/dev/null || systemctl restart apache2
}

# --- Ablauf ----------------------------------------------------------------------------------

main() {
    detect_os
    install_packages
    deploy_app
    configure_env
    [ "$SKIP_MYSQL" = "1" ] || configure_mysql
    run_migrations
    setup_python_services
    configure_apache

    log "Fertig. Anwendung erreichbar unter: ${APP_URL:-http(s)://${DOMAIN}}"
    if [ "$SSL_MODE" = "csr" ]; then
        log "Hinweis: Es läuft derzeit ein Übergangszertifikat, bis das CA-Zertifikat eingespielt wird (--ssl-mode install-cert)."
    fi
}

main "$@"
