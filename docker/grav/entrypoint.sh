#!/bin/sh
set -eu

if [ ! -f /var/www/html/index.php ]; then
  rm -rf /var/www/html/*
  composer create-project getgrav/grav /var/www/html --no-interaction --prefer-dist

  mkdir -p /var/www/html/user/pages/02.about /var/www/html/user/pages/03.contact
  cat > /var/www/html/user/pages/02.about/default.md <<'MD'
---
title: About
---

Benchmark about page.
MD
  cat > /var/www/html/user/pages/03.contact/default.md <<'MD'
---
title: Contact
---

Benchmark contact page.
MD

  chown -R www-data:www-data /var/www/html
fi

exec "$@"
