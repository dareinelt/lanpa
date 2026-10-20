FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libldap2-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-configure ldap --with-libdir=lib/$(uname -m)-linux-gnu/ \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_mysql ldap gd zip opcache \
    && a2enmod rewrite headers substitute \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/php/scheduler.sh /usr/local/bin/app-scheduler
RUN chmod +x /usr/local/bin/app-entrypoint /usr/local/bin/app-scheduler

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p storage/uploads storage/logs storage/tmp \
    && chown -R www-data:www-data storage

EXPOSE 80
ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
