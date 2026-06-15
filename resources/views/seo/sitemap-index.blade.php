<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
        <loc>{{ route('sitemap.pages') }}</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
    </sitemap>
    <sitemap>
        <loc>{{ route('sitemap.posts') }}</loc>
        <lastmod>{{ optional(\App\Models\Post::published()->latest('updated_at')->first())->updated_at?->toAtomString() ?? now()->toAtomString() }}</lastmod>
    </sitemap>
    <sitemap>
        <loc>{{ route('sitemap.news') }}</loc>
        <lastmod>{{ optional(\App\Models\Post::published()->latest('published_at')->first())->published_at?->toAtomString() ?? now()->toAtomString() }}</lastmod>
    </sitemap>
    <sitemap>
        <loc>{{ route('sitemap.matches') }}</loc>
        <lastmod>{{ optional(\App\Models\FootballMatch::published()->latest('updated_at')->first())->updated_at?->toAtomString() ?? now()->toAtomString() }}</lastmod>
    </sitemap>
    <sitemap>
        <loc>{{ route('sitemap.taxonomies') }}</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
    </sitemap>
</sitemapindex>
