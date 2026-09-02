<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:locale" content="{{ $seo['locale'] }}">
    <meta property="og:type" content="{{ $seo['type'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta property="og:image:secure_url" content="{{ $seo['image'] }}">
    <meta property="og:image:type" content="{{ $seo['image_type'] }}">
    <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $seo['image'] }}">
    <meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">
    @isset($seo['published_at'])<meta property="article:published_time" content="{{ $seo['published_at'] }}">@endisset
    @isset($seo['modified_at'])<meta property="article:modified_time" content="{{ $seo['modified_at'] }}">@endisset
    @foreach(($seo['schemas'] ?? []) as $schema)
        <script type="application/ld+json">@json($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)</script>
    @endforeach
</head>
<body>
    <main>
        <h1>{{ $seo['title'] }}</h1>
        <p>{{ $seo['description'] }}</p>
        @isset($seo['ranking_rows'])
            <h2>{{ $seo['ranking_caption'] ?? 'Rang-lista' }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Rang</th>
                        <th>Naselje</th>
                        <th>Opština</th>
                        <th>Vrijednost</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($seo['ranking_rows'] as $row)
                        <tr>
                            <td>{{ $row['rank'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['municipality'] }}</td>
                            <td>{{ $row['value'] }}{{ !empty($row['unit']) ? ' '.$row['unit'] : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endisset
        @isset($seo['compare_rows'])
            <h2>{{ $seo['compare_caption'] ?? 'Poređenje' }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>Metrik</th>
                        <th>Naselje A</th>
                        <th>Naselje B</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($seo['compare_rows'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td>{{ $row['a'] === null ? 'Nema dostupnog podatka' : $row['a'] }}{{ !empty($row['unit']) && $row['a'] !== null ? ' '.$row['unit'] : '' }}</td>
                            <td>{{ $row['b'] === null ? 'Nema dostupnog podatka' : $row['b'] }}{{ !empty($row['unit']) && $row['b'] !== null ? ' '.$row['unit'] : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endisset
        <a href="{{ $seo['canonical'] }}">Otvori stranicu</a>
    </main>
</body>
</html>
