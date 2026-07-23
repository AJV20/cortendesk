#!/usr/bin/env bash
# CortenDesk production deploy — rsync code to the prod box and refresh caches.
# Usage: ./deploy.sh
set -euo pipefail

# Set these in your environment or a local (git-ignored) deploy.env file:
#   CORTENDESK_DEPLOY_HOST  e.g. deploy@your-server.example.com
#   CORTENDESK_DEPLOY_DEST  e.g. /opt/cortendesk
[ -f "$(dirname "$0")/deploy.env" ] && . "$(dirname "$0")/deploy.env"
HOST="${CORTENDESK_DEPLOY_HOST:?set CORTENDESK_DEPLOY_HOST (user@host)}"
DEST="${CORTENDESK_DEPLOY_DEST:-/opt/cortendesk}"

# NOTES:
# - Excludes are anchored to the repo root ("/vendor", not "vendor") so they
#   never swallow public/assets/vendor (the theme's JS libraries).
# - No --delete: macOS openrsync crashes (code 12) when the delete pass hits
#   the www-data-owned cache files on the remote. Remove stale files manually
#   on the rare occasion a source file is deleted.
rsync -az \
  --exclude '/.git' \
  --exclude '/vendor' \
  --exclude '/node_modules' \
  --exclude '/webclient/node_modules' \
  --exclude '/.env' \
  --exclude '/deploy.sh' \
  --exclude '/bootstrap/cache/*' \
  --exclude '/storage/app/*' \
  --exclude '/storage/logs/*' \
  --exclude '/storage/framework/*' \
  ./ "$HOST:$DEST/" || [ $? -eq 23 ] # 23 = attrs not set on www-data-owned dirs; transfer itself is fine

ssh "$HOST" "cd $DEST \
  && sudo -n -u www-data env COMPOSER_HOME=/tmp/composer composer install --no-dev --optimize-autoloader --no-interaction --quiet \
  && sudo -n -u www-data php artisan migrate --force \
  && sudo -n -u www-data php artisan optimize:clear -q \
  && sudo -n -u www-data php artisan config:cache -q \
  && sudo -n -u www-data php artisan route:cache -q \
  && sudo -n -u www-data php artisan view:cache -q"

echo "Deployed."
