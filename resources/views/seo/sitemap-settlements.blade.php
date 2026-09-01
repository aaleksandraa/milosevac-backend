<?xml version="1.0" encoding="UTF-8"?>
@php($frontend = rtrim(config('services.frontend.url'), '/'))
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ $frontend }}/mjesta</loc>
        <lastmod>{{ \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
    @foreach($municipalities as $municipality)
    <url>
        <loc>{{ $frontend }}/mjesta/{{ $municipality->slug }}</loc>
        <lastmod>{{ optional($municipality->updated_at)->toAtomString() ?? \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.65</priority>
    </url>
    @endforeach
    @foreach($settlements as $settlement)
    <url>
        <loc>{{ $frontend }}/mjesta/{{ $settlement->municipality->slug }}/{{ $settlement->slug }}</loc>
        <lastmod>{{ optional($settlement->updated_at)->toAtomString() ?? \Carbon\Carbon::parse($lastmod)->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    @endforeach
</urlset>
