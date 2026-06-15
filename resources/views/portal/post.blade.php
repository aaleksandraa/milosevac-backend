@extends('layouts.portal')

@section('content')
<section class="section">
    <div class="container-news grid-main">
        <article class="article">
            <nav class="meta" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Početna</a>
                <span>/</span>
                <a href="{{ route('categories.show', $post->category) }}">{{ $post->category->name }}</a>
            </nav>
            <div class="card-labels">
                @if($post->labelText())
                    <span class="post-label {{ $post->labelClass() }}">{{ $post->labelText() }}</span>
                @endif
                <a class="category-pill" style="background: {{ $post->category->color }}" href="{{ route('categories.show', $post->category) }}">{{ $post->category->name }}</a>
            </div>
            <h1>{{ $post->title }}</h1>
            <p class="excerpt">{{ $post->excerpt }}</p>
            <div class="meta">
                <a href="{{ route('authors.show', $post->author) }}">{{ $post->author->name }}</a>
                <span>Objavljeno {{ optional($post->published_at)->format('d.m.Y. H:i') }}</span>
                <span>Ažurirano {{ $post->updated_at->format('d.m.Y. H:i') }}</span>
                <span>{{ $post->reading_time }} min čitanja</span>
            </div>
            <div class="cover" style="margin: 22px 0;">
                @if($post->featured_image)
                    <img
                        src="{{ asset('storage/'.$post->featured_image) }}"
                        @if(\App\Support\ImagePipeline::srcset($post->featured_image_responsive)) srcset="{{ \App\Support\ImagePipeline::srcset($post->featured_image_responsive) }}" sizes="(max-width: 900px) 100vw, 760px" @endif
                        alt="{{ $post->featured_image_alt ?: $post->title }}"
                        loading="eager"
                        decoding="async">
                @elseif($post->defaultImageUrl())
                    <img
                        src="{{ $post->defaultImageUrl() }}"
                        alt="{{ $post->featured_image_alt ?: $post->title }}"
                        loading="eager"
                        decoding="async">
                @else
                    <span>{{ $post->category->name }}</span>
                @endif
            </div>
            @if($post->notice_schedule || $post->notice_ends_at)
                <div class="notice-detail-box">
                    <strong>{{ $post->service_type === 'power_outage' ? 'Planirani prekid isporuke električne energije' : 'Važna obavijest' }}</strong>
                    @if($post->notice_schedule)
                        <p>{{ $post->notice_schedule }}</p>
                    @endif
                    @if($post->notice_ends_at)
                        <small>Istaknuto do {{ $post->notice_ends_at->format('d.m.Y. H:i') }}</small>
                    @endif
                </div>
            @endif
            <x-ad-slot position="article_top" />
            @php
                $contentParts = preg_split('/(<\/p>)/i', $post->content, 5, PREG_SPLIT_DELIM_CAPTURE);
                $hasMidContentAd = count($contentParts) >= 5;
            @endphp
            <div class="article-content">
                @if($hasMidContentAd)
                    {!! $contentParts[0].$contentParts[1].$contentParts[2].$contentParts[3] !!}
                    <x-ad-slot position="article_mid" />
                    {!! $contentParts[4] ?? '' !!}
                @else
                    {!! $post->content !!}
                @endif
            </div>
            <x-ad-slot position="article_inline" />
            <div class="tags">
                @foreach($post->tags as $tag)
                    <a class="tag" href="{{ route('tags.show', $tag) }}">#{{ $tag->name }}</a>
                @endforeach
            </div>
            <section class="section">
                <h2 class="section-title">Povezani članci</h2>
                <div class="post-list">
                    @foreach($related as $item)
                        <x-news-card :post="$item" />
                    @endforeach
                </div>
            </section>
        </article>
        <x-sidebar :popular="$popular" />
    </div>
</section>
@endsection
