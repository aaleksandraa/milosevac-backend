#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="${APP_ROOT:-/var/www/milosevac.com}"
BACKEND_DIR="${BACKEND_DIR:-$APP_ROOT}"
REF="${1:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NPM_BIN="${NPM_BIN:-npm}"
LOCK_FILE="$APP_ROOT/deploy-backend.lock"

test -d "$BACKEND_DIR/.git" || { echo "Missing backend repository: $BACKEND_DIR"; exit 1; }
test -f "$BACKEND_DIR/.env" || { echo "Missing $BACKEND_DIR/.env"; exit 1; }

exec 9>"$LOCK_FILE"
flock -n 9 || { echo "Another backend deployment is running."; exit 1; }

if [[ -x "$APP_ROOT/backup.sh" ]]; then
  "$APP_ROOT/backup.sh" predeploy
fi

git -C "$BACKEND_DIR" fetch --depth 1 origin "$REF"
git -C "$BACKEND_DIR" checkout --force --detach FETCH_HEAD

(
  cd "$BACKEND_DIR"
  "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
  "$NPM_BIN" ci
  "$NPM_BIN" run build
  "$PHP_BIN" artisan migrate --force
  "$PHP_BIN" artisan storage:link
  "$PHP_BIN" artisan posts:generate-social-images
  "$PHP_BIN" artisan optimize
)

chmod -R ug+rwX "$BACKEND_DIR/storage"

if command -v supervisorctl >/dev/null 2>&1; then
  sudo -n supervisorctl restart milosevac-worker:* || supervisorctl restart milosevac-worker:* || true
fi

echo "Backend deployment completed: $(git -C "$BACKEND_DIR" rev-parse --short HEAD)"
