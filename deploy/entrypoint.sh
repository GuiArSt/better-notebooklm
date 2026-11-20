#!/bin/sh
set -e

cd /var/www/html

# `serve` runs php-fpm and Caddy; any other command (queue:work, schedule:work...) runs as given.
if [ "$1" = "serve" ]; then
  # The workers run as www-data like php-fpm; this repairs a volume an older image filled as root (the web process could not read
  # the audio a root worker had written into a private directory).
  chown -R www-data:www-data storage
  if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
  fi
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php-fpm -D
  exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
fi

php artisan config:cache
exec "$@"
