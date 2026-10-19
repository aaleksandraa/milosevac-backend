@props([
    'context',
    'uploadUrl',
    'discardTemplate',
    'items' => [],
    'maxKb' => 8192,
])

@php
    use App\Models\Media;
    use App\Support\StagedGallery;

    $existing = collect($items);
    $oldIds = collect(old('staged_gallery', []))->map(fn ($id) => (int) $id)->filter()->unique()->values();
    $knownIds = $existing->pluck('id')->map(fn ($id) => (int) $id);
    $restored = $oldIds->isEmpty()
        ? collect()
        : Media::query()
            ->whereIn('id', $oldIds->diff($knownIds))
            ->where('user_id', auth()->id())
            ->whereIn('media_type', [StagedGallery::MATCH, StagedGallery::POST])
            ->whereNull('post_id')
            ->get();

    $cards = $existing->concat($restored)->keyBy(fn ($media) => (int) $media->id);
    $order = collect(old('gallery_order', []))->map(fn ($id) => (int) $id)->filter();
    if ($order->isNotEmpty()) {
        $ordered = $order->map(fn ($id) => $cards->get($id))->filter();
        $cards = $ordered->concat($cards->except($order->all())->values());
    } else {
        $cards = $cards->values();
    }

    $maxMb = (int) round($maxKb / 1024);
@endphp

<div
    class="gallery-uploader"
    data-gallery-upload
    data-upload-url="{{ $uploadUrl }}"
    data-discard-template="{{ $discardTemplate }}"
    data-gallery-context="{{ $context }}"
    data-max-bytes="{{ $maxKb * 1024 }}"
>
    <div class="field">
        <label>Dodaj fotografije</label>
        <input type="file" data-gallery-input accept="image/png,image/jpeg,image/webp" multiple>
        <small>Fotografije se učitavaju odmah, čim ih odaberete (najviše {{ $maxMb }} MB po slici). Sačekajte da traka dođe do 100%, pa tek onda sačuvajte.@if($context === 'match') Svaka fotografija utakmice dobija aktivni watermark ako je podešen.@endif</small>
    </div>
    <p class="gallery-upload-fallback" data-gallery-fallback>Učitavanje fotografija nije spremno. Osvježite stranicu. Ako poruka ostane, fotografije se ne smiju slati zajedno sa utakmicom.</p>
    <div class="gallery-upload-panel" data-gallery-panel hidden>
        <div class="gallery-upload-panel-row">
            <strong data-gallery-status>Priprema fotografija…</strong>
            <span data-gallery-count>0 / 0</span>
        </div>
        <div class="gallery-upload-track" aria-hidden="true"><span data-gallery-bar></span></div>
    </div>
    <div class="admin-gallery-preview is-sortable {{ $cards->isNotEmpty() ? 'has-items' : '' }}" data-gallery-sortable>
        @foreach($cards as $media)
            @php
                $isStaged = str_starts_with((string) $media->media_type, 'staged_');
                $storedCaption = $context === 'match'
                    ? (($media->pivot?->caption && $media->pivot->caption !== $media->alt_text) ? $media->pivot->caption : '')
                    : (string) ($media->caption ?? '');
                $caption = old('gallery_captions.'.$media->id, $storedCaption);
            @endphp
            <figure class="admin-gallery-item" draggable="true" data-gallery-item @if($isStaged) data-staged="1" @endif>
                @if($isStaged)
                    <input type="hidden" name="staged_gallery[]" value="{{ $media->id }}">
                @endif
                <input type="hidden" name="gallery_order[]" value="{{ $media->id }}" data-gallery-order>
                <img src="{{ asset('storage/'.$media->path) }}" alt="{{ $media->alt_text }}">
                <figcaption>
                    <label class="gallery-caption-field">
                        Opis
                        <input name="gallery_captions[{{ $media->id }}]" value="{{ $caption }}" placeholder="Opis fotografije">
                    </label>
                    <span class="gallery-item-actions">
                        <button class="gallery-drag-handle" type="button" data-gallery-handle aria-label="Pomjeri sliku">Pomjeri</button>
                        @if($isStaged)
                            <button class="gallery-delete-control" type="button" data-gallery-remove>Ukloni</button>
                        @else
                            <label class="gallery-delete-control">
                                <input type="checkbox" name="delete_gallery[]" value="{{ $media->id }}" data-gallery-delete @checked(in_array($media->id, array_map('intval', (array) old('delete_gallery', []))))>
                                Obrisi
                            </label>
                        @endif
                    </span>
                </figcaption>
            </figure>
        @endforeach
    </div>
</div>
@once
    <style>
        .gallery-upload-fallback { margin: 0 0 10px; padding: 10px 12px; border-radius: 8px; background: #fff4f4; color: #9f1d1d; font-size: 14px; font-weight: 800; }
        .gallery-uploader.is-ready .gallery-upload-fallback { display: none; }
        .gallery-upload-panel { margin: 0 0 12px; padding: 12px 14px; border: 1px solid #eadfdf; border-radius: 10px; background: #fff; }
        .gallery-upload-panel.is-error { border-color: #9f1d1d; background: #fff4f4; }
        .gallery-upload-panel.is-done { border-color: #b8e8c7; background: #f3fbf6; }
        .gallery-upload-panel-row { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; margin-bottom: 8px; }
        .gallery-upload-panel-row strong { font-size: 15px; }
        .gallery-upload-panel-row span { font-size: 13px; font-weight: 900; color: #6e6464; }
        .gallery-upload-track, .gallery-upload-mini { height: 10px; border-radius: 999px; background: #eadfdf; overflow: hidden; }
        .gallery-upload-track > span, .gallery-upload-mini > span { display: block; height: 100%; width: 0; background: #9f1d1d; }
        .gallery-upload-overlay { position: absolute; inset: auto 0 0; display: grid; gap: 6px; padding: 8px; background: rgba(32, 24, 24, .78); color: #fff; font-size: 12px; font-weight: 900; }
        .gallery-upload-name { display: block; padding: 8px; font-size: 12px; font-weight: 800; word-break: break-word; }
        .gallery-uploader .admin-gallery-preview { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
        .gallery-uploader .admin-gallery-preview:not(.has-items) { display: none; }
        .gallery-uploader .admin-gallery-item { position: relative; }
        .gallery-uploader .admin-gallery-item.is-uploading img[src=""] { display: none; }
    </style>
    @push('scripts')
        <script src="{{ asset('js/gallery-upload.js') }}?v={{ filemtime(public_path('js/gallery-upload.js')) }}"></script>
    @endpush
@endonce
