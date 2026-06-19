<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'CMS prijava' }} | Milosevac</title>
    @include('partials.vite-assets')
</head>
<body class="auth-page">
    @yield('content')
</body>
</html>
