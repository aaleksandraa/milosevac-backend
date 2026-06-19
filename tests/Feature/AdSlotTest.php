<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AdSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_redirects_to_the_react_frontend(): void
    {
        $this->get('/')
            ->assertRedirect('http://localhost:8080/');
    }

    public function test_it_does_not_render_an_unconfigured_ad_slot(): void
    {
        Setting::create([
            'key' => 'ad_settings',
            'value' => [
                'google' => ['enabled' => false, 'client_id' => ''],
                'slots' => [
                    'top_banner' => ['enabled' => true],
                ],
            ],
        ]);

        $this->assertSame('', trim(Blade::render('<x-ad-slot position="top_banner" />')));
    }

    public function test_it_renders_a_configured_google_ad_slot(): void
    {
        Setting::create([
            'key' => 'ad_settings',
            'value' => [
                'google' => ['enabled' => true, 'client_id' => 'ca-pub-123456789012'],
                'slots' => [
                    'top_banner' => ['enabled' => true],
                ],
            ],
        ]);

        $html = Blade::render('<x-ad-slot position="top_banner" />');

        $this->assertStringContainsString('class="adsbygoogle"', $html);
        $this->assertStringContainsString('data-ad-client="ca-pub-123456789012"', $html);
        $this->assertStringContainsString('data-ad-position="top_banner"', $html);
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
