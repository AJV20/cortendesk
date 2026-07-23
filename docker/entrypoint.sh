#!/bin/sh
# CortenDesk container bootstrap: render nginx config, ensure APP_KEY and
# database exist, migrate, cache, then hand off to supervisord.
set -e
cd /app

# --- APP_KEY: use the env if provided, else generate once into /data --------
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f /data/.app_key ]; then
        php artisan key:generate --show > /data/.app_key
        chown www-data:www-data /data/.app_key
        chmod 600 /data/.app_key
        echo "[cortendesk] generated APP_KEY (persisted in the /data volume)"
    fi
    APP_KEY="$(cat /data/.app_key)"
    export APP_KEY
fi

# --- database ----------------------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    [ -f "$DB_DATABASE" ] || { touch "$DB_DATABASE" && chown www-data:www-data "$DB_DATABASE"; }
fi

# Wait for an external database to accept connections (MySQL etc.).
tries=0
until php artisan migrate --force --no-interaction 2>/tmp/migrate.err; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "[cortendesk] database not reachable after 150s:" >&2
        cat /tmp/migrate.err >&2
        exit 1
    fi
    echo "[cortendesk] waiting for database... ($tries)"
    sleep 5
done

# First boot: create the admin account (no-op once any user exists).
php artisan db:seed --class=Database\\Seeders\\DockerSeeder --force --no-interaction

# --- nginx: point the ws bridge at the RustDesk server ------------------------
# RUSTDESK_WS_HOST > host part of CORTENDESK_ID_SERVER > localhost.
if [ -z "${RUSTDESK_WS_HOST:-}" ]; then
    RUSTDESK_WS_HOST="$(echo "${CORTENDESK_ID_SERVER:-127.0.0.1}" | cut -d: -f1)"
fi
export RUSTDESK_WS_HOST
# Per-request DNS for the ws bridge: use the container's own resolver.
NGINX_RESOLVER="${NGINX_RESOLVER:-$(awk '/^nameserver/{print $2; exit}' /etc/resolv.conf)}"
export NGINX_RESOLVER="${NGINX_RESOLVER:-127.0.0.11}"
envsubst '${RUSTDESK_WS_HOST} ${NGINX_RESOLVER}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

php artisan config:cache --no-interaction -q
php artisan route:cache --no-interaction -q
php artisan view:cache --no-interaction -q

echo "[cortendesk] ready — listening on :8080 (ws bridge -> ${RUSTDESK_WS_HOST}:21118/21119)"
exec supervisord -c /etc/supervisord.conf
