#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="${APP_ROOT:-/var/www/milosevac.com}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/milosevac}"
MYSQL_CNF="${MYSQL_CNF:-$APP_ROOT/shared/mysql-backup.cnf}"
BACKUP_ENV="${BACKUP_ENV:-$APP_ROOT/shared/backup.env}"
MODE="${1:-daily}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOCK_FILE="$APP_ROOT/backup.lock"

test -f "$MYSQL_CNF" || { echo "Missing $MYSQL_CNF"; exit 1; }
test -f "$BACKUP_ENV" || { echo "Missing $BACKUP_ENV"; exit 1; }
# shellcheck disable=SC1090
source "$BACKUP_ENV"
: "${MYSQL_DATABASE:?MYSQL_DATABASE is required in backup.env}"

mkdir -p "$BACKUP_ROOT/daily" "$BACKUP_ROOT/weekly" "$BACKUP_ROOT/predeploy"
exec 9>"$LOCK_FILE"
flock -n 9 || { echo "Another backup is running."; exit 1; }

DESTINATION="$BACKUP_ROOT/$MODE"
mkdir -p "$DESTINATION"

mysqldump --defaults-extra-file="$MYSQL_CNF" \
  --single-transaction --quick --routines --triggers --events \
  "$MYSQL_DATABASE" | gzip -1 > "$DESTINATION/database-$STAMP.sql.gz"

tar -C "$APP_ROOT/backend" -cf - storage/app/public | gzip -1 > "$DESTINATION/storage-$STAMP.tar.gz"

if [[ "$MODE" == "daily" && "$(date -u +%u)" == "7" ]]; then
  ln "$DESTINATION/database-$STAMP.sql.gz" "$BACKUP_ROOT/weekly/database-$STAMP.sql.gz"
  ln "$DESTINATION/storage-$STAMP.tar.gz" "$BACKUP_ROOT/weekly/storage-$STAMP.tar.gz"
fi

find "$BACKUP_ROOT/daily" -type f -mtime +7 -delete
find "$BACKUP_ROOT/weekly" -type f -mtime +28 -delete
find "$BACKUP_ROOT/predeploy" -type f -mtime +7 -delete

echo "Backup completed: $DESTINATION ($STAMP)"
