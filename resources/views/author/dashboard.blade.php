@extends('layouts.author')
@section('content')
<div style="display:flex;justify-content:space-between;gap:16px;align-items:center;">
    <h1>Moji članci</h1>
    <a class="btn" href="{{ route('author.posts.create') }}">Novi članak</a>
</div>
<table class="table">
    <tr><th>Naslov</th><th>Status</th><th>Kategorija</th><th>Ažurirano</th><th></th></tr>
    @foreach($posts as $post)
        <tr><td>{{ $post->title }}</td><td>{{ str_replace('_',' ', $post->status) }}</td><td>{{ $post->category->name }}</td><td>{{ $post->updated_at->format('d.m.Y. H:i') }}</td><td><a class="btn secondary" href="{{ route('author.posts.edit', $post) }}">Uredi</a></td></tr>
    @endforeach
</table>
{{ $posts->links('vendor.pagination.clean') }}

<div style="display:flex;justify-content:space-between;gap:16px;align-items:center;margin-top:32px;">
    <h2>Moje utakmice</h2>
    <a class="btn" href="{{ route('author.matches.create') }}">Nova utakmica</a>
</div>
<table class="table">
    <tr><th>Utakmica</th><th>Rezultat</th><th>Status</th><th>Galerija</th><th>Ažurirano</th><th></th></tr>
    @foreach($matches as $match)
        <tr>
            <td>{{ $match->title }}</td>
            <td>{{ $match->home_team }} {{ $match->score() }} {{ $match->away_team }}</td>
            <td>{{ str_replace('_',' ', $match->status) }}</td>
            <td>{{ $match->media->count() }} slika</td>
            <td>{{ $match->updated_at->format('d.m.Y. H:i') }}</td>
            <td><a class="btn secondary" href="{{ route('author.matches.edit', $match) }}">Uredi</a></td>
        </tr>
    @endforeach
</table>
@endsection
