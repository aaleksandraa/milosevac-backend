<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
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
        $image = self::absoluteUrl($image ?: asset('logo.webp'));
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
            'mainEntityOfPage' => route('posts.show', $post->slug),
            'articleSection' => $post->category->name,
            'keywords' => $post->tags->pluck('name')->implode(', '),
            'wordCount' => str_word_count(strip_tags($post->content)),
        ];

        $seo = self::page(
            $post->meta_title ?: $post->title,
            $post->meta_description ?: $post->excerpt,
            $post->canonical_url ?: route('posts.show', $post->slug),
            self::storageImage($post->og_image ?: $post->featured_image),
            $schema,
            'article'
        );

        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => route('home')],
            ['name' => $post->category->name, 'url' => route('categories.show', $post->category->slug)],
            ['name' => $post->title, 'url' => route('posts.show', $post->slug)],
        ]);
        $seo['published_at'] = optional($post->published_at)->toIso8601String();
        $seo['modified_at'] = $post->updated_at->toIso8601String();

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

    public static function category(Category $category): array
    {
        return self::archive($category->meta_title ?: $category->name, $category->meta_description ?: $category->description, route('categories.show', $category->slug));
    }

    public static function tag(Tag $tag): array
    {
        return self::archive($tag->meta_title ?: '#'.$tag->name, $tag->meta_description ?: $tag->description, route('tags.show', $tag->slug));
    }

    public static function author(User $author): array
    {
        return self::archive($author->name, $author->bio, route('authors.show', $author->slug));
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
                'url' => route('home'),
            ],
        ]);

        $seo['schemas'][] = self::breadcrumb([
            ['name' => 'Početna', 'url' => route('home')],
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
}
