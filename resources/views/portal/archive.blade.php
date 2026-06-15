@extends('layouts.portal')

@php
    $sortOptions = [
        'new' => 'Najnovije',
        'old' => 'Najstarije',
        'popular' => 'Popularno',
        'reading' => 'Najduže čitanje',
    ];
    $activeTags = collect($activeTags ?? []);
    $tagOptions = collect($tagOptions ?? []);
    $archiveType = $archiveType ?? 'archive';
    $hasFilters = $activeTags->isNotEmpty() || ($sort ?? 'new') !== 'new';
@endphp

@section('content')
<section class="container-news archive-header archive-hero">
    <nav class="archive-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('home') }}">Naslovna</a>
        <span>/</span>
        <span>{{ $title }}</span>
    </nav>
    <span class="archive-kicker">{{ $archiveType === 'category' ? 'Kategorija' : ($archiveType === 'tag' ? 'Tag' : ($archiveType === 'author' ? 'Autor' : 'Arhiva')) }}</span>
    <h1>{{ $title }}</h1>
@if($description)<p>{{ $description }}</p>@endif
</section>

<x-ad-slot position="archive_top" />

@if($archiveType !== 'search')
    <section class="container-news archive-filters archive-sort" aria-label="Sortiranje arhive">
        <div class="filter-panel">
            <details class="filter-details" open>
                <summary>
                    <span>Sortiraj <small>{{ $sortOptions[$sort] ?? 'Najnovije' }}</small></span>
                    <b aria-hidden="true">⌄</b>
                </summary>
                <div class="filter-options">
                    @foreach($sortOptions as $key => $label)
                        <a
                            class="filter-chip {{ ($sort ?? 'new') === $key ? 'is-active' : '' }}"
                            href="{{ request()->fullUrlWithQuery(['sort' => $key, 'page' => null]) }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </details>
            @if(($sort ?? 'new') !== 'new')
                <div class="filter-reset">
                    <span>Aktivni filteri mijenjaju prikaz ove arhive.</span>
                    <a href="{{ url()->current() }}">Resetuj</a>
                </div>
            @endif
        </div>
    </section>
@endif

<section class="section archive-content">
    <div class="container-news archive-main">
        <div>
            <div class="post-list archive-list">
                @forelse($posts as $post)
                    <x-news-card :post="$post" wide />
                    @if($loop->iteration === 4)
                        <x-ad-slot position="archive_mid_feed" />
                    @endif
                @empty
                    <div class="empty-state">
                        <h2>Nema pronađenih članaka.</h2>
                        @if($hasFilters)
                            <a class="btn secondary" href="{{ url()->current() }}">Ukloni filtere</a>
                        @endif
                    </div>
                @endforelse
            </div>
            {{ $posts->links('vendor.pagination.clean') }}
        </div>
    </div>
</section>

@if($archiveType !== 'search' && $tagOptions->isNotEmpty())
    <section class="container-news archive-tags-bottom" aria-label="Tagovi arhive">
        <div class="filter-panel">
            <details class="filter-details" open>
                <summary>
                    <span>Tagovi @if($activeTags->isNotEmpty())<small>{{ $activeTags->count() }} aktivno</small>@endif</span>
                    <b aria-hidden="true">⌄</b>
                </summary>
                <div class="filter-options tag-options">
                    @foreach($tagOptions as $tag)
                        @php
                            $nextTags = $activeTags->contains($tag->slug)
                                ? $activeTags->reject(fn ($item) => $item === $tag->slug)->values()
                                : $activeTags->concat([$tag->slug])->unique()->values();
                        @endphp
                        <a
                            class="filter-chip tag-chip {{ $activeTags->contains($tag->slug) ? 'is-active' : '' }}"
                            href="{{ request()->fullUrlWithQuery(['tags' => $nextTags->implode(','), 'page' => null]) }}">
                            #{{ $tag->name }}
                        </a>
                    @endforeach
                </div>
            </details>

            @if($hasFilters)
                <div class="filter-reset">
                    <span>Aktivni filteri mijenjaju prikaz ove arhive.</span>
                    <a href="{{ url()->current() }}">Resetuj</a>
                </div>
            @endif
        </div>
    </section>
@endif
@endsection
