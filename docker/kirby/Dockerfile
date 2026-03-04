FROM php:8.2-apache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
      git \
      unzip \
      libfreetype6-dev \
      libjpeg62-turbo-dev \
      libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY docker/kirby/entrypoint.sh /usr/local/bin/kirby-entrypoint
RUN chmod +x /usr/local/bin/kirby-entrypoint

ENTRYPOINT ["kirby-entrypoint"]
CMD ["apache2-foreground"]
