@extends('layouts.admin')

@section('content')
<h1>Oglasi i Google AdSense</h1>
<form method="post" action="{{ route('admin.ads.update') }}">
    @csrf
    <section class="panel ad-admin-intro">
        <div>
            <h2>Google AdSense povezivanje</h2>
            <p>
                Publisher ID se unosi jednom. Za svaku poziciju samo izaberite da li je aktivna,
                a Google automatski popunjava oglasni prostor.
            </p>
        </div>
        <label class="ad-toggle">
            <input type="checkbox" name="google[enabled]" value="1" @checked(data_get($settings, 'google.enabled'))>
            <span>Ukljuci Google oglase</span>
        </label>
        <div class="field">
            <label>Google publisher/client ID</label>
            <input
                name="google[client_id]"
                placeholder="ca-pub-xxxxxxxxxxxxxxxx"
                value="{{ old('google.client_id', data_get($settings, 'google.client_id', 'ca-pub-1407310093643341')) }}">
            <small>Za Milosevac je postavljen <strong>ca-pub-1407310093643341</strong>. Oglasi se ucitavaju tek nakon korisnickog pristanka na marketing.</small>
            @error('google.client_id')<small class="field-error">{{ $message }}</small>@enderror
        </div>
    </section>

    <div class="ad-admin-grid">
        @foreach($positions as $key => $label)
            @php($slot = data_get($settings, "slots.$key", []))
            <section class="panel ad-admin-card">
                <div class="ad-card-head">
                    <div>
                        <h2>{{ $label }}</h2>
                        <small>{{ $key }}</small>
                    </div>
                    <label class="ad-toggle">
                        <input type="checkbox" name="slots[{{ $key }}][enabled]" value="1" @checked(old("slots.$key.enabled", data_get($slot, 'enabled')))>
                        <span>Aktivno</span>
                    </label>
                </div>
                <small class="ad-field-note">Ako je pozicija aktivna, portal prikazuje AdSense prostor na toj lokaciji. Nema rucnog unosa teksta, slike ili slot ID-a.</small>
            </section>
        @endforeach
    </div>

    <div style="margin-top:18px;"><button class="btn" type="submit">Sacuvaj oglase</button></div>
</form>
@endsection
