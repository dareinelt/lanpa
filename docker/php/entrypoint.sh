#!/bin/sh
# Startskript der Container "app" und "sync".
set -eu

cd /var/www/html

mkdir -p storage/logs storage/uploads
chown -R www-data:www-data storage || true

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
CONTAINER_ROLE="${CONTAINER_ROLE:-app}"

echo "[entrypoint] Warte auf die Datenbank ${DB_HOST}:${DB_PORT} ..."
i=0
until php -r '
    $host = getenv("DB_HOST") ?: "db";
    $port = (int) (getenv("DB_PORT") ?: 3306);
    $name = getenv("DB_NAME") ?: "intranet";
    $user = getenv("DB_USER") ?: "intranet";
    $pass = getenv("DB_PASSWORD") ?: "";
    $file = getenv("DB_PASSWORD_FILE");
    if ($file && is_readable($file)) { $pass = trim((string) file_get_contents($file)); }
    try {
        new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
    } catch (Throwable $e) { exit(1); }
'; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] Datenbank nicht erreichbar - Abbruch." >&2
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] Datenbank erreichbar."

# Schluessel fuer verschluesselt gespeicherte Zugangsdaten (storage/keys/).
# Wird als root erzeugt und anschliessend www-data uebergeben.
fix_key_permissions() {
    if [ -d storage/keys ]; then
        chown -R www-data:www-data storage/keys || true
        chmod 700 storage/keys || true
        chmod 600 storage/keys/* 2>/dev/null || true
    fi
}

if [ "$CONTAINER_ROLE" = "app" ]; then
    echo "[entrypoint] Führe Migrationen aus ..."
    php scripts/migrate.php

    # Schluessel sicherstellen und Zugangsdaten frueherer Versionen aus der
    # Umgebung einmalig verschluesselt in die Datenbank uebernehmen.
    php scripts/credentials.php || echo "[entrypoint] WARNUNG: Zugangsdaten-Schlüssel konnte nicht eingerichtet werden." >&2
    fix_key_permissions

    # Token fuer den Abruf der SSO-Konfiguration durch die auth-Container
    # (gemeinsames Volume, nur app und auth-* haben Zugriff).
    SSO_TOKEN_FILE="${SSO_CONFIG_TOKEN_FILE:-/run/intranet-sso/token}"
    if [ -d "$(dirname "$SSO_TOKEN_FILE")" ]; then
        if [ ! -s "$SSO_TOKEN_FILE" ]; then
            (umask 077 && php -r 'echo bin2hex(random_bytes(32)), "\n";' > "$SSO_TOKEN_FILE")
        fi
        chown root:www-data "$SSO_TOKEN_FILE" || true
        chmod 640 "$SSO_TOKEN_FILE" || true
    fi

    if [ "${SEED_ON_START:-true}" = "true" ]; then
        echo "[entrypoint] Führe Seeder aus ..."
        php scripts/seed.php
    fi

    ADMIN_COUNT="$(php -r 'require "/var/www/html/bootstrap.php"; echo (new App\Repositories\AdminUserRepository())->count();')"
    if [ "$ADMIN_COUNT" = "0" ]; then
        echo "[entrypoint] Lege Administrationskonto an ..."
        php scripts/create_admin.php
    fi
else
    # Der Sync-Container wartet kurz, damit der App-Container die Migrationen abschliessen kann.
    sleep 10
    php scripts/credentials.php --key || true
    fix_key_permissions
fi

exec "$@"
