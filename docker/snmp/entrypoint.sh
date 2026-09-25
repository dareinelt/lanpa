#!/bin/sh
# Startet den SNMP-Agenten (net-snmp/snmpd).
# Erzeugt die snmpd.conf aus Umgebungsvariablen und haengt die Status-Checks
# als "exec"-Eintraege an (UCD-SNMP-MIB::extTable, .1.3.6.1.4.1.2021.8.1).
set -eu

SNMP_COMMUNITY="${SNMP_COMMUNITY:-public}"
SNMP_SYS_LOCATION="${SNMP_SYS_LOCATION:-Intranet}"
SNMP_SYS_CONTACT="${SNMP_SYS_CONTACT:-admin@example.internal}"

# Im Adminbereich gepflegte Werte (Tabelle `settings`) haben Vorrang vor den
# Umgebungsvariablen. Ist die Datenbank (noch) nicht erreichbar, bleiben die
# Umgebungswerte gueltig.
db_host="${DB_HOST:-db}"
db_port="${DB_PORT:-3306}"
db_user="${DB_USER:-intranet}"
db_name="${DB_NAME:-intranet}"
db_pass="${DB_PASSWORD:-}"
db_pass_file="${DB_PASSWORD_FILE:-}"
[ -n "$db_pass_file" ] && [ -r "$db_pass_file" ] && db_pass="$(cat "$db_pass_file")"

setting() {
    key="${1}"
    MYSQL_PWD="$db_pass" mysql --protocol=tcp -h "$db_host" -P "$db_port" -u "$db_user" -N -B \
        -e "SELECT setting_value FROM \`${db_name}\`.settings WHERE setting_key = '${key}'" 2>/dev/null
}

db_community="$(setting snmp_community || true)"
[ -n "$db_community" ] && SNMP_COMMUNITY="$db_community"
db_location="$(setting snmp_sys_location || true)"
[ -n "$db_location" ] && SNMP_SYS_LOCATION="$db_location"
db_contact="$(setting snmp_sys_contact || true)"
[ -n "$db_contact" ] && SNMP_SYS_CONTACT="$db_contact"

mkdir -p /var/lib/snmp

# Hinweis: Die Reihenfolge der exec-Eintraege bestimmt den Index (1..10) der
# extResult-/extOutput-OIDs. Bei Aenderungen die README-Dokumentation anpassen.
cat > /etc/snmp/snmpd.conf <<EOF
# Erzeugt vom Entrypoint – nicht manuell bearbeiten.
sysLocation  ${SNMP_SYS_LOCATION}
sysContact   ${SNMP_SYS_CONTACT}
agentaddress udp:161
rocommunity  ${SNMP_COMMUNITY}

exec app           /opt/snmp/check_status.sh app
exec db            /opt/snmp/check_status.sh db
exec sync          /opt/snmp/check_status.sh sync
exec sync_workflow /opt/snmp/check_status.sh sync_workflow
exec phpmyadmin    /opt/snmp/check_status.sh phpmyadmin
exec nextcloud       /opt/snmp/check_status.sh nextcloud
exec nextcloud_db    /opt/snmp/check_status.sh nextcloud_db
exec nextcloud_redis /opt/snmp/check_status.sh nextcloud_redis
exec eurooffice      /opt/snmp/check_status.sh eurooffice
exec office_workflow /opt/snmp/check_status.sh office_workflow
EOF

# -C: nur diese Datei lesen (sonst wird snmpd.conf doppelt geladen -> doppelte
# extend-Eintraege und "Error opening specified endpoint udp:161").
exec snmpd -f -Lo -C -c /etc/snmp/snmpd.conf
