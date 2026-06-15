<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach($categories as $category)
        <url>
            <loc>{{ route('categories.show', $category->slug) }}</loc>
            <lastmod>{{ $category->updated_at->toAtomString() }}</lastmod>
            <changefreq>daily</changefreq>
            <priority>0.7</priority>
        </url>
    @endforeach
    @foreach($tags as $tag)
        <url>
            <loc>{{ route('tags.show', $tag->slug) }}</loc>
            <lastmod>{{ $tag->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.5</priority>
        </url>
    @endforeach
    @foreach($authors as $author)
        <url>
            <loc>{{ route('authors.show', $author->slug) }}</loc>
            <lastmod>{{ $author->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.5</priority>
        </url>
    @endforeach
</urlset>
