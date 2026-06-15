#!/usr/bin/env bash
set -u

echo "== Host =="
hostnamectl 2>/dev/null || hostname
echo

echo "== Disk and memory =="
df -h /
free -h 2>/dev/null || true
echo

echo "== Runtime versions =="
for command in nginx php php-fpm8.2 mysql mariadb node npm composer git supervisorctl certbot; do
  if command -v "$command" >/dev/null 2>&1; then
    printf '%-16s ' "$command"
    "$command" --version 2>&1 | head -n 1
  fi
done
echo

echo "== Listening ports =="
ss -lntup 2>/dev/null || true
echo

echo "== Enabled Nginx sites =="
ls -la /etc/nginx/sites-enabled 2>/dev/null || true
echo

echo "== PHP-FPM pools =="
find /etc/php -path '*/fpm/pool.d/*.conf' -maxdepth 5 -type f -print 2>/dev/null || true
echo

echo "== Supervisor programs =="
supervisorctl status 2>/dev/null || true
echo

echo "Inventory only: no server state was changed."
