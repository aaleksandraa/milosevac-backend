<?php

namespace App\Http\Controllers;

use App\Models\FootballMatch;
use App\Models\Post;
use App\Models\Setting;
use App\Support\AdPositions;
use App\Support\ArticleContent;
use App\Support\PowerOutage\OutageQueryService;
use App\Support\PowerOutage\PowerOutageFeaturedImage;
use Illuminate\Http\Request;

/**
 * JSON API consumed by the React frontend. Public pages themselves are rendered
 * by the SPA; Laravel only serves data, sitemaps and social previews.
 */
class PublicPortalController extends Controller
{
    public function apiFkPosavina(Request $request)
    {
        $matches = FootballMatch::published()
            ->with(['author', 'media'])
            ->latest('played_at')
            ->take(24)
            ->get();

        $galleryMatches = $matches
            ->filter(fn (FootballMatch $match) => $match->media->isNotEmpty())
            ->values();

        return response()->json([
            'matches' => $matches->map(fn (FootballMatch $match) => $this->matchApiPayload($match, $request))->values(),
            'galleryMatches' => $galleryMatches->map(fn (FootballMatch $match) => $this->matchApiPayload($match, $request))->values(),
        ]);
    }

    public function apiContent(Request $request)
    {
        $limit = min(max((int) $request->integer('limit', 40), 1), 1000);
        $category = trim((string) $request->query('category'));
        $version = cache()->get('api.content.version', 1);
        $cacheKey = 'api.content.'.$version.'.'.sha1($category.'|'.$limit);

        $payload = cache()->remember($cacheKey, 300, function () use ($category, $limit) {
            $posts = Post::published()
                ->select([
                    'id', 'author_id', 'category_id', 'slug', 'title', 'excerpt',
                    'published_at', 'reading_time', 'is_breaking', 'label',
                    'service_type', 'notice_starts_at', 'notice_ends_at',
                    'views_count', 'featured_image', 'featured_image_alt',
                    'featured_image_responsive', 'canonical_url', 'seo_metadata',
                ])
                ->with([
                    'author:id,name',
                    'category:id,parent_id,slug',
                    'category.parent:id,slug',
                    'places:id,slug,name,locative,is_primary',
                ])
                ->when($category, fn ($query) => $query->where(function ($inner) use ($category) {
                    $inner->whereHas('category', fn ($categoryQuery) => $this->categorySlugFilter($categoryQuery, $category))
                        ->orWhereHas('categories', fn ($categoryQuery) => $this->categorySlugFilter($categoryQuery, $category));
                }))
                ->portalOrder()
                ->limit($limit)
                ->get();

            return [
                'articles' => $posts->map(fn (Post $post) => $this->postApiPayload($post, false, false))->values(),
            ];
        });

        return response()->json($payload)->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function apiShowPost(Request $request, Post $post)
    {
        abort_unless(
            $post->status === 'published'
                && $post->published_at
                && $post->published_at->lte(now()),
            404
        );

        return response()->json([
            'article' => $this->postApiPayload($post->load(['author', 'category.parent', 'tags', 'galleryMedia', 'places'])),
        ]);
    }

    public function apiOutages(Request $request, OutageQueryService $outages)
    {
        $place = trim((string) $request->query('place')) ?: null;
        $status = trim((string) $request->query('status')) ?: null;
        $limit = min(max((int) $request->integer('limit', 100), 1), 500);

        if ($status && ! in_array($status, ['upcoming', 'active', 'past'], true)) {
            abort(422, 'Nepoznat status filter.');
        }

        $posts = $outages->fetch($place, $status, $limit);

        return response()->json([
            'places' => $outages->listPlaces(),
            'outages' => $posts->map(fn (Post $post) => $this->outageApiPayload($post))->values(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function apiShowMatch(Request $request, FootballMatch $match)
    {
        abort_unless($match->status === 'published' && $match->published_at, 404);

        return response()->json([
            'match' => $this->matchApiPayload($match->load(['author', 'media']), $request, true),
        ]);
    }

    public function apiAds()
    {
        $settings = cache()->remember('settings.ads.public', 600, fn () => Setting::where('key', 'ad_settings')->first()?->value ?? []);
        $google = data_get($settings, 'google', []);
        $clientId = data_get($google, 'client_id') ?: 'ca-pub-1407310093643341';
        $slots = collect(AdPositions::all())->mapWithKeys(fn ($label, $key) => [$key => [
            'label' => $label,
            'enabled' => (bool) data_get($settings, "slots.$key.enabled"),
        ]]);

        return response()->json([
            'enabled' => (bool) data_get($google, 'enabled'),
            'clientId' => $clientId,
            'slots' => $slots,
        ]);
    }

    private function matchApiPayload(FootballMatch $match, Request $request, bool $includePhotos = false): array
    {
        $photos = $match->media->map(function ($media) use ($match, $request) {
            $variants = collect($media->responsive_paths['variants'] ?? []);
            $thumb = $variants->firstWhere('width', 480) ?: $variants->first() ?: ['path' => $media->path];
            $full = $variants->sortByDesc('width')->first() ?: ['path' => $media->path];
            $displayCaption = $media->pivot->caption && $media->pivot->caption !== $media->alt_text
                ? $media->pivot->caption
                : null;
            $caption = $displayCaption ?: $media->alt_text ?: $match->title;

            return [
                'id' => (string) $media->id,
                'src' => $this->storageUrl($request, $thumb['path'] ?? $media->path),
                'fullSrc' => $this->storageUrl($request, $full['path'] ?? $media->path),
                'alt' => $caption,
                'caption' => $displayCaption,
            ];
        })->values();

        return [
            'slug' => $match->slug,
            'title' => $match->title,
            'date' => optional($match->played_at)->toIso8601String(),
            'venue' => $match->venue,
            'author' => $match->author?->name,
            'home' => $match->home_team,
            'away' => $match->away_team,
            'homeScore' => $match->home_score,
            'awayScore' => $match->away_score,
            'score' => $match->score(),
            'excerpt' => $match->excerpt,
            'contentHtml' => $match->content,
            'cover' => $this->storageUrl($request, $match->cover_image),
            'photosCount' => $photos->count(),
            'url' => route('matches.show', $match->slug),
            'frontendUrl' => '/fk-posavina/utakmica/'.$match->slug,
            'photos' => $includePhotos ? $photos : $photos->take(3)->values(),
        ];
    }

    private function postApiPayload(Post $post, bool $includeContent = true, bool $includeTags = true): array
    {
        $category = $post->category?->parent ?: $post->category;
        $gallery = $includeContent && $post->relationLoaded('galleryMedia')
            ? $post->galleryMedia->map(function ($media) use ($post) {
                $variants = collect($media->responsive_paths['variants'] ?? []);
                $thumb = $variants->firstWhere('width', 480) ?: $variants->first() ?: ['path' => $media->path];
                $full = $variants->sortByDesc('width')->first() ?: ['path' => $media->path];
                $caption = $media->caption ?: $media->alt_text ?: $post->title;

                return [
                    'id' => (string) $media->id,
                    'src' => '/storage/'.ltrim($thumb['path'] ?? $media->path, '/'),
                    'fullSrc' => '/storage/'.ltrim($full['path'] ?? $media->path, '/'),
                    'alt' => $caption,
                    'caption' => $media->caption,
                ];
            })->values()
            : collect();

        return [
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'category' => $category?->slug ?: 'vijesti',
            'author' => $post->author?->name ?: 'Redakcija',
            'date' => optional($post->published_at)->toIso8601String(),
            'readingTime' => $post->reading_time,
            'urgent' => $post->is_breaking || $post->label === 'hitno',
            'notice' => $post->hasActiveNoticePriority(),
            'label' => $post->service_type ?: $post->label,
            'serviceType' => $post->service_type,
            'noticeStartsAt' => optional($post->notice_starts_at)->toIso8601String(),
            'noticeEndsAt' => optional($post->notice_ends_at)->toIso8601String(),
            'outageStatus' => $post->outageStatus(),
            'locationsRaw' => data_get($post->seo_metadata, 'outage.locationsRaw'),
            'transformerArea' => data_get($post->seo_metadata, 'outage.transformerArea'),
            'fieldUnit' => data_get($post->seo_metadata, 'outage.fieldUnit'),
            'sourceUrl' => data_get($post->seo_metadata, 'outage.sourceUrl') ?: $post->canonical_url,
            'places' => $post->relationLoaded('places')
                ? $post->places->map(fn ($place) => [
                    'slug' => $place->slug,
                    'name' => $place->name,
                    'locative' => $place->locative,
                    'isPrimary' => (bool) $place->is_primary,
                ])->values()
                : [],
            'tags' => $includeTags ? $post->tags->pluck('slug')->values() : [],
            'views' => $post->views_count,
            ...app(PowerOutageFeaturedImage::class)->apiPayload($post),
            'contentHtml' => $includeContent ? ArticleContent::prepare($post->content) : null,
            'gallery' => $gallery,
            'galleryCount' => $gallery->count(),
        ];
    }

    private function outageApiPayload(Post $post): array
    {
        return [
            ...$this->postApiPayload($post, false, true),
            'noticeSchedule' => $post->notice_schedule,
            'externalId' => data_get($post->seo_metadata, 'outage.externalId'),
        ];
    }

    private function storageUrl(Request $request, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return $request->getSchemeAndHttpHost().'/storage/'.ltrim($path, '/');
    }

    private function categorySlugFilter($query, string $category)
    {
        return $query
            ->where('slug', $category)
            ->orWhereHas('parent', fn ($parentQuery) => $parentQuery->where('slug', $category));
    }
}
