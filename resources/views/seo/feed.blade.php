<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>Miloševac</title>
        <link>{{ route('home') }}</link>
        <description>Lokalne vijesti, servisne informacije i magazin za Miloševac.</description>
        <language>bs-BA</language>
        <lastBuildDate>{{ optional($posts->first())->published_at?->toRssString() ?? now()->toRssString() }}</lastBuildDate>
        <atom:link href="{{ route('feed') }}" rel="self" type="application/rss+xml" />
        @foreach($posts as $post)
            <item>
                <title>{{ e($post->title) }}</title>
                <link>{{ route('posts.show', $post->slug) }}</link>
                <guid isPermaLink="true">{{ route('posts.show', $post->slug) }}</guid>
                <description>{{ e($post->excerpt) }}</description>
                <category>{{ e($post->category->name) }}</category>
                <author>redakcija@milosevac.com ({{ e($post->author->name) }})</author>
                <pubDate>{{ $post->published_at->toRssString() }}</pubDate>
            </item>
        @endforeach
    </channel>
</rss>
