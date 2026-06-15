@props(['post', 'hero' => false, 'horizontal' => false, 'wide' => false])
<article class="news-card {{ $hero ? 'hero-card' : '' }} {{ $horizontal ? 'horizontal-card' : '' }} {{ $wide ? 'wide-card' : '' }}">
    <a href="{{ route('posts.show', $post->slug) }}" aria-label="{{ $post->title }}">
        <div class="cover cover-{{ \Illuminate\Support\Str::before($post->category->slug, '-') }}">
            @if($post->featured_image)
                <img
                    src="{{ asset('storage/'.$post->featured_image) }}"
                    @if(\App\Support\ImagePipeline::srcset($post->featured_image_responsive)) srcset="{{ \App\Support\ImagePipeline::srcset($post->featured_image_responsive) }}" sizes="{{ $hero ? '(max-width: 900px) 100vw, 720px' : '(max-width: 620px) 100vw, (max-width: 900px) 50vw, 340px' }}" @endif
                    alt="{{ $post->featured_image_alt ?: $post->title }}"
                    loading="{{ $hero ? 'eager' : 'lazy' }}"
                    decoding="async">
            @elseif($post->defaultImageUrl())
                <img
                    src="{{ $post->defaultImageUrl() }}"
                    alt="{{ $post->featured_image_alt ?: $post->title }}"
                    loading="{{ $hero ? 'eager' : 'lazy' }}"
                    decoding="async">
            @else
                <span class="cover-label" aria-hidden="true">{{ $post->category->name }}</span>
            @endif
        </div>
    </a>
    <div class="card-body">
        <div class="card-labels">
            @if($post->labelText())
                <span class="post-label {{ $post->labelClass() }}">{{ $post->labelText() }}</span>
            @endif
            <a class="category-pill" style="background: {{ $post->category->color }}" href="{{ route('categories.show', $post->category) }}">{{ $post->category->name }}</a>
        </div>
        <h2 class="title"><a href="{{ route('posts.show', $post->slug) }}">{{ $post->title }}</a></h2>
        <div class="meta">
            <a href="{{ route('authors.show', $post->author) }}">{{ $post->author->name }}</a>
            <span>{{ optional($post->published_at)->format('d.m.Y.') }}</span>
            <span>{{ $post->reading_time }} min</span>
        </div>
        <p class="excerpt">{{ $post->excerpt }}</p>
        @if($post->relationLoaded('tags') && $post->tags->isNotEmpty())
            <div class="card-tags" aria-label="Tagovi">
                @foreach($post->tags->take(3) as $tag)
                    <a href="{{ route('tags.show', $tag) }}">#{{ $tag->name }}</a>
                @endforeach
            </div>
        @endif
    </div>
</article>
