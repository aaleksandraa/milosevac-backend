@props(['position'])
@php
    $settings = cache()->remember('settings.ads', 600, fn () => \App\Models\Setting::where('key', 'ad_settings')->first()?->value ?? []);
    $slot = data_get($settings, "slots.$position");
    $google = data_get($settings, 'google', []);
    $clientId = data_get($google, 'client_id') ?: 'ca-pub-1407310093643341';
    $isEnabled = data_get($google, 'enabled') && data_get($slot, 'enabled') && $clientId;
@endphp

@if($isEnabled)
    <section class="ad-slot ad-slot-{{ $position }} ad-slot-google" aria-label="Oglas">
        <span>Oglas</span>
        <ins class="adsbygoogle"
             style="display:block"
             data-ad-client="{{ $clientId }}"
             data-ad-format="auto"
             data-full-width-responsive="true"
             data-ad-position="{{ $position }}"></ins>
    </section>
@endif
