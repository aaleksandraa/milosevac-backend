<?xml version="1.0" encoding="UTF-8"?>
@php($frontend = rtrim(config('services.frontend.url'), '/'))
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach($categories as $category)
        <url>
            <loc>{{ $frontend }}/kategorija/{{ $category->slug }}</loc>
            <lastmod>{{ $category->updated_at->toAtomString() }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    @endforeach
    @foreach($tags as $tag)
        <url>
            <loc>{{ $frontend }}/tag/{{ $tag->slug }}</loc>
            <lastmod>{{ $tag->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.5</priority>
        </url>
    @endforeach
    @foreach($authors as $author)
        <url>
            <loc>{{ $frontend }}/autor/{{ $author->slug }}</loc>
            <lastmod>{{ $author->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.5</priority>
        </url>
    @endforeach
</urlset>
