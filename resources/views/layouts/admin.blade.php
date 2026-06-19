<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ?? 'CMS' }} | Miloševac</title>
    @include('partials.vite-assets')
</head>
<body>
<div class="admin-shell">
    <aside class="admin-nav">
        <h1>CMS</h1>
        <a href="{{ route('admin.dashboard') }}">Dashboard</a>
        <a href="{{ route('admin.posts.index') }}">Članci</a>
        <a href="{{ route('admin.matches.index') }}">Utakmice</a>
        <a href="{{ route('admin.ads.index') }}">Oglasi</a>
        <a href="{{ route('admin.categories.index') }}">Kategorije</a>
        <a href="{{ route('admin.tags.index') }}">Tagovi</a>
        <a href="{{ route('admin.users.index') }}">Korisnici</a>
        <a href="{{ route('author.dashboard') }}">Autorski panel</a>
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
