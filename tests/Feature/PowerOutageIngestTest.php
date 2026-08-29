<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use App\Support\PowerOutage\OutagePublisher;
use App\Support\PowerOutage\PlaceMatcher;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PowerOutageIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_fixture_command_outputs_preview(): void
    {
        $this->seed(DatabaseSeeder::class);

        $fixture = base_path('tests/fixtures/power-outage-dobrinja.txt');

        $this->artisan('outages:ingest', [
            '--dry-run' => true,
            '--fixture' => $fixture,
            '--message-id' => 'test-message-15175',
        ])
            ->expectsOutputToContain('[OK] Elektro Doboj #15175')
            ->expectsOutputToContain('Planirani prekid struje u Dobrinji 31. avgusta')
            ->expectsOutputToContain('POST NIJE OBJAVLJEN (--dry-run)')
            ->assertSuccessful();
    }

    public function test_fixture_publish_creates_power_outage_post(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PlaceSeeder::class);

        $fixture = base_path('tests/fixtures/power-outage-dobrinja.txt');

        $this->artisan('outages:ingest', [
            '--fixture' => $fixture,
            '--message-id' => 'test-message-publish-15175',
        ])->assertSuccessful();

        $post = Post::query()->where('service_type', 'power_outage')->first();

        $this->assertNotNull($post);
        $this->assertSame('power_outage', $post->service_type);
        $this->assertNotNull($post->notice_starts_at);
        $this->assertStringContainsString('Dobrinji', $post->title);
    }

    public function test_outages_api_returns_published_outage(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(PlaceSeeder::class);

        $body = file_get_contents(base_path('tests/fixtures/power-outage-dobrinja.txt'));
        $notice = (new \App\Support\PowerOutage\NoticeParser())->parse($body);
        $places = (new PlaceMatcher())->match($notice);
        $author = User::query()->where('email', 'redakcija@milosevac.test')->first();
        (new OutagePublisher())->publish($notice, $places, $author);

        $response = $this->getJson('/api/outages?status=upcoming');

        $response->assertOk()
            ->assertJsonPath('outages.0.serviceType', 'power_outage')
            ->assertJsonStructure([
                'places',
                'outages' => [
                    ['slug', 'title', 'outageStatus', 'places', 'locationsRaw'],
                ],
            ]);
    }
}
