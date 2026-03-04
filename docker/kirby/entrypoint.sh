#!/bin/sh
set -eu

if [ ! -f /var/www/html/index.php ]; then
  rm -rf /var/www/html/*
  composer create-project getkirby/plainkit /var/www/html --no-interaction --prefer-dist

  mkdir -p /var/www/html/content/about /var/www/html/content/contact
  cat > /var/www/html/content/about/about.txt <<'TXT'
Title: About
----
Text: Benchmark about page.
TXT
  cat > /var/www/html/content/contact/contact.txt <<'TXT'
Title: Contact
----
Text: Benchmark contact page.
TXT

  chown -R www-data:www-data /var/www/html
fi

exec "$@"
