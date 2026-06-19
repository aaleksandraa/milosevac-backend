@extends('layouts.auth')
@section('content')
    <div class="auth-card">
        <div class="auth-brand">
            <a href="{{ route('home') }}">Milosevac</a>
            <h1>Prijava u CMS</h1>
            <p>Administratorski pristup za urednistvo portala.</p>
        </div>

        @if($errors->any())<div class="flash">{{ $errors->first() }}</div>@endif

        <form method="post" action="{{ route('login') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
            </div>
            <div class="field">
                <label for="password">Lozinka</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <div class="auth-actions">
                <label class="remember-field"><input type="checkbox" name="remember" value="1"> Zapamti me</label>
                <button class="btn" type="submit">Prijava</button>
            </div>
            <p class="meta" style="margin-top:16px;">Za pristup koristite nalog koji je dodijeljen urednistvu.</p>
        </form>
    </div>
@endsection
