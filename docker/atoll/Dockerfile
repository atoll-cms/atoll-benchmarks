FROM composer:2 AS build

WORKDIR /work

COPY atoll-starter/ ./
COPY atoll-core/ ./core/

RUN if [ -f config.yaml ]; then sed -i 's|path: ../atoll-core|path: core|' config.yaml; fi \
    && composer install --no-interaction --prefer-dist --no-dev \
    && php -r 'require "vendor/autoload.php"; $cfg=Symfony\Component\Yaml\Yaml::parseFile("config.yaml"); if (!is_array($cfg)) { throw new RuntimeException("Invalid config"); } $cfg["environment"]="prod"; $cfg["cache"]["enabled"]=true; $cfg["security"]["rate_limit"]["enabled"]=false; $cfg["security"]["rate_limit"]["requests"]=100000; $cfg["security"]["rate_limit"]["window_seconds"]=60; file_put_contents("config.yaml", Symfony\Component\Yaml\Yaml::dump($cfg, 8, 2));'

FROM php:8.2-apache

RUN a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY --from=build /work /var/www/html

RUN mkdir -p /var/www/html/cache /var/www/html/backups /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html
