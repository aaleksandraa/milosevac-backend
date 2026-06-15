@extends('layouts.admin')

@section('content')
<h1>{{ $match->exists ? 'Uredi utakmicu' : 'Nova utakmica' }}</h1>
<form method="post" enctype="multipart/form-data" action="{{ $match->exists ? route('admin.matches.update', $match) : route('admin.matches.store') }}">
    @csrf
    @if($match->exists) @method('put') @endif
    <div class="form-grid">
        <section class="panel">
            <div class="field"><label>Naslov</label><input name="title" value="{{ old('title', $match->title) }}" required></div>
            <div class="field"><label>Slug</label><input name="slug" value="{{ old('slug', $match->slug) }}"></div>
            <div class="field"><label>Kratak tekst</label><textarea name="excerpt" rows="3">{{ old('excerpt', $match->excerpt) }}</textarea></div>
            <div class="field"><label>Izvještaj utakmice</label><textarea name="content" rows="12" data-rich-editor>{{ old('content', $match->content) }}</textarea></div>

            <h2>Galerija</h2>
            <div class="field"><label>Dodaj slike utakmice</label><input type="file" name="gallery_images[]" accept="image/png,image/jpeg,image/webp" multiple></div>
            <small>Svaka nova slika se obrađuje u WebP varijante i dobija aktivni watermark ako je podešen.</small>
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
                @foreach(['draft','pending_review','published','scheduled','archived'] as $status)
                    <option value="{{ $status }}" @selected(old('status', $match->status) === $status)>{{ str_replace('_', ' ', $status) }}</option>
                @endforeach
            </select></div>
            <div class="field"><label>Autor</label><select name="author_id">@foreach($authors as $author)<option value="{{ $author->id }}" @selected(old('author_id', $match->author_id) == $author->id)>{{ $author->name }}</option>@endforeach</select></div>
            <div class="field"><label>Domaćin</label><input name="home_team" value="{{ old('home_team', $match->home_team ?: 'FK Posavina') }}" required></div>
            <div class="field"><label>Gost</label><input name="away_team" value="{{ old('away_team', $match->away_team) }}" required></div>
            <div class="field"><label>Rezultat domaćin</label><input type="number" min="0" max="99" name="home_score" value="{{ old('home_score', $match->home_score) }}"></div>
            <div class="field"><label>Rezultat gost</label><input type="number" min="0" max="99" name="away_score" value="{{ old('away_score', $match->away_score) }}"></div>
            <div class="field"><label>Datum utakmice</label><input name="played_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 16:00" value="{{ \App\Support\DateFormat::input(old('played_at', $match->played_at)) }}"></div>
            <div class="field"><label>Stadion / lokacija</label><input name="venue" value="{{ old('venue', $match->venue) }}"></div>
            <div class="field"><label>Objavljeno</label><input name="published_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 07:00" value="{{ \App\Support\DateFormat::input(old('published_at', $match->published_at)) }}"></div>
            <div class="field"><label>Zakazano</label><input name="scheduled_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 07:00" value="{{ \App\Support\DateFormat::input(old('scheduled_at', $match->scheduled_at)) }}"></div>
            <div class="field"><label>Naslovna slika utakmice</label><input type="file" name="cover_image" accept="image/png,image/jpeg,image/webp"></div>
            <div class="field"><label>Meta title</label><input name="meta_title" value="{{ old('meta_title', $match->meta_title) }}"></div>
            <div class="field"><label>Meta description</label><textarea name="meta_description" rows="3">{{ old('meta_description', $match->meta_description) }}</textarea></div>
            <button class="btn" type="submit">Sačuvaj utakmicu</button>
        </aside>
    </div>
</form>
@endsection
