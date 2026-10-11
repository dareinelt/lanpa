# syntax=docker/dockerfile:1
# Virenscanner (ClamAV) fuer die Malware-Protection der Orvanta-Anhaenge.
#
# clamd nimmt Dateiinhalte ueber TCP 3310 entgegen (Kommando INSTREAM), freshclam
# haelt die Signaturdatenbank aktuell und stoesst bei Aenderungen ein RELOAD von
# clamd an (NotifyClamd). Siehe docs/malware-protection.md.
#
# Bewusst kein clamav/clamav-Image: das offizielle Image erscheint nur fuer
# linux/amd64, waehrend Alpine ClamAV fuer amd64 und arm64 nativ mitliefert.
# Der Port wird nicht veroeffentlicht; erreichbar ist der Container nur aus dem
# internen Docker-Netz.
FROM alpine:3.21

# clamav: clamd, freshclam, clamdscan (Healthcheck), tini: sauberes SIGTERM fuer
# clamd. Das Verzeichnis /var/lib/clamav wird als Volume eingebunden; die
# Rechte werden hier gesetzt, damit ein frisch angelegtes Volume sie erbt.
RUN apk add --no-cache clamav tini \
    && mkdir -p /var/lib/clamav /var/log/clamav /run/clamav \
    && chown -R clamav:clamav /var/lib/clamav /var/log/clamav /run/clamav

COPY docker/clamav/clamd.conf /etc/clamav/clamd.conf
COPY docker/clamav/freshclam.conf /etc/clamav/freshclam.conf
COPY docker/clamav/entrypoint.sh /usr/local/bin/clamav-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/clamav-entrypoint.sh \
    && chmod +x /usr/local/bin/clamav-entrypoint.sh

ENV CLAMAV_MAX_STREAM_LENGTH=100M \
    CLAMAV_FRESHCLAM_CHECKS=24

# Signaturdatenbank (Volume clamav_data)
VOLUME ["/var/lib/clamav"]

# Nur fuer Dokumentation und Compose-Transparenz - kein Port-Mapping.
EXPOSE 3310

# Der Start kann beim ersten Mal dauern, weil die Signaturen geladen werden.
HEALTHCHECK --interval=30s --timeout=15s --start-period=180s --retries=3 \
    CMD clamdscan --ping 1 --config-file=/run/clamav/clamd.conf > /dev/null

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/clamav-entrypoint.sh"]
