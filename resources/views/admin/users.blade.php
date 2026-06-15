@extends('layouts.admin')
@section('content')
<h1>Korisnici i uloge</h1>
<form method="post" action="{{ route('admin.users.store') }}" class="panel">@csrf
    <div class="form-grid">
        <div><div class="field"><label>Ime</label><input name="name" required></div><div class="field"><label>Email</label><input type="email" name="email" required></div></div>
        <div><div class="field"><label>Uloga</label><select name="role_id">@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->label }}</option>@endforeach</select></div><div class="field"><label>Password</label><input type="password" name="password" required></div></div>
    </div>
    <button class="btn">Kreiraj korisnika</button>
</form>
<table class="table"><tr><th>Ime</th><th>Email</th><th>Uloga</th></tr>@foreach($users as $user)<tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role?->label }}</td></tr>@endforeach</table>
@endsection
