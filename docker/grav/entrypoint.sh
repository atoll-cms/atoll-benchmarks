#!/bin/sh
set -eu

if [ ! -f /var/www/html/index.php ]; then
  rm -rf /var/www/html/*
  composer create-project getgrav/grav /var/www/html --no-interaction --prefer-dist
  chown -R www-data:www-data /var/www/html
fi

exec "$@"
