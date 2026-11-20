# syntax=docker/dockerfile:1
# Build from the repository root:  docker build -f deploy/Dockerfile .
# Targets: `app` (default: web, workers, scheduler) and `media` (adds ffmpeg for the media worker).

ARG PHP_VERSION=8.4

# ---- Stage 1: PHP dependencies (no dev packages) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY src/composer.json src/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY src/ ./
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---- Stage 2: front-end assets (Node lives here only; wayfinder needs PHP to generate routes) ----
FROM php:${PHP_VERSION}-cli AS assets
COPY --from=node:22-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
WORKDIR /app
COPY --from=vendor /app ./
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && cp -n .env.example .env && php artisan key:generate --force
RUN npm ci && npm run build

# ---- Stage 3: runtime ----
FROM php:${PHP_VERSION}-fpm AS app
COPY --from=caddy:2 /usr/bin/caddy /usr/bin/caddy
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libonig-dev unzip curl \
    && apt-get clean
# One extension per layer, built one at a time (-j1). The same build also runs under x86 emulation on an Apple Silicon Mac,
# where the compiler now and then crashes at random: each layer retries up to four times, and a real error still fails the build.
RUN docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp \
    && for i in 1 2 3 4; do docker-php-ext-install -j1 gd && exit 0; echo "retry $i"; done; exit 1
RUN for i in 1 2 3 4; do docker-php-ext-install -j1 pdo_pgsql pgsql && exit 0; echo "retry $i"; done; exit 1
RUN for i in 1 2 3 4; do docker-php-ext-install -j1 intl && exit 0; echo "retry $i"; done; exit 1
RUN for i in 1 2 3 4; do docker-php-ext-install -j1 zip pcntl mbstring bcmath && exit 0; echo "retry $i"; done; exit 1
# opcache has a JIT whose sources crash the compiler now and then under emulation. A crash leaves a broken object file behind,
# so every retry starts from a clean build directory.
RUN for i in 1 2 3 4 5 6; do docker-php-ext-install -j1 opcache && exit 0; echo "retry $i"; (cd /usr/src/php/ext/opcache && make clean >/dev/null 2>&1; find . -name '*.lo' -o -name '*.o' | xargs rm -f); done; exit 1
COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY deploy/Caddyfile /etc/caddy/Caddyfile
COPY deploy/entrypoint.sh /usr/local/bin/entrypoint
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENV PHP_FPM_MAX_CHILDREN=8
ENV XDG_DATA_HOME=/tmp/caddy-data XDG_CONFIG_HOME=/tmp/caddy-config
EXPOSE 80
ENTRYPOINT ["entrypoint"]
CMD ["serve"]

# ---- Media worker: the same app plus ffmpeg ----
FROM app AS media
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && apt-get clean
