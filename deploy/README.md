# Miloševac backend deployment

Produkcija koristi dva odvojena Git repozitorija i jedan domen:

- `/var/www/milosevac.com/httpdocs` → `milosevac-frontend`, Nginx servira `dist/`
- `/var/www/milosevac.com/backend` → `milosevac-backend`, Laravel aplikacija
- `/var/www/milosevac.com/backend/.env` → produkcijski Laravel env, izvan Gita
- `/var/www/milosevac.com/backend/storage` → slike, logovi i Laravel runtime

Nginx šalje `/api`, admin/autorske rute, storage, sitemap, feed i health check
Laravelu. Social crawler user-agenti takođe dobijaju Laravel Open Graph HTML.

## 1. Priprema servera

Prvo pokrenuti read-only inventar:

```bash
bash deploy/server/inventory.sh | tee ~/milosevac-server-inventory.txt
```

Potrebni su Nginx, PHP-FPM/CLI `>=8.2`, MySQL/MariaDB, Composer 2, Node.js 20,
npm, Git, rsync, Supervisor i Certbot.

```bash
sudo adduser --disabled-password --gecos "" milosevac
sudo usermod -aG www-data milosevac
sudo install -d -o milosevac -g www-data -m 2775 \
  /var/www/milosevac.com/shared/import \
  /var/backups/milosevac
```

## 2. Kloniranje repozitorija i shared fajlovi

```bash
sudo -u milosevac git clone git@github.com:aaleksandraa/milosevac-backend.git /var/www/milosevac.com/backend
sudo -u milosevac cp /var/www/milosevac.com/backend/deploy/env/backend.env.example /var/www/milosevac.com/backend/.env
sudo -u milosevac cp /var/www/milosevac.com/backend/deploy/env/backup.env.example /var/www/milosevac.com/shared/backup.env
sudo -u milosevac cp /var/www/milosevac.com/backend/deploy/env/mysql-backup.cnf.example /var/www/milosevac.com/shared/mysql-backup.cnf
sudo chmod 600 /var/www/milosevac.com/backend/.env /var/www/milosevac.com/shared/mysql-backup.cnf
sudo install -o milosevac -g www-data -m 0755 /var/www/milosevac.com/backend/deploy/server/deploy-backend.sh /var/www/milosevac.com/deploy-backend.sh
sudo install -o milosevac -g www-data -m 0755 /var/www/milosevac.com/backend/deploy/server/backup.sh /var/www/milosevac.com/backup.sh
```

Popuniti `backend.env`, `backup.env` i `mysql-backup.cnf` stvarnim produkcijskim
vrijednostima. Produkcija ne pokreće `db:seed`.

## 3. Servisi

Nakon pregleda postojećih konfiguracija:

```bash
sudo cp deploy/php-fpm/milosevac.conf.example /etc/php/8.2/fpm/pool.d/milosevac.conf
sudo cp deploy/nginx/milosevac.conf.example /etc/nginx/sites-available/milosevac.conf
sudo ln -s /etc/nginx/sites-available/milosevac.conf /etc/nginx/sites-enabled/milosevac.conf
sudo cp deploy/supervisor/milosevac-worker.conf.example /etc/supervisor/conf.d/milosevac-worker.conf
sudo cp deploy/cron/milosevac.example /etc/cron.d/milosevac
sudo php-fpm8.2 -t
sudo nginx -t
sudo supervisorctl reread
sudo supervisorctl update
```

## 4. Prvi deployment i podaci

```bash
sudo -u milosevac /var/www/milosevac.com/deploy-backend.sh main
rsync -avP database/database.sqlite milosevac@SERVER:/var/www/milosevac.com/shared/import/database.sqlite
rsync -avP storage/app/public/ milosevac@SERVER:/var/www/milosevac.com/backend/storage/app/public/
```

Na serveru:

```bash
cd /var/www/milosevac.com/backend
php artisan content:transfer-sqlite /var/www/milosevac.com/shared/import/database.sqlite --dry-run
php artisan content:transfer-sqlite /var/www/milosevac.com/shared/import/database.sqlite --force
php artisan users:set-password admin@milosevac.test
```

## 5. GitHub Actions i backup

U oba repozitorija dodati variable `DEPLOY_ENABLED=true` i secrets
`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`.

Backend push na `main` poziva `/var/www/milosevac.com/deploy-backend.sh`, a
frontend push poziva `/var/www/milosevac.com/deploy-frontend.sh`.

```bash
/var/www/milosevac.com/backup.sh daily
gzip -t /var/backups/milosevac/daily/database-*.sql.gz
gzip -t /var/backups/milosevac/daily/storage-*.tar.gz
```

Smoke test:

```bash
curl -fsS https://milosevac.com/up
curl -fsS 'https://milosevac.com/api/content?limit=1'
curl -fsS https://milosevac.com/sitemap.xml
curl -I https://milosevac.com/admin
```
