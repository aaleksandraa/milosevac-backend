<?php

namespace Tests\Unit;

use App\Support\SocialPreviewDetector;
use Illuminate\Http\Request;
use Tests\TestCase;

class SocialPreviewDetectorTest extends TestCase
{
    public function test_detects_facebook_and_viber_user_agents(): void
    {
        $this->assertTrue(SocialPreviewDetector::isSocialCrawler(
            Request::create('/clanak/test', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => 'facebookexternalhit/1.1',
            ]),
        ));

        $this->assertTrue(SocialPreviewDetector::isSocialCrawler(
            Request::create('/clanak/test', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => 'Viber',
            ]),
        ));
    }

    public function test_ignores_regular_browsers(): void
    {
        $this->assertFalse(SocialPreviewDetector::isSocialCrawler(
            Request::create('/clanak/test', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/139.0.0.0',
            ]),
        ));
    }
}
