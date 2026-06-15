@props(['position'])
@php
    $settings = cache()->remember('settings.ads', 600, fn () => \App\Models\Setting::where('key', 'ad_settings')->first()?->value ?? []);
    $slot = data_get($settings, "slots.$position");
    $google = data_get($settings, 'google', []);
    $type = data_get($slot, 'type', 'text');
    $display = data_get($slot, 'display', 'standard');
    $hasContent = match ($type) {
        'google' => data_get($google, 'enabled') && data_get($google, 'client_id') && data_get($slot, 'google_slot'),
        'image' => data_get($slot, 'image_path'),
        'text' => data_get($slot, 'title') || data_get($slot, 'text') || data_get($slot, 'link_url'),
        default => false,
    };
    $isEnabled = data_get($slot, 'enabled') && $hasContent;
@endphp

@if($isEnabled)
    <section class="ad-slot ad-slot-{{ $position }} ad-slot-{{ $type }} ad-slot-{{ $display }}" aria-label="Oglas">
        <span>Oglas</span>
        @if($type === 'google')
            <ins class="adsbygoogle"
                 style="display:block"
                 data-ad-client="{{ data_get($google, 'client_id') }}"
                 data-ad-slot="{{ data_get($slot, 'google_slot') }}"
                 data-ad-format="{{ $display === 'inline' ? 'fluid' : 'auto' }}"
                 data-full-width-responsive="true"></ins>
        @elseif($type === 'image' && data_get($slot, 'image_path'))
            <a href="{{ data_get($slot, 'link_url') ?: '#' }}" @if(data_get($slot, 'link_url')) target="_blank" rel="noopener sponsored" @endif>
                <img src="{{ asset('storage/'.data_get($slot, 'image_path')) }}" alt="{{ data_get($slot, 'title') ?: 'Oglas' }}" loading="lazy">
            </a>
        @else
            @if(data_get($slot, 'title'))<strong>{{ data_get($slot, 'title') }}</strong>@endif
            @if(data_get($slot, 'text'))<p>{{ data_get($slot, 'text') }}</p>@endif
            @if(data_get($slot, 'link_url'))
                <a href="{{ data_get($slot, 'link_url') }}" target="_blank" rel="noopener sponsored">{{ data_get($slot, 'link_label') ?: 'Saznaj više' }}</a>
            @endif
        @endif
    </section>
@endif
