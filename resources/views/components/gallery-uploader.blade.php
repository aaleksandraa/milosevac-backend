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
        <input type="file" name="gallery_images[]" data-gallery-input accept="image/png,image/jpeg,image/webp" multiple>
        <small>Fotografije se učitavaju odmah, jedna po jedna (najviše {{ $maxMb }} MB). Možete dodati i nekoliko stotina — sačekajte da sve stignu, pa tek onda sačuvajte.@if($context === 'match') Svaka fotografija utakmice dobija aktivni watermark ako je podešen.@endif</small>
    </div>
    <p class="gallery-upload-status" data-gallery-status hidden></p>
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
