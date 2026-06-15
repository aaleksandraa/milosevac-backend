@extends('layouts.admin')
@section('content')
<div style="display:flex;justify-content:space-between;gap:16px;align-items:center;">
    <h1>Članci</h1>
    <a class="btn" href="{{ route('admin.posts.create') }}">Novi članak</a>
</div>
<table class="table">
    <tr><th>Naslov</th><th>Oznaka</th><th>Status</th><th>Kategorija</th><th>Autor</th><th>Objava</th><th></th></tr>
    @foreach($posts as $post)
        <tr>
            <td>{{ $post->title }}</td>
            <td>{{ $post->labelText() ?: '-' }}</td>
            <td>{{ str_replace('_', ' ', $post->status) }}</td>
            <td>{{ $post->category->name }}</td>
            <td>{{ $post->author->name }}</td>
            <td>{{ optional($post->published_at)->format('d.m.Y. H:i') }}</td>
            <td><a class="btn secondary" href="{{ route('admin.posts.edit', $post) }}">Uredi</a></td>
        </tr>
    @endforeach
</table>
{{ $posts->links('vendor.pagination.clean') }}
@endsection
