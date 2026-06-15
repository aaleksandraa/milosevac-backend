@extends('layouts.author')

@section('content')
<h1>{{ $match->exists ? 'Uredi utakmicu' : 'Nova utakmica' }}</h1>
<form method="post" enctype="multipart/form-data" action="{{ $match->exists ? route('author.matches.update', $match) : route('author.matches.store') }}">
    @csrf
    @if($match->exists) @method('put') @endif
    <div class="form-grid">
        <section class="panel">
            <div class="field"><label>Naslov</label><input name="title" value="{{ old('title', $match->title) }}" required></div>
            <div class="field"><label>Slug</label><input name="slug" value="{{ old('slug', $match->slug) }}"></div>
            <div class="field"><label>Kratak tekst</label><textarea name="excerpt" rows="3">{{ old('excerpt', $match->excerpt) }}</textarea></div>
            <div class="field"><label>Izvještaj utakmice</label><textarea name="content" rows="12" data-rich-editor>{{ old('content', $match->content) }}</textarea></div>
            <div class="field"><label>Dodaj galeriju</label><input type="file" name="gallery_images[]" accept="image/png,image/jpeg,image/webp" multiple></div>
            @if($match->exists && $match->media->isNotEmpty())
                <div class="admin-gallery-preview is-sortable" data-gallery-sortable>
                    @foreach($match->media as $media)
                        <figure class="admin-gallery-item" draggable="true" data-gallery-item>
                            <input type="hidden" name="gallery_order[]" value="{{ $media->id }}" data-gallery-order>
                            <img src="{{ asset('storage/'.$media->path) }}" alt="{{ $media->alt_text }}">
                            <figcaption>
                                <label class="gallery-caption-field">
                                    Opis
                                    <input name="gallery_captions[{{ $media->id }}]" value="{{ old('gallery_captions.'.$media->id, $media->pivot->caption !== $media->alt_text ? $media->pivot->caption : '') }}" placeholder="Opis fotografije">
                                </label>
                                <span class="gallery-item-actions">
                                    <button class="gallery-drag-handle" type="button" data-gallery-handle aria-label="Pomjeri sliku">Pomjeri</button>
                                    <label class="gallery-delete-control">
                                        <input type="checkbox" name="delete_gallery[]" value="{{ $media->id }}" data-gallery-delete>
                                        Obrisi
                                    </label>
                                </span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif
        </section>
        <aside class="panel">
            <div class="field"><label>Status</label><select name="status">
                <option value="draft" @selected(old('status', $match->status) === 'draft')>draft</option>
                <option value="pending_review" @selected(old('status', $match->status) === 'pending_review')>pending review</option>
                @if(auth()->user()->canPublishDirectly())
                    <option value="published" @selected(old('status', $match->status) === 'published')>published</option>
                    <option value="scheduled" @selected(old('status', $match->status) === 'scheduled')>scheduled</option>
                @endif
            </select></div>
            <div class="field"><label>Domaćin</label><input name="home_team" value="{{ old('home_team', $match->home_team ?: 'FK Posavina') }}" required></div>
            <div class="field"><label>Gost</label><input name="away_team" value="{{ old('away_team', $match->away_team) }}" required></div>
            <div class="field"><label>Rezultat domaćin</label><input type="number" min="0" max="99" name="home_score" value="{{ old('home_score', $match->home_score) }}"></div>
            <div class="field"><label>Rezultat gost</label><input type="number" min="0" max="99" name="away_score" value="{{ old('away_score', $match->away_score) }}"></div>
            <div class="field"><label>Datum utakmice</label><input name="played_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 16:00" value="{{ \App\Support\DateFormat::input(old('played_at', $match->played_at)) }}"></div>
            <div class="field"><label>Lokacija</label><input name="venue" value="{{ old('venue', $match->venue) }}"></div>
            <div class="field"><label>Naslovna slika</label><input type="file" name="cover_image" accept="image/png,image/jpeg,image/webp"></div>
            <button class="btn" type="submit">Sačuvaj utakmicu</button>
        </aside>
    </div>
</form>
@endsection
