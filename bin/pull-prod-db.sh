#!/usr/bin/env bash
# Replace the LOCAL dev database with a fresh production snapshot.
# Reads the deploy target from deploy.env (git-ignored) and DB credentials
# from the local .env / the remote .env — nothing sensitive lives here.
#
# After running: your dev console logins are the PRODUCTION accounts.
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f deploy.env ] && . deploy.env
HOST="${CORTENDESK_DEPLOY_HOST:?set CORTENDESK_DEPLOY_HOST (user@host)}"
DEST="${CORTENDESK_DEPLOY_DEST:-/opt/cortendesk}"

LOCAL_DB=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)
LOCAL_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)
LOCAL_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2)

echo "== dumping production database..."
ssh "$HOST" "sudo sh -c 'set -e
  PW=\$(grep -E \"^DB_PASSWORD=\" $DEST/.env | cut -d= -f2)
  DB=\$(grep -E \"^DB_DATABASE=\" $DEST/.env | cut -d= -f2)
  U=\$(grep -E \"^DB_USERNAME=\" $DEST/.env | cut -d= -f2)
  mysqldump --single-transaction --quick --no-tablespaces -u\"\$U\" -p\"\$PW\" \"\$DB\"' | gzip" > /tmp/cortendesk-prod.sql.gz

echo "== importing into local '$LOCAL_DB' (replaces ALL local data)..."
gunzip -c /tmp/cortendesk-prod.sql.gz | mysql -u"$LOCAL_USER" -p"$LOCAL_PASS" "$LOCAL_DB"
rm /tmp/cortendesk-prod.sql.gz

# Local hygiene: don't carry over live console sessions.
mysql -u"$LOCAL_USER" -p"$LOCAL_PASS" "$LOCAL_DB" -e "TRUNCATE sessions;" 2>/dev/null || true

php artisan migrate --force        # apply any local migrations newer than prod
php artisan config:clear -q

echo "== done. Local dev now mirrors production."
echo "   NOTE: console logins are now the PRODUCTION accounts (admin/changeme is gone)."
