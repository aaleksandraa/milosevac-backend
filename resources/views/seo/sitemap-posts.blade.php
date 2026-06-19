<?xml version="1.0" encoding="UTF-8"?>
@php($frontend = rtrim(config('services.frontend.url'), '/'))
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    @foreach($posts as $post)
        <url>
            <loc>{{ $frontend }}/clanak/{{ $post->slug }}</loc>
            <lastmod>{{ $post->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>{{ $post->is_featured ? '0.9' : '0.8' }}</priority>
            @if($post->featured_image)
                <image:image>
                    <image:loc>{{ asset('storage/'.$post->featured_image) }}</image:loc>
                    <image:title>{{ e($post->title) }}</image:title>
                    <image:caption>{{ e($post->featured_image_alt ?: $post->excerpt) }}</image:caption>
                </image:image>
            @endif
        </url>
    @endforeach
</urlset>
