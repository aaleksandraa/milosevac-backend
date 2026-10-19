<?php

namespace App\Support;

use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Support\Atlas\PopulationSelector;
use App\Support\Atlas\SettlementIndexability;
use App\Support\PowerOutage\PowerOutageFeaturedImage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Seo
{
    public static function site(): array
    {
        return cache()->remember('settings.site', 3600, function () {
            return Setting::query()->where('key', 'site')->first()?->value ?? [
                'name' => 'Miloševac',
                'description' => 'Lokalne vijesti, magazin i servisne informacije.',
            ];
        });
    }

    public static function page(string $title, ?string $description = null, ?string $canonical = null, ?string $image = null, array $schema = [], string $type = 'website', string $robots = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'): array
    {
        $site = self::site();
        $canonical = self::absoluteUrl($canonical ?: URL::current());
        $title = Str::of($title)->contains($site['name']) ? (string) $title : "{$title} | {$site['name']}";
        $description = Str::limit(trim(html_entity_decode(strip_tags((string) ($description ?: $site['description'])))), 160, '');
        $image = self::absoluteUrl($image ?: self::defaultShareImage());
        if (self::isBlockedShareImage($image)) {
            $image = self::defaultShareImage();
        }
        $imageType = match (strtolower(pathinfo(parse_url($image, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/webp',
        };
        $schemas = $schema ? [$schema] : [];

        return compact('title', 'description', 'canonical', 'image', 'schemas', 'robots') + [
            'type' => $type,
            'site_name' => $site['name'],
            'locale' => 'bs_BA',
            'image_alt' => $title,
            'image_type' => $imageType,
        ];
    }

    public static function post(Post $post): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $post->title,
            'description' => $post->meta_description ?: $post->excerpt,
            'datePublished' => optional($post->published_at)->toIso8601String(),
            'dateModified' => $post->updated_at->toIso8601String(),
            'author' => ['@type' => 'Person', 'name' => $post->author->name],
            'publisher' => [
                '@type' => 'Organization',
                'name' => self::site()['name'],
            ],
            'mainEntityOfPage' => self::frontendUrl('clanak/'.$post->slug),
            'articleSection' => $post->category->name,
            'keywords' => $post->tags->pluck('name')->implode(', '),
            'wordCount' => str_word_count(strip_tags($post->content)),
        ];

        $seo = self::page(
            $post->meta_title ?: $post->title,
            $post->meta_description ?: $post->excerpt,
            self::postShareUrl($post),
            self::postShareImage($post),
            $schema,
            'article'
        );

        $seo['image_alt'] = $post->featured_image_alt ?: $post->title;

        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => self::frontendUrl('/')],
            ['name' => $post->category->name, 'url' => self::frontendUrl('kategorija/'.$post->category->slug)],
            ['name' => $post->title, 'url' => self::frontendUrl('clanak/'.$post->slug)],
        ]);
        $seo['published_at'] = optional($post->published_at)->toIso8601String();
        $seo['modified_at'] = $post->updated_at->toIso8601String();

        return $seo;
    }

    public static function footballMatch(FootballMatch $match): array
    {
        $seo = self::page(
            $match->meta_title ?: $match->title,
            $match->meta_description ?: $match->excerpt,
            self::frontendUrl('fk-posavina/utakmica/'.$match->slug),
            self::matchShareImage($match),
            [
                '@context' => 'https://schema.org',
                '@type' => 'NewsArticle',
                'headline' => $match->title,
                'description' => $match->meta_description ?: $match->excerpt,
                'datePublished' => optional($match->published_at)->toIso8601String(),
                'dateModified' => $match->updated_at->toIso8601String(),
                'author' => ['@type' => 'Person', 'name' => $match->author?->name ?: self::site()['name']],
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => self::site()['name'],
                ],
                'mainEntityOfPage' => self::frontendUrl('fk-posavina/utakmica/'.$match->slug),
            ],
            'article'
        );
        $seo['image_alt'] = $match->title;
        $seo['published_at'] = optional($match->published_at)->toIso8601String();
        $seo['modified_at'] = $match->updated_at->toIso8601String();

        return $seo;
    }

    public static function absoluteUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return url('/'.ltrim($url, '/'));
    }

    public static function storageImage(?string $path): ?string
    {
        if (! $path || Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $socialPath = preg_replace('/\.[^.]+$/', '-social.jpg', $path);
        $selected = $socialPath && Storage::disk('public')->exists($socialPath) ? $socialPath : $path;

        return asset('storage/'.$selected);
    }

    public static function frontendUrl(string $path = ''): string
    {
        $base = rtrim((string) config('services.frontend.url'), '/');
        $path = ltrim($path, '/');

        return $path === '' ? $base.'/' : $base.'/'.$path;
    }

    private static function postShareImage(Post $post): ?string
    {
        if ($post->service_type === 'power_outage') {
            $bundle = app(PowerOutageFeaturedImage::class)->bundle($post->featured_image, $post->featured_image_responsive);

            return self::storageImage($bundle['og_image'] ?: $bundle['path']);
        }

        return self::storageImage($post->og_image ?: $post->featured_image);
    }

    private static function matchShareImage(FootballMatch $match): ?string
    {
        $path = $match->cover_image ?: $match->media()->value('path');
        if (! $path || self::isBlockedShareImage($path)) {
            return null;
        }

        $social = app(ImagePipeline::class)->socialImage($path);

        return self::storageImage($social ?: $path);
    }

    private static function defaultShareImage(): string
    {
        return self::frontendUrl('og.jpg');
    }

    private static function isBlockedShareImage(?string $path): bool
    {
        $name = strtolower(basename((string) (parse_url((string) $path, PHP_URL_PATH) ?: $path)));

        return in_array($name, ['logo.webp', 'logo.jpg', 'logo.png', 'logo.jpeg'], true);
    }

    private static function postShareUrl(Post $post): string
    {
        $frontend = self::frontendUrl('clanak/'.$post->slug);
        $canonical = trim((string) $post->canonical_url);

        if ($canonical !== '' && str_contains($canonical, 'milosevac.com')) {
            return $canonical;
        }

        return $frontend;
    }

    public static function category(Category $category): array
    {
        return self::archive(
            $category->meta_title ?: $category->name,
            $category->meta_description ?: $category->description,
            self::frontendUrl('kategorija/'.$category->slug),
        );
    }

    public static function tag(Tag $tag): array
    {
        return self::archive(
            $tag->meta_title ?: '#'.$tag->name,
            $tag->meta_description ?: $tag->description,
            self::frontendUrl('kategorija/vijesti?tag='.$tag->slug),
        );
    }

    public static function author(User $author): array
    {
        return self::archive($author->name, $author->bio, self::frontendUrl('autor/'.$author->slug));
    }

    public static function archive(string $title, ?string $description, string $canonical): array
    {
        $seo = self::page($title, $description, $canonical, null, [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $title,
            'description' => $description ?: self::site()['description'],
            'url' => $canonical,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => self::site()['name'],
                'url' => self::frontendUrl('/'),
            ],
        ]);

        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => self::frontendUrl('/')],
            ['name' => $title, 'url' => $canonical],
        ]);

        return $seo;
    }

    public static function paginated(array $seo, LengthAwarePaginator $paginator): array
    {
        if ($paginator->currentPage() > 1) {
            $seo['title'] = 'Stranica '.$paginator->currentPage().' - '.$seo['title'];
            $seo['canonical'] = $paginator->url($paginator->currentPage());
        }

        if ($paginator->previousPageUrl()) {
            $seo['prev'] = $paginator->previousPageUrl();
        }

        if ($paginator->nextPageUrl()) {
            $seo['next'] = $paginator->nextPageUrl();
        }

        return $seo;
    }

    public static function noindex(array $seo): array
    {
        $seo['robots'] = 'noindex, follow';

        return $seo;
    }

    public static function breadcrumb(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)->values()->map(fn (array $item, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ])->all(),
        ];
    }

    public static function atlasIndex(): array
    {
        $canonical = self::frontendUrl('mjesta');
        $title = 'Atlas naseljenih mjesta';
        $description = 'Pilot podaci Popisa 2013. za naseljena mjesta opština Modriča i Šamac. Atlas Bosne i Hercegovine je u fazi proširenja.';

        $seo = self::page($title, $description, $canonical, null, [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'description' => $description,
            'url' => $canonical,
        ]);
        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => self::frontendUrl('/')],
            ['name' => 'Mjesta', 'url' => $canonical],
        ]);

        return $seo;
    }

    public static function atlasMunicipality(GeoMunicipality $municipality, ?int $settlementCount = null, ?int $population2013 = null): array
    {
        $canonical = self::frontendUrl('mjesta/'.$municipality->slug);
        $admin = $municipality->admin_type->publicLabel();
        $genitive = match ($municipality->admin_type->value) {
            'municipality' => 'opštine',
            'city' => 'grada',
            'district' => 'distrikta',
            default => $admin,
        };
        $title = 'Naselja '.$genitive.' '.$municipality->name;
        $description = $admin.' '.$municipality->name;
        if ($municipality->relationLoaded('entity') && $municipality->entity) {
            $description .= ' u '.$municipality->entity->name;
        }
        $description .= '.';
        if ($settlementCount !== null) {
            $description .= ' '.$settlementCount.' '.($settlementCount === 1 ? 'naselje' : 'naselja');
        }
        if ($population2013 !== null) {
            $description .= ' i '.self::bsNumber($population2013).' stanovnika prema Popisu 2013.';
        } else {
            $description .= ' Podaci o stanovništvu prema Popisu 2013. gdje su uvezeni.';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'AdministrativeArea',
            'name' => $municipality->name,
            'url' => $canonical,
        ];
        if ($municipality->relationLoaded('entity') && $municipality->entity) {
            $schema['containedInPlace'] = [
                '@type' => 'AdministrativeArea',
                'name' => $municipality->entity->name,
            ];
        }

        $seo = self::page($title, $description, $canonical, null, $schema);
        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => self::frontendUrl('/')],
            ['name' => 'Mjesta', 'url' => self::frontendUrl('mjesta')],
            ['name' => $municipality->name, 'url' => $canonical],
        ]);

        return $seo;
    }

    public static function atlasSettlement(GeoSettlement $settlement): array
    {
        $municipality = $settlement->municipality;
        $canonical = self::frontendUrl('mjesta/'.$municipality->slug.'/'.$settlement->slug);
        $title = $settlement->name.' – stanovništvo i podaci';
        $description = $settlement->name.', naselje u '.$municipality->admin_type->publicLabel().' '.$municipality->name.'.';

        $census = $settlement->relationLoaded('populationRecords')
            ? (new PopulationSelector)->preferredForYear($settlement->populationRecords, 2013)
            : null;
        $historyYears = $settlement->relationLoaded('populationRecords')
            ? (new PopulationSelector)->history($settlement->populationRecords)
                ->pluck('census_year')
                ->map(fn ($year) => (int) $year)
                ->unique()
                ->sort()
                ->values()
            : collect();
        if ($census !== null) {
            $description .= ' '.self::bsNumber((int) $census->population).' stanovnika prema Popisu 2013.';
        }
        $historicalYears = $historyYears->reject(fn (int $year) => $year === 2013);
        if ($historicalYears->isNotEmpty()) {
            $labels = $historicalYears->map(fn (int $year) => (string) $year)->implode(', ');
            $description .= ' Dostupni su i podaci popisa '.$labels.'.';
        }

        $climate = $settlement->relationLoaded('climateProfiles')
            ? $settlement->climateProfiles->first(fn ($row) => $row->isPubliclyUsable())
            : null;
        if ($climate && $climate->temperature_mean_annual_c !== null) {
            $description .= ' Klima prema ERA5-Land reanalizi 1991–2020: '
                .str_replace('.', ',', number_format((float) $climate->temperature_mean_annual_c, 1)).' °C.';
        }

        $clc = $settlement->relationLoaded('surfaceProfiles')
            ? $settlement->surfaceProfiles->first(fn ($row) => $row->product_code === 'clc2018' && $row->isPubliclyUsable())
            : null;
        $agricultural = $clc?->summaryValue('landcover_agricultural_pct');
        if ($agricultural !== null) {
            $description .= ' Prema CORINE Land Cover 2018, u okviru trenutno dostupne atlas granice, poljoprivredne površine čine '
                .str_replace('.', ',', number_format($agricultural, 1)).'% analizirane površine.';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Place',
            'name' => $settlement->name,
            'url' => $canonical,
            'containedInPlace' => [
                '@type' => 'AdministrativeArea',
                'name' => $municipality->name,
                'url' => self::frontendUrl('mjesta/'.$municipality->slug),
            ],
        ];

        $seo = self::page($title, $description, $canonical, null, $schema);
        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => self::frontendUrl('/')],
            ['name' => 'Mjesta', 'url' => self::frontendUrl('mjesta')],
            ['name' => $municipality->name, 'url' => self::frontendUrl('mjesta/'.$municipality->slug)],
            ['name' => $settlement->name, 'url' => $canonical],
        ]);

        if (! SettlementIndexability::isReady($settlement)) {
            $seo = self::noindex($seo);
        }

        return $seo;
    }

    public static function atlasRankings(?string $metricLabel = null, array $rows = [], ?string $scopeNote = null): array
    {
        $canonical = self::frontendUrl('rang-liste');
        $title = 'Rang-liste naselja';
        $description = 'Rang-liste trenutno obrađenih naseljenih mjesta u atlasu. Rangovi se računaju samo za naselja s važećim podatkom za izabrani metrik. Ovo nisu rang-liste Bosne i Hercegovine.';
        if ($metricLabel) {
            $title = $metricLabel;
            $description = $metricLabel.'. '.$description;
        }

        $seo = self::page($title, $description, $canonical, null, [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $title,
            'description' => $description,
            'url' => $canonical,
        ]);
        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => self::frontendUrl('/')],
            ['name' => 'Mjesta', 'url' => self::frontendUrl('mjesta')],
            ['name' => 'Rang-liste', 'url' => $canonical],
        ]);
        $seo['ranking_caption'] = $title.($scopeNote ? ' — '.$scopeNote : '');
        $seo['ranking_rows'] = $rows;

        return $seo;
    }

    public static function atlasCompare(string $path, string $nameA, string $municipalityA, string $nameB, string $municipalityB, array $rows = []): array
    {
        $canonical = self::frontendUrl(ltrim($path, '/'));
        $title = $nameA.' i '.$nameB.' — poređenje naselja';
        $description = 'Poređenje naselja '.$nameA.' ('.$municipalityA.') i '.$nameB.' ('.$municipalityB.') prema dostupnim atlas podacima. Nije ocjena kvaliteta života.';

        $seo = self::noindex(self::page($title, $description, $canonical, null, [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $title,
            'description' => $description,
        ]));
        $seo['compare_caption'] = $nameA.' ('.$municipalityA.') i '.$nameB.' ('.$municipalityB.')';
        $seo['compare_rows'] = $rows;

        return $seo;
    }

    public static function bsNumber(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
