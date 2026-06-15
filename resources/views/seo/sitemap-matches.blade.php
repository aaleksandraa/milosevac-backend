<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    @foreach($matches as $match)
        <url>
            <loc>{{ route('matches.show', $match->slug) }}</loc>
            <lastmod>{{ $match->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.7</priority>
            @if($match->cover_image)
                <image:image>
                    <image:loc>{{ asset('storage/'.$match->cover_image) }}</image:loc>
                    <image:title>{{ e($match->title) }}</image:title>
                </image:image>
            @endif
            @foreach($match->media->take(20) as $media)
                <image:image>
                    <image:loc>{{ asset('storage/'.$media->path) }}</image:loc>
                    <image:title>{{ e($media->alt_text ?: $match->title) }}</image:title>
                </image:image>
            @endforeach
        </url>
    @endforeach
</urlset>
