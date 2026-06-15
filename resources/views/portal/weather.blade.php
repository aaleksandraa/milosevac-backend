@extends('layouts.portal')

@section('content')
<section class="weather-page">
    <div class="container-news">
        @if(empty($forecast['current']) && empty($forecast['daily']))
            <div class="weather-empty">
                <h1>Vrijeme u Miloševcu</h1>
                <p>{{ $forecast['message'] ?? 'Prognoza trenutno nije dostupna. Pokušajte ponovo za nekoliko minuta.' }}</p>
            </div>
        @else
            <div class="weather-hero">
                <div>
                    <p class="weather-kicker">Miloševac, Modriča, Republika Srpska</p>
                    <h1>Vrijeme u Miloševcu</h1>
                    <p>Trenutni uslovi i sedmodnevna prognoza za lokalno područje.</p>
                </div>
                @if($forecast['current'])
                    <div class="current-weather-card">
                        <x-weather-icon :type="$forecast['current']['icon']" />
                        <strong>{{ $forecast['current']['temperature'] }}°C</strong>
                        <span>{{ $forecast['current']['weather_text'] }}</span>
                        <small>Osjećaj {{ $forecast['current']['feels_like'] }}°C</small>
                    </div>
                @endif
            </div>

            @if($forecast['current'])
                <div class="weather-metrics">
                    <div><span>Vlažnost</span><strong>{{ $forecast['current']['humidity'] ?? '-' }}%</strong></div>
                    <div><span>Vjetar</span><strong>{{ $forecast['current']['wind_speed'] ?? '-' }} km/h</strong></div>
                    <div><span>Padavine</span><strong>{{ $forecast['current']['precipitation'] ?? 0 }} mm</strong></div>
                    <div><span>Ažurirano</span><strong>{{ $forecast['updated_at'] ? substr($forecast['updated_at'], 11, 5) : '-' }}</strong></div>
                </div>
            @endif

            <section class="weather-section">
                <div class="section-heading">
                    <div><span></span><h2>Prognoza po danima</h2></div>
                </div>
                <div class="daily-weather-grid">
                    @foreach($forecast['daily'] as $day)
                        <article class="daily-weather-card">
                            <div>
                                <strong>{{ $day['label'] }}</strong>
                                <small>{{ \Carbon\Carbon::parse($day['date'])->format('d.m.') }}</small>
                            </div>
                            <x-weather-icon :type="$day['icon']" />
                            <p>{{ $day['day_text'] }}</p>
                            <div class="weather-temp-row"><b>{{ $day['max'] }}°</b><span>{{ $day['min'] }}°</span></div>
                            <small>Kiša {{ $day['rain_probability'] ?? 0 }}% · Vjetar {{ $day['wind_speed'] ?? '-' }} km/h</small>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="weather-section">
                <div class="section-heading">
                    <div><span></span><h2>Naredna 24 sata</h2></div>
                </div>
                <div class="hourly-weather-strip">
                    @foreach($forecast['hourly'] as $hour)
                        <div class="hourly-weather-card">
                            <span>{{ $hour['time'] }}</span>
                            <x-weather-icon :type="$hour['icon']" />
                            <strong>{{ $hour['temperature'] }}°</strong>
                            <small>{{ $hour['rain_probability'] ?? 0 }}%</small>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</section>
@endsection
