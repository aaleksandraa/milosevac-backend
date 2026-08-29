<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_redirects_to_the_react_frontend(): void
    {
        $this->get('/')
            ->assertRedirect('http://localhost:8080/');
    }

    public function test_ads_api_falls_back_to_the_default_client_id(): void
    {
        Setting::create([
            'key' => 'ad_settings',
            'value' => [
                'google' => ['enabled' => false, 'client_id' => ''],
                'slots' => ['top_banner' => ['enabled' => true]],
            ],
        ]);

        $this->getJson('/api/ads')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('clientId', 'ca-pub-1407310093643341');
    }

    public function test_ads_api_exposes_only_enabled_flags_for_frontend_slots(): void
    {
        Setting::create([
            'key' => 'ad_settings',
            'value' => [
                'google' => ['enabled' => true, 'client_id' => 'ca-pub-123456789012'],
                'slots' => [
                    'top_banner' => ['enabled' => true, 'type' => 'image', 'title' => 'Old data'],
                    'footer_banner' => ['enabled' => false],
                ],
            ],
        ]);

        $this->getJson('/api/ads')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('clientId', 'ca-pub-123456789012')
            ->assertJsonPath('slots.top_banner.enabled', true)
            ->assertJsonPath('slots.footer_banner.enabled', false)
            ->assertJsonMissingPath('slots.top_banner.type')
            ->assertJsonMissingPath('slots.top_banner.title');
    }
}
