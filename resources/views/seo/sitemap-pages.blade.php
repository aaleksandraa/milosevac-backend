<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ route('home') }}</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>hourly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ route('weather.show') }}</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
        <changefreq>hourly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc>{{ route('about-milosevac') }}</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc>{{ route('fk-posavina') }}</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>daily</changefreq>
        <priority>0.85</priority>
    </url>
</urlset>
