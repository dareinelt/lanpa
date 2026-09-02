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

if [ "$CONTAINER_ROLE" = "app" ]; then
    echo "[entrypoint] Führe Migrationen aus ..."
    php scripts/migrate.php

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
fi

exec "$@"
