@props(['popular'])
<aside class="sidebar">
    <section class="panel soft-panel">
        <h2>Najčitanije</h2>
        <div class="ranked">
            @foreach($popular as $item)
                <a href="{{ route('posts.show', $item->slug) }}"><span>{{ $loop->iteration }}</span><strong>{{ $item->title }}</strong></a>
            @endforeach
        </div>
    </section>
    <x-ad-slot position="sidebar_primary" />
    <x-ad-slot position="sidebar_secondary" />
</aside>
