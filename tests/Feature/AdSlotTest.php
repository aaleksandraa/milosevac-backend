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
                    'top_banner' => ['enabled' => true, 'type' => 'google', 'google_slot' => ''],
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
                    'top_banner' => ['enabled' => true, 'type' => 'google', 'google_slot' => '1234567890'],
                ],
            ],
        ]);

        $html = Blade::render('<x-ad-slot position="top_banner" />');

        $this->assertStringContainsString('class="adsbygoogle"', $html);
        $this->assertStringContainsString('data-ad-slot="1234567890"', $html);
    }
}
