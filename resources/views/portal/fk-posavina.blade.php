@extends('layouts.portal')

@section('content')
<section class="club-page">
    @php
        $featuredMatch = $matches->first();
        $featuredHome = $featuredMatch?->home_team ?? 'FK Posavina';
        $featuredAway = $featuredMatch?->away_team ?? 'Sloga';
        $featuredScore = $featuredMatch ? $featuredMatch->score() : '3:0';
        $featuredDate = $featuredMatch?->played_at?->format('d.m.Y.') ?? '25.04.2026.';
        $sampleGalleryImages = [
            asset('fk-posavina-gallery-1.svg'),
            asset('fk-posavina-gallery-2.svg'),
            asset('fk-posavina-gallery-3.svg'),
        ];
    @endphp

    <div class="container-news club-hero">
        <div>
            <nav class="archive-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Naslovna</a>
                <span>/</span>
                <span>FK Posavina</span>
            </nav>
            <span class="archive-kicker">Klub</span>
            <h1>FK Posavina</h1>
            <p>Rezultati, tabela, vijesti i foto galerije utakmica kluba iz Miloševca.</p>
            <div class="club-actions">
                <a class="btn cta-btn" href="#rezultati">Rezultati i tabela</a>
                <a class="btn secondary" href="#galerije">Foto galerije</a>
            </div>
        </div>
        <aside class="club-score-panel club-last-match" aria-label="Zadnja utakmica FK Posavina">
            <div class="club-last-match-head">
                <span>Zadnja utakmica</span>
                <small>{{ $featuredDate }}</small>
            </div>
            <div class="club-last-score">
                <strong>{{ $featuredHome }}</strong>
                <b>{{ $featuredScore }}</b>
                <strong>{{ $featuredAway }}</strong>
            </div>
            <p>{{ $featuredMatch?->excerpt ?: 'Primjer rezultata zadnje utakmice prikazan uz SportDC widget.' }}</p>
            @if($featuredMatch)
                <a href="{{ route('matches.show', $featuredMatch->slug) }}">Otvori izvještaj</a>
            @endif
        </aside>
    </div>

    <div class="container-news club-layout">
        <div class="club-main-column">
            <section id="rezultati" class="club-section club-embed-card">
                <div class="section-heading sport-heading">
                    <div>
                        <span></span>
                        <h2>Rezultati i utakmice</h2>
                        <p>Rezultati i raspored utakmica FK Posavina</p>
                    </div>
                </div>
                <div class="sportdc-frame-wrap sportdc-frame-wrap--results">
                    <iframe
                        src="https://sportdc.net/embed/results/5919"
                        title="FK Posavina rezultati i raspored - SportDC"
                        frameborder="0"
                        scrolling="no"
                        width="100%"
                        height="270"
                        loading="lazy"
                        referrerpolicy="strict-origin-when-cross-origin"
                        style="height: 270px"></iframe>
                </div>
                <p class="club-source">Izvor takmičarskih podataka: SportDC.</p>
            </section>

            <x-ad-slot position="club_after_results" />
        </div>

        <aside class="club-sidebar">
            <x-sidebar :popular="$popular" />
        </aside>
    </div>

    <section id="galerije" class="container-news club-section club-full-section">
        <div class="section-heading">
            <div>
                <span></span>
                <h2>Foto galerije utakmica</h2>
                <p>Utakmice kojima je redakcija dodala fotografije u admin panelu</p>
            </div>
            <a href="{{ route('categories.show', 'sport') }}">Sve sportske vijesti</a>
        </div>

        @if($galleryMatches->isNotEmpty())
            <div class="match-card-grid">
                @foreach($galleryMatches as $match)
                    <a class="match-card" href="{{ route('matches.show', $match->slug) }}">
                        <div class="match-card-cover">
                            @if($match->cover_image)
                                <img src="{{ asset('storage/'.$match->cover_image) }}" alt="{{ $match->title }}" loading="lazy">
                            @else
                                <span>{{ $match->home_team }} - {{ $match->away_team }}</span>
                            @endif
                        </div>
                        <div>
                            <small>{{ optional($match->played_at)->format('d.m.Y.') }} · {{ $match->media->count() }} slika</small>
                            <strong>{{ $match->title }}</strong>
                            <span>{{ $match->home_team }} {{ $match->score() }} {{ $match->away_team }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="sample-gallery-card">
                <div class="sample-gallery-copy">
                    <span>Primjer galerije koju je dodao admin</span>
                    <h2>FK Posavina - Sloga 3:0</h2>
                    <p>Ovako će izgledati utakmica kada admin u CMS-u doda naslov, rezultat i fotografije utakmice.</p>
                </div>
                <div class="sample-gallery-images">
                    @foreach($sampleGalleryImages as $index => $image)
                        <figure>
                            <img src="{{ $image }}" alt="Primjer fotografije utakmice {{ $index + 1 }}" loading="lazy">
                        </figure>
                    @endforeach
                </div>
            </div>
        @endif

        @if($sportPosts->isNotEmpty())
            <div class="section-heading" style="margin-top:28px;">
                <div><span></span><h2>Sportske vijesti</h2></div>
            </div>
            <div class="club-post-grid">
                @foreach($sportPosts as $post)
                    <x-news-card :post="$post" />
                @endforeach
            </div>
        @else
            <div class="empty-state">
                <h2>Uskoro sportske vijesti</h2>
                <p>Sportska redakcija može dodavati izvještaje, fotografije i poveznice na utakmice direktno iz CMS-a.</p>
            </div>
        @endif
    </section>
</section>
@endsection
