#!/bin/sh
# Startet den SNMP-Agenten (net-snmp/snmpd).
# Erzeugt die snmpd.conf aus Umgebungsvariablen und haengt die Status-Checks
# als "exec"-Eintraege an (UCD-SNMP-MIB::extTable, .1.3.6.1.4.1.2021.8.1).
set -eu

SNMP_COMMUNITY="${SNMP_COMMUNITY:-public}"
SNMP_SYS_LOCATION="${SNMP_SYS_LOCATION:-Intranet}"
SNMP_SYS_CONTACT="${SNMP_SYS_CONTACT:-admin@example.internal}"

mkdir -p /var/lib/snmp

# Hinweis: Die Reihenfolge der exec-Eintraege bestimmt den Index (1..5) der
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
EOF

exec snmpd -f -Lo -c /etc/snmp/snmpd.conf
