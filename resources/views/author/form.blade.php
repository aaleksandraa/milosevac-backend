@extends('layouts.author')
@section('content')
<h1>{{ $post->exists ? 'Uredi članak' : 'Novi članak' }}</h1>
<form method="post" enctype="multipart/form-data" action="{{ $post->exists ? route('author.posts.update', $post) : route('author.posts.store') }}">
    @csrf
    @if($post->exists) @method('put') @endif
    <div class="form-grid">
        <section class="panel">
            <div class="field"><label>Naslov</label><input name="title" value="{{ old('title', $post->title) }}" data-slug-source="#slug" required></div>
            <div class="field"><label>Slug</label><input id="slug" name="slug" value="{{ old('slug', $post->slug) }}"></div>
            <div class="field"><label>Excerpt</label><textarea name="excerpt" rows="3">{{ old('excerpt', $post->excerpt) }}</textarea></div>
            <div class="field"><label>Sadržaj</label><textarea name="content" rows="18" data-rich-editor required>{{ old('content', $post->content) }}</textarea></div>
            <div class="field">
                <label>Slike u sadrzaju</label>
                <input type="file" name="content_images[]" accept="image/png,image/jpeg,image/webp" multiple>
                <small>Nove slike se optimizuju u WebP i dodaju na kraj teksta, pa ih nakon snimanja mozete premjestiti u editoru.</small>
            </div>
            <h2>Galerija clanka</h2>
            <div class="field">
                <label>Dodaj slike u galeriju</label>
                <input type="file" name="gallery_images[]" accept="image/png,image/jpeg,image/webp" multiple>
                <small>Galerijske slike se automatski smanjuju i cuvaju kao WebP varijante.</small>
            </div>
            @if($post->exists && $post->galleryMedia->isNotEmpty())
                <div class="admin-gallery-preview is-sortable" data-gallery-sortable>
                    @foreach($post->galleryMedia as $media)
                        <figure class="admin-gallery-item" draggable="true" data-gallery-item>
                            <input type="hidden" name="gallery_order[]" value="{{ $media->id }}" data-gallery-order>
                            <img src="{{ asset('storage/'.$media->path) }}" alt="{{ $media->alt_text }}">
                            <figcaption>
                                <label class="gallery-caption-field">
                                    Opis
                                    <input name="gallery_captions[{{ $media->id }}]" value="{{ old('gallery_captions.'.$media->id, $media->caption) }}" placeholder="Opis fotografije">
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
            <h2>SEO preview</h2>
            <div class="panel"><strong>{{ old('meta_title', $post->meta_title ?: $post->title ?: 'SEO naslov') }}</strong><p>{{ old('meta_description', $post->meta_description ?: $post->excerpt ?: 'Kratak opis za pretraživače.') }}</p></div>
            <div class="field"><label>Meta title</label><input name="meta_title" value="{{ old('meta_title', $post->meta_title) }}"></div>
            <div class="field"><label>Meta description</label><textarea name="meta_description">{{ old('meta_description', $post->meta_description) }}</textarea></div>
        </section>
        <aside class="panel">
            <div class="field"><label>Status</label><select name="status">
                <option value="draft" @selected(old('status', $post->status) === 'draft')>draft</option>
                <option value="pending_review" @selected(old('status', $post->status) === 'pending_review')>pending review</option>
                @if(auth()->user()->canPublishDirectly())
                    <option value="published" @selected(old('status', $post->status) === 'published')>published</option>
                    <option value="scheduled" @selected(old('status', $post->status) === 'scheduled')>scheduled</option>
                @endif
            </select></div>
            <div class="field"><label>Kategorija</label><select name="category_id">@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id', $post->category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            <div class="field"><label>Tagovi</label><select name="tags[]" multiple size="8">@foreach($tags as $tag)<option value="{{ $tag->id }}" @selected(collect(old('tags', $post->tags->pluck('id')->all()))->contains($tag->id))>{{ $tag->name }}</option>@endforeach</select></div>
            <div class="field" data-news-fields><label>Oznaka vijesti</label><select name="label">
                <option value="">Bez posebne oznake</option>
                @foreach(['hitno' => 'Hitno', 'obavijest' => 'Obavijest', 'info' => 'Info', 'najava' => 'Najava'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('label', $post->label) === $value)>{{ $label }}</option>
                @endforeach
            </select></div>
            <div class="field"><label>Tip članka</label><select name="service_type" data-content-type>
                <option value="">Vijest</option>
                <option value="power_outage" @selected(old('service_type', $post->service_type) === 'power_outage')>Prekid isporuke električne energije</option>
            </select></div>
            @if(auth()->user()->canPublishDirectly())
                <div class="field"><label>Objavljeno</label><input name="published_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 07:00" value="{{ \App\Support\DateFormat::input(old('published_at', $post->published_at)) }}"></div>
                <div class="field"><label>Zakazano</label><input name="scheduled_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 07:00" value="{{ \App\Support\DateFormat::input(old('scheduled_at', $post->scheduled_at)) }}"></div>
            @endif
            <div class="field" data-power-outage-fields><label>Obavijest aktivna od</label><input name="notice_starts_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 07:00" value="{{ \App\Support\DateFormat::input(old('notice_starts_at', $post->notice_starts_at)) }}"></div>
            <div class="field" data-power-outage-fields><label>Obavijest aktivna do</label><input name="notice_ends_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 09:05" value="{{ \App\Support\DateFormat::input(old('notice_ends_at', $post->notice_ends_at)) }}"></div>
            <div class="field" data-power-outage-fields><label>Termini prekida / dodatni tekst</label><textarea name="notice_schedule" rows="5" placeholder="Utorak, 24.02.2026. godine, od 07:00 do 09:05 - planirani radovi na elektroenergetskim objektima TJ Modriča.">{{ old('notice_schedule', $post->notice_schedule) }}</textarea></div>
            <div class="field"><label>Naslovna slika</label><input type="file" name="featured_image" accept="image/png,image/jpeg,image/webp"></div>
            <div class="field"><label>Alt tekst</label><input name="featured_image_alt" value="{{ old('featured_image_alt', $post->featured_image_alt) }}"></div>
            <button class="btn" type="submit">Sačuvaj</button>
        </aside>
    </div>
</form>
@endsection
