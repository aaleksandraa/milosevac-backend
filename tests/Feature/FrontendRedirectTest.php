<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_redirect_to_the_only_frontend(): void
    {
        $this->get('/vrijeme')->assertRedirect('http://localhost:8080/vrijeme');
        $this->get('/kontakt')->assertRedirect('http://localhost:8080/kontakt');
        $this->get('/politika-privatnosti')->assertRedirect('http://localhost:8080/politika-privatnosti');
        $this->get('/politika-kolacica')->assertRedirect('http://localhost:8080/politika-kolacica');
        $this->get('/uslovi-koristenja')->assertRedirect('http://localhost:8080/uslovi-koristenja');
        $this->get('/clanak/test-clanak')->assertRedirect('http://localhost:8080/clanak/test-clanak');
    }

    public function test_social_crawler_receives_article_title_description_thumbnail_and_canonical_url(): void
    {
        $this->seed();
        $post = $this->createPublishedPost();
        $post->update([
            'featured_image' => 'wordpress/test-thumbnail.webp',
            'featured_image_alt' => 'Testni thumbnail',
        ]);

        $this->withHeader('X-Social-Preview', '1')
            ->get("/clanak/{$post->slug}")
            ->assertOk()
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:title" content="Testni objavljeni članak | Miloševac">', false)
            ->assertSee('<meta property="og:description" content="Testni sažetak.">', false)
            ->assertSee('<meta property="og:image" content="http://localhost/storage/wordpress/test-thumbnail.webp">', false)
            ->assertSee('<link rel="canonical" href="http://localhost/clanak/testni-objavljeni-clanak">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
    }

    public function test_social_crawler_receives_metadata_for_static_frontend_pages(): void
    {
        $this->withHeader('X-Social-Preview', '1')
            ->get('/kontakt')
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Kontakt | Miloševac">', false)
            ->assertSee('Pošaljite vijest, fotografiju, obavještenje ili prijedlog', false)
            ->assertSee('<meta property="og:image" content="http://localhost/logo.webp">', false);
    }

    public function test_content_api_exposes_published_cms_articles(): void
    {
        $this->seed();
        $post = $this->createPublishedPost();

        $this->get('/api/content')
            ->assertOk()
            ->assertJsonPath('articles.0.slug', $post->slug)
            ->assertJsonStructure(['articles' => [['slug', 'title', 'category', 'contentHtml']]]);
    }

    public function test_content_api_marks_active_notices_for_the_react_frontend(): void
    {
        $this->seed();
        $post = $this->createPublishedPost();
        $post->update([
            'label' => 'obavijest',
            'notice_starts_at' => now()->subMinute(),
            'notice_ends_at' => now()->addDay(),
        ]);

        $response = $this->get('/api/content')
            ->assertOk()
            ->assertJsonPath('articles.0.slug', $post->slug)
            ->assertJsonPath('articles.0.notice', true)
            ->assertJsonPath('articles.0.label', 'obavijest');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', $response->headers->get('Cache-Control'));
    }

    public function test_content_api_exposes_a_single_published_article_by_slug(): void
    {
        $this->seed();
        $slug = $this->createPublishedPost()->slug;

        $this->get("/api/content/{$slug}")
            ->assertOk()
            ->assertJsonPath('article.slug', $slug)
            ->assertJsonStructure(['article' => ['slug', 'title', 'category', 'contentHtml']]);
    }

    public function test_single_article_api_renders_plain_youtube_links_as_embeds(): void
    {
        $this->seed();
        $post = $this->createPublishedPost();
        $post->update(['content' => "Uvod\n\nhttps://www.youtube.com/watch?v=fh_Rg6e4SY8\n\nhttps://youtu.be/y4o7u5J1QBc\n\nKraj"]);

        $this->get("/api/content/{$post->slug}")
            ->assertOk()
            ->assertJsonPath('article.contentHtml', fn ($html) => str_contains($html, 'youtube-nocookie.com/embed/fh_Rg6e4SY8')
                && str_contains($html, 'youtube-nocookie.com/embed/y4o7u5J1QBc'));
    }

    public function test_sitemap_uses_frontend_canonical_urls(): void
    {
        config(['services.frontend.url' => 'https://milosevac.com']);

        $this->seed();
        $post = $this->createPublishedPost();

        $this->get('/sitemap-posts.xml')
            ->assertOk()
            ->assertSee('<loc>https://milosevac.com/clanak/'.$post->slug.'</loc>', false)
            ->assertDontSee('https://api.milosevac.com/clanak', false);
    }

    public function test_api_robots_points_to_frontend_sitemap(): void
    {
        config(['services.frontend.url' => 'https://milosevac.com']);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /api/', false)
            ->assertSee('Sitemap: https://milosevac.com/sitemap.xml', false);
    }

    private function createPublishedPost(): Post
    {
        return Post::create([
            'author_id' => User::firstOrFail()->id,
            'category_id' => Category::where('slug', 'vijesti')->firstOrFail()->id,
            'title' => 'Testni objavljeni članak',
            'slug' => 'testni-objavljeni-clanak',
            'excerpt' => 'Testni sažetak.',
            'content' => '<p>Testni sadržaj.</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
    }
}
