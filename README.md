# Miloševac backend

Laravel CMS, API, admin panel, storage, sitemap, RSS i social preview renderer
za `https://milosevac.com`.

Frontend se održava u zasebnom repozitoriju `aaleksandraa/milosevac-frontend`.

## Lokalni razvoj

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
composer run dev
```

Testovi i admin build:

```bash
php artisan test
npm run build
```

Komanda `portal:export-content [target]` izvozi JSON snapshot. Kada target nije
naveden, zapisuje ga u `storage/app/private/portal-content.snapshot.json`, bez
zavisnosti od frontend repozitorija.

## Produkcija

Repozitorij se klonira u `/var/www/milosevac.com/backend`. Produkcijski `.env`
i storage ostaju u tom direktoriju, ali su isključeni iz Gita. Kompletno
uputstvo je u [`deploy/README.md`](deploy/README.md).
