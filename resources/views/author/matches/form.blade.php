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
            <h2>Galerija</h2>
            <x-gallery-uploader
                context="match"
                :upload-url="route('author.gallery-uploads.store')"
                :discard-template="route('author.gallery-uploads.destroy', ['media' => '__MEDIA__'])"
                :items="$match->exists ? $match->media : collect()"
                :max-kb="8192"
            />
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
