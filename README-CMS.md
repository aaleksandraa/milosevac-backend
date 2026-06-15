# Miloševac Today Laravel CMS

## Instalacija

```bash
cd backend
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan storage:link
php artisan migrate --seed
npm run build
php artisan serve
```

## WordPress članci i lokalne slike

WordPress export se uvozi posebnim, ponovljivim seederom:

```bash
php artisan migrate
php artisan db:seed --class=WordpressContentSeeder
php artisan storage:link
```

Seeder čita `database/imports/miloevac.WordPress.2026-05-10.xml`, uvozi članke, kategorije i tagove te preuzima dostupne slike u `storage/app/public/wordpress`. Svaka izvorna slika dobija lokalne WebP varijante, a trajno nedostupni WordPress URL-ovi se evidentiraju kako se pri narednom pokretanju ne bi ponovo čekali.

Po defaultu projekat koristi SQLite. Za MySQL ili PostgreSQL promijeniti `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` i `DB_PASSWORD` u `.env`.

## Demo nalozi

- Admin: `admin@milosevac.test`
- Password: `password`

## Paneli

- Javni portal: `/`
- Admin panel: `/admin`
- Autorski panel: `/author`
- FK Posavina stranica: `/fk-posavina`
- Sitemap: `/sitemap.xml`
- Robots: `/robots.txt`

## FK Posavina utakmice i galerije

- Admin: `/admin/matches` upravlja utakmicama, rezultatom, datumom, kraćim izvještajem, naslovnom slikom i galerijom.
- Autor: `/author/matches/create` dodaje utakmicu, draft/pending review status, tekst i galeriju kao poseban template, odvojeno od blog članaka.
- Javni template utakmice je na `/fk-posavina/utakmica/{slug}` i prikazuje rezultat, tekst, autora, lokaciju i galeriju.
- Watermark se podešava u admin listi utakmica: upload PNG logotipa i opacity 0-100%.
- Svaka nova naslovna ili galerijska slika utakmice prolazi WebP/responsive pipeline i, ako je podešen watermark, dobija watermark pri obradi.

## Oglasi

- Admin: `/admin/ads` upravlja Google oglasima i internim oglasnim pozicijama.
- Dostupne pozicije: top banner ispod navigacije, naslovna poslije izdvojenih vijesti, sidebar 300x250, unutar članka/utakmice i banner iznad footera.
- Svaka pozicija može biti isključena ili uključena i može biti `Google slot`, `slikovni banner` ili `tekstualni/native` oglas.
- Za Google oglase unosi se `ca-pub-*` client ID i pojedinačni slot ID po poziciji. Skripta se učitava tek nakon korisničkog pristanka na analitičke/marketinške kolačiće.

## SEO i performanse

Portal koristi server-side Blade rendering, dinamički title/meta/canonical/OG/Twitter tagove, Schema.org `NewsArticle`, `Organization` i `WebSite` markup, indeksabilne arhive kategorija/tagova/autora i SEO-friendly paginaciju.

Google Search Console i analitika:

- `GOOGLE_SITE_VERIFICATION` dodaje GSC verification meta tag u `<head>`.
- `GOOGLE_ANALYTICS_ID` priprema Google Analytics, ali se skripta učitava tek nakon korisničkog pristanka na analitičke kolačiće.
- Footer sadrži linkove za politiku privatnosti, politiku kolačića, uslove korištenja i dugme za ponovno otvaranje cookie postavki.

SEO endpointi:

- `/sitemap.xml` - sitemap index
- `/sitemap-pages.xml` - statičke/indexabilne stranice
- `/sitemap-posts.xml` - članci + image sitemap metadata
- `/sitemap-matches.xml` - FK Posavina utakmice i galerije
- `/sitemap-news.xml` - Google News kompatibilan sitemap za članke objavljene u zadnja 2 dana
- `/sitemap-taxonomies.xml` - kategorije, tagovi i autori
- `/robots.txt` - indeksiranje javnog dijela, blokiranje admin/author/login/search ruta
- `/feed.xml` - RSS feed najnovijih članaka

U Google Search Console prijaviti samo `/sitemap.xml`; iz njega se automatski čitaju ostali sitemapovi. Search stranica ima `noindex, follow`, a arhive i članci imaju canonical URL, pagination `prev/next` i strukturirane podatke.

## Editor, sigurnost sadržaja i slike

- WYSIWYG editor: CKEditor 5 Classic build, lazy-loaded samo na admin/autorskim formama.
- Sanitizacija HTML-a: `ezyang/htmlpurifier`, server-side prije snimanja svakog članka.
- Upload slika: validacija MIME tipova i maksimalne veličine, zatim Intervention Image pipeline.
- Image pipeline generiše WebP varijante 480w, 768w, 1200w i 1600w, čuva originalni source fajl i puni `srcset` na javnom portalu.
- Za produkciju preporuka je uključiti S3-compatible disk/CDN i periodičan backup `storage/app/public`.

Za produkciju uključiti `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, queue worker, cron za scheduler, `php artisan optimize`, CDN/S3 za slike, dnevni backup baze i storage fajlova, te Redis cache ako je dostupan.
