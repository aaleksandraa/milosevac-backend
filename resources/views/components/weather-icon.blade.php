@props(['type' => 'partly'])

@php
    $icon = match ($type) {
        'sun' => '☀',
        'cloud' => '☁',
        'rain' => '☂',
        'snow' => '*',
        'storm' => '⚡',
        default => '◐',
    };
@endphp

<span class="weather-icon" aria-hidden="true">{{ $icon }}</span>
