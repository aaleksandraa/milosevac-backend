@extends('layouts.portal')

@section('content')
@php($tickerItems = $activeNotices->concat($breaking)->unique('id')->take(8))
@if($tickerItems->isNotEmpty())
    <div class="breaking" aria-label="Breaking news">
        <div class="breaking-track">
            @foreach($tickerItems->concat($tickerItems) as $item)
                <a href="{{ route('posts.show', $item->slug) }}">{{ $item->labelText() ?: 'Hitno' }}: {{ $item->title }}</a>
            @endforeach
        </div>
    </div>
@endif

<section class="section">
    <div class="container-news hero-grid">
        @if($featured->first())
            <x-news-card :post="$featured->first()" hero />
        @endif
        <div class="side-featured">
            @foreach($featured->skip(1)->take(3) as $post)
                <x-news-card :post="$post" horizontal />
            @endforeach
        </div>
    </div>
</section>

<x-ad-slot position="home_after_featured" />

<section class="container-news local-alerts">
    <div class="section-heading urgent-heading">
        <div>
            <span></span>
            <h2>Lokalna obavještenja</h2>
            <p>Praktične informacije za stanovnike Miloševca</p>
        </div>
        <a href="{{ route('categories.show', 'vijesti') }}">Sve →</a>
    </div>
    <div class="alert-grid">
        @php($alertItems = $activeNotices->concat($breaking)->concat($posts->getCollection())->unique('id')->take(3))
        @foreach($alertItems as $alert)
            <a class="alert-card {{ $alert->hasActiveNoticePriority() ? 'alert-notice' : ($loop->first ? 'alert-high' : 'alert-info') }}" href="{{ route('posts.show', $alert->slug) }}">
                <span>{{ $alert->labelText() ?: ($loop->first ? 'Hitno' : 'Info') }}</span>
                <strong>{{ $alert->title }}</strong>
                @if($alert->notice_schedule)
                    <p>{{ \Illuminate\Support\Str::limit(strip_tags($alert->notice_schedule), 110) }}</p>
                @endif
                <small>Miloševac →</small>
            </a>
        @endforeach
    </div>
</section>

<section class="section">
    <div class="container-news grid-main">
        <div>
            <div class="section-heading">
                <div><span></span><h1>Najnovije vijesti</h1></div>
                <a href="{{ route('categories.show', 'vijesti') }}">Sve vijesti →</a>
            </div>
            <div class="post-list">
                @foreach($posts as $post)
                    <x-news-card :post="$post" />
                    @if($loop->iteration === 6)
                        <x-ad-slot position="home_mid_feed" />
                    @endif
                @endforeach
            </div>
            {{ $posts->links('vendor.pagination.clean') }}
        </div>
        <x-sidebar :popular="$popular" />
    </div>
</section>

<section class="sport-band">
    <div class="container-news">
        <div class="section-heading sport-heading">
            <div>
                <span></span>
                <h2>FK Posavina</h2>
                <p>Rezultati, vijesti i galerije iz kluba</p>
            </div>
            <a href="{{ route('fk-posavina') }}">FK Posavina →</a>
        </div>
        <div class="sport-grid">
            <div class="score-card">
                <span class="category-pill sport-pill">Posljednja utakmica</span>
                <div class="score-row">
                    <div><strong>FK Posavina</strong><b>3</b></div>
                    <small>25.04.</small>
                    <div><strong>Sloga</strong><b class="muted-score">0</b></div>
                </div>
                <p>Stadion Miloševac · Domaća utakmica</p>
                <hr>
                <small>Naredna utakmica</small>
                <strong>Mladost - FK Posavina</strong>
                <p>subota, 16:00</p>
            </div>
            <div class="sport-posts">
                @php($sportPosts = $posts->getCollection()->filter(fn ($item) => \Illuminate\Support\Str::startsWith($item->category->slug, 'sport')))
                @foreach($sportPosts->take(1) as $sportPost)
                    <x-news-card :post="$sportPost" wide />
                @endforeach
                @foreach($posts->getCollection()->reject(fn ($item) => $item->hasActiveNoticePriority())->take(2) as $post)
                    <x-news-card :post="$post" horizontal />
                @endforeach
            </div>
        </div>
    </div>
</section>

<section class="container-news gallery-section">
    <div class="section-heading">
        <div>
            <span></span>
            <h2>Slike i galerije</h2>
            <p>Najnovije fotografije iz Miloševca</p>
        </div>
        <a href="{{ route('categories.show', 'slike') }}">Sve galerije →</a>
    </div>
    <div class="gallery-grid">
        @foreach(['Proljećni pejzaži Posavine', 'Festival KUD Miloševac', 'FK Posavina u slikama', 'Stari Miloševac iz arhive'] as $gallery)
            <a class="gallery-card cover-{{ ['sport','projekti','milosevac','vijesti'][$loop->index] }}" href="{{ route('categories.show', 'slike') }}">
                <span>{{ 18 + ($loop->index * 9) }}</span>
                <strong>{{ $gallery }}</strong>
            </a>
        @endforeach
    </div>
</section>

<section class="container-news projects-section">
    <div class="section-heading accent-heading">
        <div>
            <span></span>
            <h2>Projekti i zanimljivosti</h2>
            <p>Naše stalne rubrike</p>
        </div>
    </div>
    <div class="project-grid">
        @foreach(['Da li ste znali', 'Intervjui', 'Top 5', 'Recepti', 'Na današnji dan', 'Sam svoj majstor'] as $project)
            <a class="project-card cover-{{ ['projekti','slike','vijesti','milosevac','sport','projekti'][$loop->index] }}" href="{{ route('categories.show', 'projekti') }}">
                <small>Rubrika</small>
                <strong>{{ $project }}</strong>
                <p>Zanimljive priče iz Miloševca.</p>
            </a>
        @endforeach
    </div>
</section>

<section class="container-news cta-section">
    <div>
        <h2>Imate vijest iz Miloševca?</h2>
        <p>Pišite nam - najvažnije lokalne informacije objavljujemo brzo i provjereno.</p>
        <a class="btn cta-btn" href="mailto:redakcija@milosevac.com">Pošaljite vijest</a>
    </div>
</section>
@endsection
