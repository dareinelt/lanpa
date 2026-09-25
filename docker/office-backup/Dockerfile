# syntax=docker/dockerfile:1
# Sicherungsdienst fuer die Office-Erweiterung (ohne Docker-Socket).
FROM alpine:3.22

RUN apk add --no-cache \
        bash \
        coreutils \
        jq \
        mariadb-client \
        mariadb-connector-c \
        openssl \
        postgresql17-client \
        tar \
        tzdata

COPY docker/office-backup/office-backup.sh /usr/local/bin/office-backup
RUN chmod 0755 /usr/local/bin/office-backup

VOLUME ["/backups"]

HEALTHCHECK --interval=60s --timeout=5s --start-period=20s --retries=3 \
    CMD test -f /tmp/office-backup-alive && [ $(( $(date +%s) - $(stat -c %Y /tmp/office-backup-alive) )) -lt 120 ]

ENTRYPOINT ["/usr/local/bin/office-backup"]
CMD ["agent"]
