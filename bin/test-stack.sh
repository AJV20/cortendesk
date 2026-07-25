#!/usr/bin/env bash
# Drive the local CortenDesk Docker TEST stack (test/docker-compose.yml).
# Test changes here — in the real Docker image, same as end users — instead of
# on a live production server.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f test/docker-compose.yml"

cmd="${1:-up}"
case "$cmd" in
  up)
    $COMPOSE up -d --build
    echo
    echo "CortenDesk test console: http://localhost:8088   (admin / changeme)"
    echo "hbbs :21116 / hbbr :21117 running in the stack."
    echo "Keycloak (OIDC):         http://localhost:8089   (admin / admin)"
    echo "Watch boot:  bin/test-stack.sh logs      SSO settings: bin/test-stack.sh sso"
    ;;
  sso)
    # Print the Keycloak/OIDC values the console needs, and prove both the
    # browser-facing and back-channel discovery documents resolve.
    cat <<'EOF'
Keycloak test IdP
  Browser-facing base URL : http://localhost:8089        (host -> container 8080)
  Back-channel base URL   : http://keycloak:8080         (from the cortendesk container)
  Issuer (BOTH sides)     : http://localhost:8089/realms/cortendesk
  Realm                   : cortendesk
  Client ID               : cortendesk-console  (confidential, standard flow only)
  Client secret           : test-client-secret-123
  Redirect URI            : http://localhost:8088/*
  Users                   : ssouser      / ssopass123   (emailVerified: true)
                            ssounverified/ ssopass123   (emailVerified: FALSE - must be refused)
  Admin console           : http://localhost:8089/admin  (admin / admin)

The realm is re-imported from test/keycloak/realm-export.json on every container
create; Keycloak's dev H2 DB is ephemeral, so edit the JSON, not the admin UI.
EOF
    echo
    echo -n "host      -> issuer: "
    curl -s http://localhost:8089/realms/cortendesk/.well-known/openid-configuration \
      | sed -n 's/.*"issuer":"\([^"]*\)".*/\1/p' | tr -d '\n' || true; echo
    echo -n "container -> issuer: "
    $COMPOSE exec -T cortendesk curl -s http://keycloak:8080/realms/cortendesk/.well-known/openid-configuration \
      | sed -n 's/.*"issuer":"\([^"]*\)".*/\1/p' | tr -d '\n' || true; echo
    ;;
  rebuild)
    # Rebuild just the CortenDesk image after code changes and recreate it.
    $COMPOSE build cortendesk
    $COMPOSE up -d cortendesk
    echo "CortenDesk rebuilt + recreated. http://localhost:8088"
    ;;
  logs)
    $COMPOSE logs -f --tail=80 "${2:-cortendesk}"
    ;;
  seed-prod)
    # Load the most recent prod DB snapshot (bin/pull-prod-db.sh style) into the
    # test MySQL so the stack has realistic data. Pass a .sql/.sql.gz path, or it
    # uses the newest storage/app/backups/*.sql.gz.
    dump="${2:-$(ls -t storage/app/backups/*.sql.gz 2>/dev/null | head -1 || true)}"
    [ -n "$dump" ] && [ -f "$dump" ] || { echo "No dump found. Pass a path or run a backup first."; exit 1; }
    echo "Loading $dump into the test database…"
    if [[ "$dump" == *.gz ]]; then gz="gunzip -c"; else gz="cat"; fi
    $gz "$dump" | $COMPOSE exec -T db mysql -ucortendesk -pcortendesk-test cortendesk
    $COMPOSE exec -T db mysql -ucortendesk -pcortendesk-test cortendesk -e "TRUNCATE sessions;" 2>/dev/null || true
    $COMPOSE exec -T cortendesk php artisan migrate --force
    echo "Loaded. (console logins are now the snapshot's accounts)"
    ;;
  shell)
    $COMPOSE exec cortendesk sh
    ;;
  down)
    shift || true
    $COMPOSE down "$@"   # add -v to wipe volumes
    ;;
  ps)
    $COMPOSE ps
    ;;
  *)
    echo "usage: bin/test-stack.sh {up|rebuild|logs [svc]|seed-prod [dump]|sso|shell|down [-v]|ps}"
    exit 1
    ;;
esac
