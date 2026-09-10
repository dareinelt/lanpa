# syntax=docker/dockerfile:1
FROM php:8.3-apache

# Systempakete fuer die benoetigten PHP-Erweiterungen (pdo_mysql, ldap)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libldap-common \
        libldap2-dev \
        libsasl2-dev \
        default-mysql-client \
    ; \
    docker-php-ext-configure ldap --with-ldap-sasl; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql ldap opcache; \
    apt-get purge -y --auto-remove libldap2-dev libsasl2-dev; \
    rm -rf /var/lib/apt/lists/*

# Apache-Module und DocumentRoot
RUN set -eux; \
    a2enmod rewrite headers expires; \
    sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf; \
    printf '%s\n' \
        '<Directory /var/www/html/public>' \
        '    Options -Indexes +FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        'ServerTokens Prod' \
        'ServerSignature Off' \
        'TraceEnable Off' \
        > /etc/apache2/conf-available/intranet.conf; \
    a2enconf intranet

COPY docker/php/php.ini /usr/local/etc/php/conf.d/intranet.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

RUN set -eux; \
    mkdir -p storage/logs storage/uploads; \
    chown -R www-data:www-data storage; \
    chmod -R 750 storage

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
