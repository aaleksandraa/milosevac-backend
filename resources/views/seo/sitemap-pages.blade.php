<?xml version="1.0" encoding="UTF-8"?>
@php($frontend = rtrim(config('services.frontend.url'), '/'))
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ $frontend }}/</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>hourly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ $frontend }}/vrijeme</loc>
        <lastmod>{{ now()->toAtomString() }}</lastmod>
        <changefreq>hourly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc>{{ $frontend }}/omilosevcu</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc>{{ $frontend }}/fk-posavina</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>daily</changefreq>
        <priority>0.85</priority>
    </url>
    <url>
        <loc>{{ $frontend }}/kontakt</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc>{{ $frontend }}/politika-privatnosti</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>yearly</changefreq>
        <priority>0.3</priority>
    </url>
    <url>
        <loc>{{ $frontend }}/politika-kolacica</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>yearly</changefreq>
        <priority>0.3</priority>
    </url>
    <url>
        <loc>{{ $frontend }}/uslovi-koristenja</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>yearly</changefreq>
        <priority>0.3</priority>
    </url>
</urlset>
