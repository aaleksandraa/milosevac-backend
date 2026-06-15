@extends('layouts.admin')
@section('content')
<h1>Kategorije i podkategorije</h1>
<form method="post" action="{{ route('admin.categories.store') }}" class="panel">@csrf
    <div class="form-grid">
        <div><div class="field"><label>Naziv</label><input name="name" required></div><div class="field"><label>Slug</label><input name="slug"></div></div>
        <div><div class="field"><label>Parent</label><select name="parent_id"><option value="">Bez parenta</option>@foreach($parents as $parent)<option value="{{ $parent->id }}">{{ $parent->name }}</option>@endforeach</select></div><button class="btn">Dodaj</button></div>
    </div>
    <div class="field"><label>Opis</label><textarea name="description"></textarea></div>
    <div class="field"><label>Meta description</label><textarea name="meta_description"></textarea></div>
</form>
<table class="table"><tr><th>Naziv</th><th>Slug</th><th>Parent</th></tr>@foreach($categories as $category)<tr><td>{{ $category->name }}</td><td>{{ $category->slug }}</td><td>{{ $category->parent?->name }}</td></tr>@endforeach</table>
@endsection
