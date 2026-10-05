# syntax=docker/dockerfile:1
# mail-archive: Orvanta-Langzeitarchiv. Ein schlanker Python-Supervisor
# (archive_supervisor.py) steuert den PHP-Archivierungs-Worker
# (scripts/orvanta_archive_worker.php --once) mit Intervall, exponentiellem
# Backoff und sauberem Shutdown. Die gesamte Exchange-/Archivlogik bleibt in
# den PHP-Diensten (keine parallele EWS- oder DB-Abstraktion in Python).
FROM php:8.5-cli

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        python3 \
    ; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/intranet.ini
COPY docker/mail-archive/archive_supervisor.py /usr/local/bin/archive_supervisor.py

WORKDIR /var/www/html
COPY . /var/www/html

ENTRYPOINT ["python3", "/usr/local/bin/archive_supervisor.py"]
