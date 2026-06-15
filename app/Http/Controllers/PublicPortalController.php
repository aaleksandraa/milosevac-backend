<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FootballMatch;
use App\Models\Post;
use App\Models\PostView;
use App\Models\Tag;
use App\Models\User;
use App\Support\ArticleContent;
use App\Support\Seo;
use Illuminate\Http\Request;

class PublicPortalController extends Controller
{
    public function home()
    {
        $posts = Post::published()->with(['category', 'author', 'tags'])->portalOrder()->paginate(12);
        $activeNotices = Post::published()->with(['category', 'author', 'tags'])->activeNotices()->latest('notice_ends_at')->take(3)->get();
        $featured = $activeNotices
            ->concat(Post::published()->with(['category', 'author'])->where('is_featured', true)->latest('published_at')->take(6)->get())
            ->unique('id')
            ->take(4);
        $popular = $this->popularPosts();
        $breaking = Post::published()->where('is_breaking', true)->latest('published_at')->take(6)->get();
        $categories = $this->categories();

        return view('portal.home', [
            'seo' => Seo::paginated(Seo::page('Najnovije vijesti', 'Lokalne vijesti, servisne informacije i magazinske priče iz Miloševca.'), $posts),
            'posts' => $posts,
            'featured' => $featured,
            'popular' => $popular,
            'breaking' => $breaking,
            'activeNotices' => $activeNotices,
            'categories' => $categories,
        ]);
    }

    public function aboutMilosevac()
    {
        return view('portal.about-milosevac', [
            'seo' => Seo::page(
                'O Miloševcu',
                'O Miloševcu: istorija, život, ljudi, fotografije i lokalne priče iz Miloševca, Modriča, Republika Srpska.',
                route('about-milosevac')
            ),
        ]);
    }

    public function showPost(Request $request, string $slug)
    {
        $post = Post::published()->with(['category', 'author', 'tags'])->where('slug', $slug)->firstOrFail();

        $fingerprint = [
            'post_id' => $post->id,
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
            'viewed_at' => now(),
        ];
        PostView::create($fingerprint);
        $post->increment('views_count');

        $related = Post::published()
            ->with(['category', 'author'])
            ->where('id', '!=', $post->id)
            ->where(function ($query) use ($post) {
                $query->where('category_id', $post->category_id)
                    ->orWhereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $post->tags->pluck('id')));
            })
            ->latest('published_at')
            ->take(4)
            ->get();

        return view('portal.post', [
            'seo' => Seo::post($post),
            'post' => $post,
            'related' => $related,
            'popular' => $this->popularPosts(),
        ]);
    }

    public function category(Request $request, Category $category)
    {
        [$sort, $activeTags] = $this->archiveFilters($request);
        $categoryIds = $category->children()->exists()
            ? $category->children()->pluck('id')->push($category->id)
            : collect([$category->id]);

        $tagOptions = Tag::whereHas('posts', fn ($query) => $query->whereIn('category_id', $categoryIds)->published())
            ->orderBy('name')
            ->get();

        $query = Post::query()
            ->published()
            ->with(['author', 'category', 'tags'])
            ->whereIn('category_id', $categoryIds)
            ->when($activeTags->isNotEmpty(), fn ($postQuery) => $postQuery->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tags.slug', $activeTags)));

        $posts = $this->applyArchiveSort($query, $sort)->paginate(12)->withQueryString();
        $seo = Seo::paginated(Seo::category($category), $posts);
        if ($activeTags->isNotEmpty() || $sort !== 'new') {
            $seo = Seo::noindex($seo);
        }

        return view('portal.archive', [
            'seo' => $seo,
            'title' => $category->name,
            'description' => $category->description,
            'posts' => $posts,
            'popular' => $this->popularPosts(),
            'archiveType' => 'category',
            'sort' => $sort,
            'activeTags' => $activeTags,
            'tagOptions' => $tagOptions,
        ]);
    }

    public function tag(Request $request, Tag $tag)
    {
        [$sort] = $this->archiveFilters($request);
        $posts = $this->applyArchiveSort($tag->posts()->published()->with(['author', 'category', 'tags']), $sort)
            ->paginate(12)
            ->withQueryString();
        $seo = Seo::paginated(Seo::tag($tag), $posts);
        if ($sort !== 'new') {
            $seo = Seo::noindex($seo);
        }

        return view('portal.archive', [
            'seo' => $seo,
            'title' => '#'.$tag->name,
            'description' => $tag->description,
            'posts' => $posts,
            'popular' => $this->popularPosts(),
            'archiveType' => 'tag',
            'sort' => $sort,
            'activeTags' => collect(),
            'tagOptions' => collect(),
        ]);
    }

    public function author(Request $request, User $author)
    {
        [$sort] = $this->archiveFilters($request);
        $posts = $this->applyArchiveSort($author->posts()->published()->with(['author', 'category', 'tags']), $sort)
            ->paginate(12)
            ->withQueryString();
        $seo = Seo::paginated(Seo::author($author), $posts);
        if ($sort !== 'new') {
            $seo = Seo::noindex($seo);
        }

        return view('portal.archive', [
            'seo' => $seo,
            'title' => $author->name,
            'description' => $author->bio,
            'posts' => $posts,
            'popular' => $this->popularPosts(),
            'archiveType' => 'author',
            'sort' => $sort,
            'activeTags' => collect(),
            'tagOptions' => collect(),
        ]);
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $posts = Post::published()
            ->with(['author', 'category', 'tags'])
            ->when($q, fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', "%{$q}%")
                    ->orWhere('excerpt', 'like', "%{$q}%")
                    ->orWhere('content', 'like', "%{$q}%");
            }))
            ->latest('published_at')
            ->paginate(12)
            ->withQueryString();

        return view('portal.archive', [
            'seo' => Seo::noindex(Seo::paginated(Seo::page('Pretraga', $q ? "Rezultati pretrage za {$q}" : 'Pretražite vijesti i članke.', route('search')), $posts)),
            'title' => $q ? "Pretraga: {$q}" : 'Pretraga',
            'description' => 'Brza pretraga portala.',
            'posts' => $posts,
            'popular' => $this->popularPosts(),
            'archiveType' => 'search',
            'sort' => 'new',
            'activeTags' => collect(),
            'tagOptions' => collect(),
        ]);
    }

    public function fkPosavina()
    {
        $sportPosts = Post::published()
            ->with(['category', 'author', 'tags'])
            ->whereHas('category', function ($query) {
                $query->where('slug', 'sport')
                    ->orWhere('slug', 'like', 'sport-%')
                    ->orWhere('name', 'like', '%Posavina%');
            })
            ->latest('published_at')
            ->take(6)
            ->get();
        $matches = FootballMatch::published()
            ->with(['author', 'media'])
            ->latest('played_at')
            ->take(6)
            ->get();
        $galleryMatches = FootballMatch::published()
            ->with(['author', 'media'])
            ->whereHas('media')
            ->latest('played_at')
            ->take(9)
            ->get();

        $description = 'Rezultati, raspored utakmica, vijesti i galerije FK Posavina Miloševac na jednom mjestu.';
        $seo = Seo::page(
            'FK Posavina rezultati, raspored i galerije',
            $description,
            route('fk-posavina'),
            null,
            [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => 'FK Posavina',
                'description' => $description,
                'url' => route('fk-posavina'),
                'isPartOf' => [
                    '@type' => 'WebSite',
                    'name' => Seo::site()['name'],
                    'url' => route('home'),
                ],
                'about' => [
                    '@type' => 'SportsTeam',
                    'name' => 'FK Posavina',
                    'sport' => 'Football',
                    'url' => route('fk-posavina'),
                    'memberOf' => [
                        '@type' => 'SportsOrganization',
                        'name' => 'Područna liga Republike Srpske - Modriča - Šamac, grupa B',
                    ],
                ],
            ]
        );

        $seo['schemas'][] = Seo::breadcrumb([
            ['name' => 'Naslovna', 'url' => route('home')],
            ['name' => 'FK Posavina', 'url' => route('fk-posavina')],
        ]);

        $seoItems = $matches->map(fn (FootballMatch $match) => [
            'url' => route('matches.show', $match->slug),
            'name' => $match->title,
        ])->concat($sportPosts->map(fn (Post $post) => [
            'url' => route('posts.show', $post->slug),
            'name' => $post->title,
        ]))->take(10)->values();

        if ($seoItems->isNotEmpty()) {
            $seo['schemas'][] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => 'FK Posavina utakmice, galerije i vijesti',
                'itemListElement' => $seoItems->map(fn (array $item, int $index) => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'url' => $item['url'],
                    'name' => $item['name'],
                ])->all(),
            ];
        }

        return view('portal.fk-posavina', [
            'seo' => $seo,
            'sportPosts' => $sportPosts,
            'matches' => $matches,
            'galleryMatches' => $galleryMatches,
            'popular' => $this->popularPosts(),
        ]);
    }

    public function showMatch(FootballMatch $match)
    {
        abort_unless($match->status === 'published' && $match->published_at, 404);
        $match->load(['author', 'media']);

        return view('portal.match', [
            'seo' => Seo::page(
                $match->meta_title ?: $match->title,
                $match->meta_description ?: $match->excerpt,
                route('matches.show', $match->slug),
                Seo::storageImage($match->cover_image),
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'SportsEvent',
                    'name' => $match->title,
                    'startDate' => optional($match->played_at)->toIso8601String(),
                    'location' => $match->venue ? ['@type' => 'Place', 'name' => $match->venue] : null,
                    'url' => route('matches.show', $match->slug),
                ]
            ),
            'match' => $match,
            'popular' => $this->popularPosts(),
        ]);
    }

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
                    'views_count', 'featured_image', 'featured_image_alt',
                    'featured_image_responsive',
                ])
                ->with([
                    'author:id,name',
                    'category:id,parent_id,slug',
                    'category.parent:id,slug',
                ])
                ->when($category, fn ($query) => $query->whereHas(
                    'category',
                    fn ($categoryQuery) => $categoryQuery
                        ->where('slug', $category)
                        ->orWhereHas('parent', fn ($parentQuery) => $parentQuery->where('slug', $category))
                ))
                ->portalOrder()
                ->limit($limit)
                ->get();

            return [
                'articles' => $posts->map(fn (Post $post) => $this->postApiPayload($post, false, false))->values(),
            ];
        });

        return response()->json($payload)->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
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
            'article' => $this->postApiPayload($post->load(['author', 'category.parent', 'tags'])),
        ]);
    }

    public function apiShowMatch(Request $request, FootballMatch $match)
    {
        abort_unless($match->status === 'published' && $match->published_at, 404);

        return response()->json([
            'match' => $this->matchApiPayload($match->load(['author', 'media']), $request, true),
        ]);
    }

    public function sitemap()
    {
        return response()->view('seo.sitemap-index')->header('Content-Type', 'application/xml');
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

        return [
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'category' => $category?->slug ?: 'vijesti',
            'author' => $post->author?->name ?: 'Redakcija',
            'date' => optional($post->published_at)->toIso8601String(),
            'readingTime' => $post->reading_time,
            'urgent' => $post->is_breaking || $post->label === 'hitno',
            'tags' => $includeTags ? $post->tags->pluck('slug')->values() : [],
            'views' => $post->views_count,
            'image' => $post->featured_image ? '/storage/'.ltrim($post->featured_image, '/') : null,
            'imageSrcSet' => collect($post->featured_image_responsive['variants'] ?? [])
                ->map(fn (array $variant) => '/storage/'.ltrim($variant['path'], '/').' '.$variant['width'].'w')
                ->implode(', ') ?: null,
            'imageAlt' => $post->featured_image_alt ?: $post->title,
            'contentHtml' => $includeContent ? ArticleContent::withYoutubeEmbeds($post->content) : null,
            'body' => [],
        ];
    }

    private function storageUrl(Request $request, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return $request->getSchemeAndHttpHost().'/storage/'.ltrim($path, '/');
    }

    public function sitemapPages()
    {
        $lastmod = collect([
            Post::published()->max('updated_at'),
            FootballMatch::published()->max('updated_at'),
        ])->filter()->max() ?: now();

        return response()->view('seo.sitemap-pages', compact('lastmod'))->header('Content-Type', 'application/xml');
    }

    public function sitemapPosts()
    {
        $posts = Post::published()->with(['category', 'author', 'tags'])->latest('updated_at')->get();

        return response()->view('seo.sitemap-posts', compact('posts'))->header('Content-Type', 'application/xml');
    }

    public function sitemapMatches()
    {
        $matches = FootballMatch::published()->with('media')->latest('updated_at')->get();

        return response()->view('seo.sitemap-matches', compact('matches'))->header('Content-Type', 'application/xml');
    }

    public function sitemapNews()
    {
        $posts = Post::published()
            ->with(['category', 'tags'])
            ->where('published_at', '>=', now()->subDays(2))
            ->latest('published_at')
            ->limit(1000)
            ->get();

        return response()->view('seo.sitemap-news', compact('posts'))->header('Content-Type', 'application/xml');
    }

    public function sitemapTaxonomies()
    {
        $categories = Category::where('is_active', true)->get();
        $tags = Tag::all();
        $authors = User::whereHas('posts', fn ($query) => $query->published())->get();

        return response()->view('seo.sitemap-taxonomies', compact('categories', 'tags', 'authors'))->header('Content-Type', 'application/xml');
    }

    public function robots()
    {
        $robots = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /author',
            'Disallow: /login',
            'Disallow: /pretraga',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ]);

        return response($robots, 200)->header('Content-Type', 'text/plain');
    }

    public function feed()
    {
        $posts = Post::published()->with(['category', 'author'])->latest('published_at')->take(30)->get();

        return response()->view('seo.feed', compact('posts'))->header('Content-Type', 'application/rss+xml');
    }

    private function popularPosts()
    {
        return cache()->remember('posts.popular', 600, fn () => Post::published()->with(['category', 'author'])->orderByDesc('views_count')->take(5)->get());
    }

    private function categories()
    {
        return cache()->remember('categories.menu', 3600, fn () => Category::where('is_active', true)->whereNull('parent_id')->with('children')->orderBy('sort_order')->get());
    }

    private function archiveFilters(Request $request): array
    {
        $sort = in_array($request->query('sort'), ['new', 'old', 'popular', 'reading'], true) ? $request->query('sort') : 'new';
        $rawTags = $request->query('tags', $request->query('tag', []));
        $tags = collect(is_array($rawTags) ? $rawTags : explode(',', (string) $rawTags))
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->values();

        return [$sort, $tags];
    }

    private function applyArchiveSort($query, string $sort)
    {
        return match ($sort) {
            'old' => $query->orderBy('published_at'),
            'popular' => $query->orderByDesc('views_count')->latest('published_at'),
            'reading' => $query->orderByDesc('reading_time')->latest('published_at'),
            default => $query->latest('published_at'),
        };
    }
}
