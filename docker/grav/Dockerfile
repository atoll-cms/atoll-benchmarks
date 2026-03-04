FROM php:8.2-apache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev zip \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY docker/grav/entrypoint.sh /usr/local/bin/grav-entrypoint
RUN chmod +x /usr/local/bin/grav-entrypoint

ENTRYPOINT ["grav-entrypoint"]
CMD ["apache2-foreground"]
