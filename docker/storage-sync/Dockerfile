# syntax=docker/dockerfile:1
# storage-sync: Speicher-Tiering (Hot-Tier lokal / Cold-Tier SMB oder S3) und
# HA-Synchronisation der Office-Daten. Siehe docs/storage.md.
FROM php:8.5-cli

# cifs-utils: Einbinden der SMB-Freigaben (UNC), s3fs (FUSE): Einbinden
# S3-kompatibler Buckets, inotify-tools: sofortige Erkennung von Aenderungen,
# postgresql-client: Sicherung der Nextcloud-DB.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        cifs-utils \
        fuse3 \
        inotify-tools \
        postgresql-client \
        procps \
        s3fs \
    ; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/intranet.ini
COPY docker/storage-sync/storage-sync.ini /usr/local/etc/php/conf.d/zz-storage-sync.ini
COPY docker/storage-sync/entrypoint.sh /usr/local/bin/storage-sync-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/storage-sync-entrypoint.sh && chmod +x /usr/local/bin/storage-sync-entrypoint.sh

WORKDIR /var/www/html
COPY . /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD test -f /var/lib/lanpa-tiering/agent.alive && test $(( $(date +%s) - $(stat -c %Y /var/lib/lanpa-tiering/agent.alive) )) -lt 30

ENTRYPOINT ["/usr/local/bin/storage-sync-entrypoint.sh"]
