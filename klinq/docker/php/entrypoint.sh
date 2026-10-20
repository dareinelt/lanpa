#!/bin/sh
set -e

# Wartet auf MySQL, führt Migrationen + Seeder aus und startet danach den eigentlichen Prozess.
if [ "${SKIP_MIGRATIONS:-0}" != "1" ]; then
    echo "[entrypoint] Warte auf Datenbank ${DB_HOST:-mysql}:${DB_PORT:-3306} ..."
    i=0
    until php -r 'try { new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; do
        i=$((i+1))
        if [ "$i" -ge 60 ]; then
            echo "[entrypoint] Datenbank nicht erreichbar – Abbruch." >&2
            exit 1
        fi
        sleep 2
    done
    php bin/migrate.php
fi


# Unterpfad im Intranet (z. B. /klinq). Der Proxy entfernt ihn vor der Anfrage; Apache
# ergaenzt ihn in den Antworten (siehe docker/php/apache-vhost.conf). Leer = Betrieb unter "/".
KLINQ_BASE_PATH="${KLINQ_BASE_PATH:-}"
case "$KLINQ_BASE_PATH" in
    ""|"/") KLINQ_BASE_PATH="" ;;
    *) KLINQ_BASE_PATH="/$(printf '%s' "$KLINQ_BASE_PATH" | sed 's#^/*##; s#/*$##')" ;;
esac
export KLINQ_BASE_PATH

mkdir -p storage/uploads storage/logs storage/labels storage/tmp
chown -R www-data:www-data storage

# Zeitgesteuerte Jobs (AD-Synchronisation, Help-Desk-SLA/Eskalation, Help-Desk-E-Mail-Eingang) als Hintergrundprozess (nur wenn konfiguriert)
ad_scheduled=0; hd_scheduled=0; mail_scheduled=0
if [ "${AD_ENABLED:-false}" = "true" ] && [ "${AD_SYNC_INTERVAL_MINUTES:-0}" != "0" ]; then ad_scheduled=1; fi
if [ "${HELPDESK_ENABLED:-true}" = "true" ] && [ "${HELPDESK_ESCALATION_ENABLED:-true}" = "true" ] && [ "${HELPDESK_ESCALATION_INTERVAL_MINUTES:-5}" != "0" ]; then hd_scheduled=1; fi
if [ "${HELPDESK_ENABLED:-true}" = "true" ] && [ "${HELPDESK_MAIL_ENABLED:-false}" = "true" ] && [ "${HELPDESK_MAIL_INTERVAL_MINUTES:-2}" != "0" ]; then mail_scheduled=1; fi
if [ "$ad_scheduled" = "1" ] || [ "$hd_scheduled" = "1" ] || [ "$mail_scheduled" = "1" ]; then
    su -s /bin/sh www-data -c "/usr/local/bin/app-scheduler" &
fi

exec "$@"
