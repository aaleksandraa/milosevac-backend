@extends('layouts.admin')
@section('content')
<h1>Urednicki dashboard</h1>
<div class="stats">
    <div class="stat"><span>Ukupno članaka</span><strong>{{ $postsCount }}</strong></div>
    <div class="stat"><span>Objavljeno</span><strong>{{ $publishedCount }}</strong></div>
    <div class="stat"><span>Na pregledu</span><strong>{{ $pendingCount }}</strong></div>
    <div class="stat"><span>Pregledi</span><strong>{{ $viewsCount }}</strong></div>
</div>
<section class="section">
    <h2>Najčitaniji članci</h2>
    <table class="table">
        <tr><th>Naslov</th><th>Kategorija</th><th>Autor</th><th>Pregledi</th></tr>
        @foreach($popular as $post)
            <tr><td><a href="{{ route('admin.posts.edit', $post) }}">{{ $post->title }}</a></td><td>{{ $post->category->name }}</td><td>{{ $post->author->name }}</td><td>{{ $post->views_count }}</td></tr>
        @endforeach
    </table>
</section>
@endsection
