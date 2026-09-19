# SNMP-Agent (net-snmp/snmpd) zur Ueberwachung der Intranet-Dienste.
#
# Der Agent liest ueber den (read-only gemounteten) Docker-Socket den Zustand
# der Container/Dienste aus und fragt den Status des AD-Synchronisations-
# Workflows direkt aus der Datenbank ab.
FROM alpine:3.20

RUN apk add --no-cache \
        net-snmp \
        net-snmp-tools \
        docker-cli \
        mariadb-client

COPY docker/snmp/entrypoint.sh /usr/local/bin/snmp-entrypoint.sh
COPY docker/snmp/check_status.sh /opt/snmp/check_status.sh

RUN chmod +x /usr/local/bin/snmp-entrypoint.sh /opt/snmp/check_status.sh

EXPOSE 161/udp

ENTRYPOINT ["/usr/local/bin/snmp-entrypoint.sh"]
