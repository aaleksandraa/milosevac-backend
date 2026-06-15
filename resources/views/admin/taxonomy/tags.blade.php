@extends('layouts.admin')
@section('content')
<h1>Tagovi</h1>
<form method="post" action="{{ route('admin.tags.store') }}" class="panel">@csrf
    <div class="form-grid"><div class="field"><label>Naziv</label><input name="name" required></div><div class="field"><label>Slug</label><input name="slug"></div></div>
    <div class="field"><label>Opis</label><textarea name="description"></textarea></div>
    <button class="btn">Dodaj tag</button>
</form>
<table class="table"><tr><th>Naziv</th><th>Slug</th></tr>@foreach($tags as $tag)<tr><td>{{ $tag->name }}</td><td>{{ $tag->slug }}</td></tr>@endforeach</table>
@endsection
