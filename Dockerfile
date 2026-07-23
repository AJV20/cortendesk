# CortenDesk — self-hosted RustDesk web console (app + native web client).
#
#   docker build -t cortendesk .
#   docker run -p 8080:8080 -v cortendesk-data:/data cortendesk
#
# Zero-config run uses SQLite in the /data volume and creates admin/changeme
# on first boot. See docker-compose.yml for a MySQL setup, and README.md for
# the reverse-proxy (TLS + wss) notes.

# ---- stage 1: composer dependencies -----------------------------------------
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
# The storage/cache skeleton must exist for artisan package:discover (git
# doesn't track empty directories, so don't trust the checkout).
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/testing \
      storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache \
    && composer dump-autoload --optimize --no-dev

# ---- stage 2: runtime (php-fpm + nginx under supervisord) --------------------
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor icu-libs gettext \
    && apk add --no-cache --virtual .build icu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath opcache intl \
    && apk del .build

WORKDIR /app
COPY --from=deps /app /app
COPY docker/php.ini /usr/local/etc/php/conf.d/cortendesk.ini
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /data /run/nginx \
    && chown -R www-data:www-data /data /app/storage /app/bootstrap/cache

# Container-friendly defaults; override any of these at run time.
ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:8080 \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/cortendesk.sqlite \
    SESSION_DRIVER=file \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    CORTENDESK_NATIVE_WEBCLIENT=true

EXPOSE 8080
VOLUME /data

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/login || exit 1

ENTRYPOINT ["/entrypoint.sh"]
