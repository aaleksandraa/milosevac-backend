@extends('layouts.admin')
@section('content')
<h1>{{ $post->exists ? 'Uredi članak' : 'Novi članak' }}</h1>
<form method="post" enctype="multipart/form-data" action="{{ $post->exists ? route('admin.posts.update', $post) : route('admin.posts.store') }}">
    @csrf
    @if($post->exists) @method('put') @endif
    <div class="form-grid">
        <section class="panel">
            <div class="field"><label>Naslov</label><input name="title" value="{{ old('title', $post->title) }}" data-slug-source="#slug" required></div>
            <div class="field"><label>Slug</label><input id="slug" name="slug" value="{{ old('slug', $post->slug) }}"></div>
            <div class="field"><label>Excerpt</label><textarea name="excerpt" rows="3">{{ old('excerpt', $post->excerpt) }}</textarea></div>
            <div class="field">
                <label>Sadržaj</label>
                <textarea name="content" rows="18" data-rich-editor required>{{ old('content', $post->content) }}</textarea>
            </div>
            <div class="field">
                <label>Slike u sadrzaju</label>
                <input type="file" name="content_images[]" accept="image/png,image/jpeg,image/webp" multiple>
                <small>Nove slike se optimizuju u WebP i dodaju na kraj teksta, pa ih nakon snimanja mozete premjestiti u editoru.</small>
            </div>
            <h2>Galerija clanka</h2>
            <x-gallery-uploader
                context="post"
                :upload-url="route('admin.gallery-uploads.store')"
                :discard-template="route('admin.gallery-uploads.destroy', ['media' => '__MEDIA__'])"
                :items="$post->exists ? $post->galleryMedia : collect()"
                :max-kb="12288"
            />
            <h2>SEO preview</h2>
            <div class="panel">
                <strong>{{ old('meta_title', $post->meta_title ?: $post->title ?: 'SEO naslov') }}</strong>
                <p>{{ old('meta_description', $post->meta_description ?: $post->excerpt ?: 'Meta opis za Google i social dijeljenje.') }}</p>
                <small>{{ old('canonical_url', $post->canonical_url ?: url('/clanak/'.($post->slug ?: 'slug'))) }}</small>
            </div>
            <div class="field"><label>Meta title</label><input name="meta_title" value="{{ old('meta_title', $post->meta_title) }}"></div>
            <div class="field"><label>Meta description</label><textarea name="meta_description" rows="3">{{ old('meta_description', $post->meta_description) }}</textarea></div>
            <div class="field"><label>Canonical URL</label><input name="canonical_url" value="{{ old('canonical_url', $post->canonical_url) }}"></div>
        </section>
        <aside class="panel">
            <div class="field"><label>Status</label><select name="status">
                @foreach(['draft','pending_review','published','scheduled','archived'] as $status)
                    <option value="{{ $status }}" @selected(old('status', $post->status) === $status)>{{ str_replace('_', ' ', $status) }}</option>
                @endforeach
            </select></div>
            <div class="field"><label>Autor</label><select name="author_id">@foreach($authors as $author)<option value="{{ $author->id }}" @selected(old('author_id', $post->author_id) == $author->id)>{{ $author->name }}</option>@endforeach</select></div>
            @php($selectedCategories = collect(old('categories', $post->categories->pluck('id')->all() ?: ($post->category_id ? [$post->category_id] : [])))->map(fn ($id) => (int) $id))
            <div class="field">
                <label>Kategorije</label>
                <div class="category-check-grid">
                    @foreach($categories as $category)
                        <label class="category-check">
                            <input type="checkbox" name="categories[]" value="{{ $category->id }}" @checked($selectedCategories->contains((int) $category->id))>
                            <span>
                                <strong>{{ $category->name }}</strong>
                                @if($category->parent)<small>{{ $category->parent->name }}</small>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
                <small>Izaberite jednu ili vise kategorija. Prva oznacena po redoslijedu liste ostaje primarna.</small>
            </div>
            <div class="field"><label>Tagovi</label><textarea name="tags_text" rows="4" placeholder="npr. milosevac, sport, najava">{{ old('tags_text', $post->tags->pluck('name')->implode(', ')) }}</textarea><small>Upisite tagove odvojene zarezom ili novim redom.</small></div>
            <div class="field" data-news-fields><label>Oznaka vijesti</label><select name="label">
                <option value="">Bez posebne oznake</option>
                @foreach(['hitno' => 'Hitno', 'obavijest' => 'Obavijest', 'info' => 'Info', 'najava' => 'Najava'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('label', $post->label) === $value)>{{ $label }}</option>
                @endforeach
            </select></div>
            <div class="field"><label>Objavljeno</label><input name="published_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 07:00" value="{{ \App\Support\DateFormat::input(old('published_at', $post->published_at)) }}"></div>
            <div class="field"><label>Zakazano</label><input name="scheduled_at" data-date-picker inputmode="numeric" placeholder="24.02.2026. 07:00" value="{{ \App\Support\DateFormat::input(old('scheduled_at', $post->scheduled_at)) }}"></div>
            <div class="field"><label>Naslovna slika</label><input type="file" name="featured_image" accept="image/png,image/jpeg,image/webp"></div>
            <div class="field"><label>Alt tekst slike</label><input name="featured_image_alt" value="{{ old('featured_image_alt', $post->featured_image_alt) }}"></div>
            <div class="field"><label>OG image</label><input type="file" name="og_image" accept="image/png,image/jpeg,image/webp"></div>
            <label><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $post->is_featured))> Izdvojeno</label><br>
            <label><input type="checkbox" name="is_breaking" value="1" @checked(old('is_breaking', $post->is_breaking))> Breaking news</label>
            <div style="margin-top:16px"><button class="btn" type="submit">Sačuvaj</button></div>
        </aside>
    </div>
</form>
@endsection
