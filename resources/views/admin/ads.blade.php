@extends('layouts.admin')

@section('content')
<h1>Oglasi i Google Ads</h1>
<form method="post" enctype="multipart/form-data" action="{{ route('admin.ads.update') }}">
    @csrf
    <section class="panel ad-admin-intro">
        <div>
            <h2>Google AdSense povezivanje</h2>
            <p>
                Unesite AdSense publisher ID jednom, a zatim za svaku poziciju upišite njen Google slot ID.
                Google skripta se na portalu učitava tek nakon korisničkog pristanka na marketing/oglase.
            </p>
        </div>
        <label class="ad-toggle">
            <input type="checkbox" name="google[enabled]" value="1" @checked(data_get($settings, 'google.enabled'))>
            <span>Uključi Google oglase</span>
        </label>
        <div class="field">
            <label>Google publisher/client ID</label>
            <input
                name="google[client_id]"
                placeholder="ca-pub-xxxxxxxxxxxxxxxx"
                value="{{ old('google.client_id', data_get($settings, 'google.client_id')) }}">
            <small>Format je npr. <strong>ca-pub-1234567890123456</strong>. Nalazi se u Google AdSense nalogu.</small>
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
                <div class="field">
                    <label>Tip oglasa</label>
                    <select name="slots[{{ $key }}][type]">
                        @foreach(['text' => 'Tekstualni/native', 'image' => 'Slikovni banner', 'google' => 'Google slot'] as $type => $typeLabel)
                            <option value="{{ $type }}" @selected(old("slots.$key.type", data_get($slot, 'type', 'text')) === $type)>{{ $typeLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>Prikaz</label>
                    <select name="slots[{{ $key }}][display]">
                        @foreach(['standard' => 'Standardni blok', 'compact' => 'Manji tekstualni/native', 'inline' => 'Inline u tekstu'] as $display => $displayLabel)
                            <option value="{{ $display }}" @selected(old("slots.$key.display", data_get($slot, 'display', 'standard')) === $display)>{{ $displayLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Google slot ID</label><input name="slots[{{ $key }}][google_slot]" value="{{ old("slots.$key.google_slot", data_get($slot, 'google_slot')) }}"></div>
                <small class="ad-field-note">Za Google slot izaberite tip “Google slot”, uključite poziciju i upišite slot ID iz AdSense ad unit-a.</small>
                <div class="field"><label>Naslov</label><input name="slots[{{ $key }}][title]" value="{{ old("slots.$key.title", data_get($slot, 'title')) }}"></div>
                <div class="field"><label>Tekst</label><textarea name="slots[{{ $key }}][text]" rows="3">{{ old("slots.$key.text", data_get($slot, 'text')) }}</textarea></div>
                <div class="field"><label>Link URL</label><input name="slots[{{ $key }}][link_url]" value="{{ old("slots.$key.link_url", data_get($slot, 'link_url')) }}"></div>
                <div class="field"><label>Label dugmeta/linka</label><input name="slots[{{ $key }}][link_label]" value="{{ old("slots.$key.link_label", data_get($slot, 'link_label')) }}"></div>
                <div class="field"><label>Slika bannera</label><input type="file" name="slots[{{ $key }}][image]" accept="image/png,image/jpeg,image/webp"></div>
                @if(data_get($slot, 'image_path'))
                    <img class="ad-admin-thumb" src="{{ asset('storage/'.data_get($slot, 'image_path')) }}" alt="">
                @endif
            </section>
        @endforeach
    </div>

    <div style="margin-top:18px;"><button class="btn" type="submit">Sačuvaj oglase</button></div>
</form>
@endsection
