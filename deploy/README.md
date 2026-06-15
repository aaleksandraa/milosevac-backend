# Miloševac production deployment

## Odabrana struktura

Laravel mora biti instaliran u root folderu domena, zato koristimo:

```text
/var/www/milosevac.com            → milosevac-backend, Laravel root
/var/www/milosevac.com/frontend   → milosevac-frontend, React/Vite
/var/www/milosevac.com/frontend/dist → React build koji Nginx javno servira
```

Ovo je bolje od `backend.milosevac.com` jer `/api`, `/storage`, admin sesije,
social previewi i javni članci ostaju na istom domenu bez CORS konfiguracije.
Frontend folder je ignorisan u backend Git repozitoriju.

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
  /var/www/milosevac.com \
  /var/backups/milosevac
```

## 2. Kloniranje oba repozitorija

Backend se klonira direktno u root domena, a frontend u njegov `frontend/`
folder:

```bash
sudo -u milosevac git clone git@github.com:aaleksandraa/milosevac-backend.git /var/www/milosevac.com
sudo -u milosevac git clone git@github.com:aaleksandraa/milosevac-frontend.git /var/www/milosevac.com/frontend
```

Kreirati produkcijske env i backup fajlove:

```bash
sudo -u milosevac cp /var/www/milosevac.com/deploy/env/backend.env.example /var/www/milosevac.com/.env
sudo -u milosevac mkdir -p /var/www/milosevac.com/shared/import
sudo -u milosevac cp /var/www/milosevac.com/deploy/env/backup.env.example /var/www/milosevac.com/shared/backup.env
sudo -u milosevac cp /var/www/milosevac.com/deploy/env/mysql-backup.cnf.example /var/www/milosevac.com/shared/mysql-backup.cnf
sudo chmod 600 /var/www/milosevac.com/.env /var/www/milosevac.com/shared/mysql-backup.cnf
```

Instalirati deploy skripte:

```bash
sudo install -o milosevac -g www-data -m 0755 /var/www/milosevac.com/deploy/server/deploy-backend.sh /var/www/milosevac.com/deploy-backend.sh
sudo install -o milosevac -g www-data -m 0755 /var/www/milosevac.com/deploy/server/backup.sh /var/www/milosevac.com/backup.sh
sudo install -o milosevac -g www-data -m 0755 /var/www/milosevac.com/frontend/deploy/server/deploy-frontend.sh /var/www/milosevac.com/deploy-frontend.sh
```

Popuniti `.env`, `shared/backup.env` i `shared/mysql-backup.cnf` stvarnim
produkcijskim vrijednostima. Produkcija ne pokreće `db:seed`.

## 3. Nginx i servisi

Nginx javno servira `/var/www/milosevac.com/frontend/dist`, a Laravel rute
šalje na `/var/www/milosevac.com/public/index.php`.

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

## 4. Prvi deployment i prenos podataka

```bash
sudo -u milosevac /var/www/milosevac.com/deploy-backend.sh main
sudo -u milosevac /var/www/milosevac.com/deploy-frontend.sh main
rsync -avP database/database.sqlite milosevac@SERVER:/var/www/milosevac.com/shared/import/database.sqlite
rsync -avP storage/app/public/ milosevac@SERVER:/var/www/milosevac.com/storage/app/public/
```

Na serveru:

```bash
cd /var/www/milosevac.com
php artisan content:transfer-sqlite /var/www/milosevac.com/shared/import/database.sqlite --dry-run
php artisan content:transfer-sqlite /var/www/milosevac.com/shared/import/database.sqlite --force
php artisan users:set-password admin@milosevac.test
```

## 5. GitHub Actions i provjera

U oba repozitorija dodati variable `DEPLOY_ENABLED=true` i secrets
`DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`.

```bash
/var/www/milosevac.com/backup.sh daily
curl -fsS https://milosevac.com/up
curl -fsS 'https://milosevac.com/api/content?limit=1'
curl -fsS https://milosevac.com/sitemap.xml
curl -I https://milosevac.com/admin
```
