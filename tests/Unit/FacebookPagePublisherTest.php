<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use App\Support\Seo;
use App\Support\Social\FacebookPagePublisher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookPagePublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_shares_power_outage_post_to_facebook_page(): void
    {
        config([
            'outages.facebook_auto_share' => true,
            'services.facebook.page_id' => '123456789',
            'services.facebook.page_access_token' => 'page-token',
            'services.frontend.url' => 'https://milosevac.com',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['id' => '987654321_111'], 200),
        ]);

        $post = Post::query()->create([
            'author_id' => User::firstOrFail()->id,
            'category_id' => Category::where('slug', 'vijesti')->firstOrFail()->id,
            'title' => 'Planirani prekid struje u Dobrinji',
            'slug' => 'planirani-prekid-struje-u-dobrinji',
            'excerpt' => 'Planirani prekid struje 31. avgusta od 09:00 do 11:00.',
            'content' => '<p>Test</p>',
            'status' => 'published',
            'published_at' => now(),
            'service_type' => 'power_outage',
            'seo_metadata' => ['outage' => ['externalId' => '15175']],
        ]);

        $publisher = new FacebookPagePublisher();
        $facebookPostId = $publisher->sharePost($post);

        $this->assertSame('987654321_111', $facebookPostId);

        Http::assertSent(function ($request) use ($post) {
            $body = $request->data();

            return str_contains($request->url(), '/123456789/feed')
                && ($body['message'] ?? '') === trim($post->title."\n\n".$post->excerpt)
                && ($body['link'] ?? '') === Seo::frontendUrl('clanak/'.$post->slug)
                && ($body['access_token'] ?? '') === 'page-token';
        });
    }

    public function test_skips_when_already_shared(): void
    {
        config([
            'outages.facebook_auto_share' => true,
            'services.facebook.page_id' => '123456789',
            'services.facebook.page_access_token' => 'page-token',
        ]);

        Http::fake();

        $post = Post::query()->create([
            'author_id' => User::firstOrFail()->id,
            'category_id' => Category::where('slug', 'vijesti')->firstOrFail()->id,
            'title' => 'Planirani prekid struje',
            'slug' => 'planirani-prekid-struje',
            'excerpt' => 'Test',
            'content' => '<p>Test</p>',
            'status' => 'published',
            'published_at' => now(),
            'service_type' => 'power_outage',
            'seo_metadata' => ['facebook' => ['postId' => 'existing-post-id']],
        ]);

        $this->assertNull((new FacebookPagePublisher())->sharePost($post));
        Http::assertNothingSent();
    }
}
