<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $seo['title'] ?? 'Miloševac' }}</title>
    <meta name="description" content="{{ $seo['description'] ?? '' }}">
    <meta name="robots" content="{{ $seo['robots'] ?? 'index, follow' }}">
    <link rel="canonical" href="{{ $seo['canonical'] ?? url()->current() }}">
    @isset($seo['prev'])<link rel="prev" href="{{ $seo['prev'] }}">@endisset
    @isset($seo['next'])<link rel="next" href="{{ $seo['next'] }}">@endisset
    <link rel="alternate" type="application/rss+xml" title="Miloševac RSS" href="{{ route('feed') }}">
    @if(config('services.analytics.gsc_verification'))
        <meta name="google-site-verification" content="{{ config('services.analytics.gsc_verification') }}">
    @endif
    <meta property="og:title" content="{{ $seo['title'] ?? '' }}">
    <meta property="og:description" content="{{ $seo['description'] ?? '' }}">
    <meta property="og:type" content="{{ $seo['type'] ?? 'website' }}">
    <meta property="og:url" content="{{ $seo['canonical'] ?? url()->current() }}">
    @isset($seo['image'])
        <meta property="og:image" content="{{ $seo['image'] }}">
        <meta property="og:image:secure_url" content="{{ $seo['image'] }}">
        <meta property="og:image:type" content="{{ $seo['image_type'] ?? 'image/webp' }}">
        <meta property="og:image:alt" content="{{ $seo['image_alt'] ?? $seo['title'] ?? 'Miloševac' }}">
    @endisset
    <meta property="og:site_name" content="{{ $seo['site_name'] ?? 'Miloševac' }}">
    <meta property="og:locale" content="{{ $seo['locale'] ?? 'bs_BA' }}">
    @isset($seo['published_at'])<meta property="article:published_time" content="{{ $seo['published_at'] }}">@endisset
    @isset($seo['modified_at'])<meta property="article:modified_time" content="{{ $seo['modified_at'] }}">@endisset
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] ?? '' }}">
    <meta name="twitter:description" content="{{ $seo['description'] ?? '' }}">
    @isset($seo['image'])
        <meta name="twitter:image" content="{{ $seo['image'] }}">
        <meta name="twitter:image:alt" content="{{ $seo['image_alt'] ?? $seo['title'] ?? 'Miloševac' }}">
    @endisset
    @foreach(($seo['schemas'] ?? []) as $schema)
        <script type="application/ld+json">@json($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    @endforeach
    @php
        $organizationSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'Miloševac',
            'url' => url('/'),
        ];
        $websiteSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Miloševac',
            'url' => url('/'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => route('search').'?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
        ];
    @endphp
    <script type="application/ld+json">@json($organizationSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    <script type="application/ld+json">@json($websiteSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    @php
        $adsBootstrap = cache()->remember('settings.ads.bootstrap', 600, function () {
            $settings = \App\Models\Setting::where('key', 'ad_settings')->first()?->value ?? [];
            return [
                'enabled' => (bool) data_get($settings, 'google.enabled'),
                'clientId' => data_get($settings, 'google.client_id'),
            ];
        });
    @endphp
    <script>
        window.milosevacAnalytics = {
            googleAnalyticsId: @json(config('services.analytics.google_id')),
        };
        window.milosevacAds = @json($adsBootstrap);
    </script>
    @include('partials.vite-assets')
</head>
<body>
<a class="skip-link" href="#content">Preskoči na sadržaj</a>
@php($topWeather = app(\App\Support\WeatherService::class)->current())
<div class="topbar">
    <div class="container-news topbar-inner">
        <span>{{ now()->locale('bs')->translatedFormat('l, d. F Y.') }}</span>
        <span class="topbar-center">Oficijalna stranica Miloševca</span>
        <div class="topbar-actions">
            <a href="{{ route('weather.show') }}" class="weather-badge" aria-label="Vrijeme u Miloševcu">
                @if($topWeather)
                    <x-weather-icon :type="$topWeather['icon']" />
                    <span>{{ $topWeather['temperature'] }}°C</span>
                    <small>Miloševac</small>
                @else
                    <span>Vrijeme</span>
                @endif
            </a>
            <a href="{{ route('search') }}" class="topbar-search">Pretraga</a>
        </div>
    </div>
</div>
<header class="site-header">
    <div class="container-news header-inner">
        <a class="brand" href="{{ route('home') }}">
            <span>Miloševac</span>
        </a>
        <nav class="nav" data-mobile-nav aria-label="Glavna navigacija">
            <a href="{{ route('categories.show', 'vijesti') }}">Vijesti</a>
            <a href="{{ route('fk-posavina') }}">FK Posavina</a>
            <a href="{{ route('weather.show') }}">Vrijeme</a>
        </nav>
        <form class="search-form header-search" action="{{ route('search') }}" method="get">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Pretraga" aria-label="Pretraga">
            <button class="btn" type="submit">Traži</button>
        </form>
        <button class="icon-button mobile-toggle" type="button" data-mobile-toggle aria-label="Otvori meni">&#9776;</button>
    </div>
</header>
<x-ad-slot position="top_banner" />
<main id="content">
    @yield('content')
</main>
<x-ad-slot position="footer_banner" />
<footer class="footer">
    <div class="container-news footer-main">
        <div class="footer-brand">
            <a class="brand footer-logo" href="{{ route('home') }}">
                <span>Miloševac</span>
            </a>
            <p>Lokalni informativni portal za Miloševac i okolinu: vijesti, obavještenja, sport, kultura i zajednica.</p>
            <div class="footer-actions">
                <a href="{{ route('feed') }}">RSS feed</a>
                <button type="button" data-open-cookie-settings>Postavke kolačića</button>
            </div>
        </div>
        <div class="footer-newsletter">
            <h2>Pratite najvažnije lokalne informacije</h2>
            <p>Portal je pripremljen za Google Search Console i analitiku uz poštovanje izbora korisnika za kolačiće.</p>
            <a class="btn cta-btn" href="mailto:redakcija@milosevac.com">Kontakt redakciji</a>
        </div>
    </div>
    <div class="container-news footer-grid">
        <div>
            <h3>Kategorije</h3>
            <ul class="footer-links">
                <li><a href="{{ route('categories.show', 'vijesti') }}">Vijesti</a></li>
                <li><a href="{{ route('fk-posavina') }}">FK Posavina</a></li>
                <li><a href="{{ route('weather.show') }}">Vrijeme</a></li>
            </ul>
        </div>
        <div>
            <h3>Portal</h3>
            <ul class="footer-links">
                <li><a href="{{ route('home') }}">Naslovna</a></li>
                <li><a href="{{ route('fk-posavina') }}">FK Posavina</a></li>
                <li><a href="{{ route('search') }}">Pretraga</a></li>
                <li><a href="{{ route('weather.show') }}">Vrijeme u Miloševcu</a></li>
                <li><a href="{{ route('sitemap') }}">Sitemap</a></li>
            </ul>
        </div>
        <div>
            <h3>Pravno</h3>
            <ul class="footer-links">
                <li><a href="{{ route('privacy') }}">Politika privatnosti</a></li>
                <li><a href="{{ route('cookies') }}">Politika kolačića</a></li>
                <li><a href="{{ route('terms') }}">Uslovi korištenja</a></li>
                <li><a href="{{ route('login') }}">CMS prijava</a></li>
            </ul>
        </div>
        <div>
            <h3>Kontakt</h3>
            <ul class="footer-links">
                <li>Miloševac, Modriča</li>
                <li><a href="mailto:redakcija@milosevac.com">redakcija@milosevac.com</a></li>
                <li>+387 00 000 000</li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <div class="container-news">
            <span>© {{ date('Y') }} Miloševac. Sva prava zadržana.</span>
            <span>Analitika se aktivira samo nakon pristanka.</span>
        </div>
    </div>
</footer>
@include('partials.cookie-consent')
</body>
</html>
