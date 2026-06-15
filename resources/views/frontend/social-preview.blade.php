<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:locale" content="{{ $seo['locale'] }}">
    <meta property="og:type" content="{{ $seo['type'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta property="og:image:secure_url" content="{{ $seo['image'] }}">
    <meta property="og:image:type" content="{{ $seo['image_type'] }}">
    <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $seo['image'] }}">
    <meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">
    @isset($seo['published_at'])<meta property="article:published_time" content="{{ $seo['published_at'] }}">@endisset
    @isset($seo['modified_at'])<meta property="article:modified_time" content="{{ $seo['modified_at'] }}">@endisset
    @foreach(($seo['schemas'] ?? []) as $schema)
        <script type="application/ld+json">@json($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    @endforeach
</head>
<body>
    <main>
        <h1>{{ $seo['title'] }}</h1>
        <p>{{ $seo['description'] }}</p>
        <a href="{{ $seo['canonical'] }}">Otvori stranicu</a>
    </main>
</body>
</html>
