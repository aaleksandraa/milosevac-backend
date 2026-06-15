@extends('layouts.admin')

@section('content')
<div style="display:flex;justify-content:space-between;gap:16px;align-items:center;">
    <h1>Utakmice FK Posavina</h1>
    <a class="btn" href="{{ route('admin.matches.create') }}">Nova utakmica</a>
</div>

<section class="panel" style="margin:18px 0;">
    <h2>Watermark za galerije</h2>
    <form method="post" enctype="multipart/form-data" action="{{ route('admin.watermark.update') }}" class="form-grid">
        @csrf
        <div>
            <div class="field"><label>Custom logo PNG</label><input type="file" name="watermark_logo" accept="image/png"></div>
            @if(!empty($watermark['path']))
                <small>Aktivni logo: {{ $watermark['path'] }}</small>
            @endif
        </div>
        <div>
            <div class="field">
                <label>Opacity watermarka: {{ $watermark['opacity'] ?? 35 }}%</label>
                <input type="range" min="0" max="100" name="opacity" value="{{ old('opacity', $watermark['opacity'] ?? 35) }}">
            </div>
            <button class="btn" type="submit">Sačuvaj watermark</button>
        </div>
    </form>
</section>

<table class="table">
    <tr><th>Utakmica</th><th>Rezultat</th><th>Status</th><th>Datum</th><th>Galerija</th><th>Autor</th><th></th></tr>
    @foreach($matches as $match)
        <tr>
            <td>{{ $match->title }}</td>
            <td>{{ $match->home_team }} {{ $match->score() }} {{ $match->away_team }}</td>
            <td>{{ str_replace('_', ' ', $match->status) }}</td>
            <td>{{ optional($match->played_at)->format('d.m.Y. H:i') }}</td>
            <td>{{ $match->media->count() }} slika</td>
            <td>{{ $match->author->name }}</td>
            <td style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                @if($match->status === 'published' && $match->published_at)
                    <a class="btn secondary" href="{{ route('matches.show', $match->slug) }}" target="_blank" rel="noopener">Otvori</a>
                @endif
                <a class="btn secondary" href="{{ route('admin.matches.edit', $match) }}">Uredi</a>
            </td>
        </tr>
    @endforeach
</table>
{{ $matches->links('vendor.pagination.clean') }}
@endsection
