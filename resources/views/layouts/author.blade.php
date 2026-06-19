<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Autorski panel | Miloševac</title>
    @include('partials.vite-assets')
</head>
<body>
<div class="admin-shell">
    <aside class="admin-nav">
        <h1>Autor</h1>
        <a href="{{ route('author.dashboard') }}">Moji članci</a>
        <a href="{{ route('author.posts.create') }}">Novi članak</a>
        <a href="{{ route('author.matches.create') }}">Nova utakmica</a>
        @if(auth()->user()->hasRole('super_admin','admin','editor'))<a href="{{ route('admin.dashboard') }}">Admin</a>@endif
        <a href="{{ route('account.password.edit') }}">Promjena lozinke</a>
        <a href="{{ route('home') }}">Portal</a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn secondary" type="submit">Odjava</button></form>
    </aside>
    <main class="admin-main">
        @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="flash">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
</div>
</body>
</html>
