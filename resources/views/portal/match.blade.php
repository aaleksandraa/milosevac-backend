@extends('layouts.portal')

@section('content')
<section class="match-page">
    <div class="container-news match-layout">
        <article class="match-article">
            <nav class="archive-breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Naslovna</a>
                <span>/</span>
                <a href="{{ route('fk-posavina') }}">FK Posavina</a>
            </nav>
            <span class="archive-kicker">Utakmica</span>
            <h1>{{ $match->title }}</h1>
            <div class="match-scoreline">
                <strong>{{ $match->home_team }}</strong>
                <b>{{ $match->score() }}</b>
                <strong>{{ $match->away_team }}</strong>
            </div>
            <div class="meta">
                <a href="{{ route('authors.show', $match->author) }}">{{ $match->author->name }}</a>
                @if($match->played_at)<span>{{ $match->played_at->format('d.m.Y. H:i') }}</span>@endif
                @if($match->venue)<span>{{ $match->venue }}</span>@endif
            </div>
            @if($match->cover_image)
                <figure class="match-cover">
                    <img
                        src="{{ asset('storage/'.$match->cover_image) }}"
                        @if(\App\Support\ImagePipeline::srcset($match->cover_image_responsive)) srcset="{{ \App\Support\ImagePipeline::srcset($match->cover_image_responsive) }}" sizes="(max-width: 900px) 100vw, 760px" @endif
                        alt="{{ $match->title }}"
                        loading="eager"
                        decoding="async">
                </figure>
            @endif
            @if($match->excerpt)<p class="excerpt">{{ $match->excerpt }}</p>@endif
            @if($match->content)
                @php
                    $matchContentParts = preg_split('/(<\/p>)/i', $match->content, 3, PREG_SPLIT_DELIM_CAPTURE);
                    $hasMatchMidAd = count($matchContentParts) >= 3;
                @endphp
                <div class="article-content">
                    @if($hasMatchMidAd)
                        {!! $matchContentParts[0].$matchContentParts[1] !!}
                        <x-ad-slot position="match_mid" />
                        {!! $matchContentParts[2] ?? '' !!}
                    @else
                        {!! $match->content !!}
                    @endif
                </div>
            @endif
            <x-ad-slot position="article_inline" />

            <section class="section">
                @php
                    $galleryInitialLimit = 60;
                    $galleryBatchSize = 60;
                @endphp
                <x-ad-slot position="match_gallery_top" />
                <div class="section-heading sport-heading">
                    <div><span></span><h2>Galerija utakmice</h2><p>{{ $match->media->count() }} fotografija</p></div>
                </div>
                <div class="match-gallery" data-match-gallery data-gallery-batch="{{ $galleryBatchSize }}">
                    @foreach($match->media as $media)
                        @php
                            $displayCaption = $media->pivot->caption && $media->pivot->caption !== $media->alt_text
                                ? $media->pivot->caption
                                : null;
                            $caption = $displayCaption ?: $media->alt_text ?: $match->title;
                            $variants = collect($media->responsive_paths['variants'] ?? []);
                            $thumb = $variants->firstWhere('width', 480) ?: $variants->first() ?: ['path' => $media->path];
                            $full = $variants->sortByDesc('width')->first() ?: ['path' => $media->path];
                            $srcset = $variants->isNotEmpty()
                                ? $variants->map(fn ($variant) => asset('storage/'.$variant['path']).' '.$variant['width'].'w')->implode(', ')
                                : null;
                        @endphp
                        <figure data-match-gallery-item @class(['is-hidden' => $loop->iteration > $galleryInitialLimit])>
                            <button
                                class="match-gallery-trigger"
                                type="button"
                                data-lightbox-image="{{ asset('storage/'.$full['path']) }}"
                                data-lightbox-caption="{{ $caption }}"
                                aria-label="Otvori sliku preko cijelog ekrana">
                                <img
                                    src="{{ asset('storage/'.$thumb['path']) }}"
                                    @if($srcset) srcset="{{ $srcset }}" sizes="(max-width: 700px) 100vw, (max-width: 1100px) 33vw, 280px" @endif
                                    alt="{{ $caption }}"
                                    loading="lazy"
                                    decoding="async">
                            </button>
                            @if($displayCaption)<figcaption>{{ $displayCaption }}</figcaption>@endif
                        </figure>
                    @endforeach
                </div>
                @if($match->media->count() > $galleryInitialLimit)
                    <div class="gallery-load-more">
                        <button class="btn secondary" type="button" data-match-gallery-more>Prikaži još fotografija</button>
                    </div>
                @endif
            </section>
        </article>
    </div>
</section>
@endsection
