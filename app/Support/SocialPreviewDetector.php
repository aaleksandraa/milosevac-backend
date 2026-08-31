<?php

namespace App\Support;

use Illuminate\Http\Request;

class SocialPreviewDetector
{
    /** @var list<string> */
    private const BOT_PATTERNS = [
        'facebookexternalhit',
        'facebot',
        'twitterbot',
        'linkedinbot',
        'whatsapp',
        'slackbot',
        'telegrambot',
        'viber',
        'discordbot',
        'pinterestbot',
        'googlebot',
        'bingbot',
        'applebot',
        'embedly',
        'skypeuripreview',
        'redditbot',
        'quora link preview',
    ];

    public static function isSocialCrawler(?Request $request = null): bool
    {
        $request ??= request();
        if ($request === null) {
            return false;
        }

        if ($request->header('X-Social-Preview') === '1') {
            return true;
        }

        $agent = strtolower((string) $request->userAgent());
        if ($agent === '') {
            return false;
        }

        foreach (self::BOT_PATTERNS as $pattern) {
            if (str_contains($agent, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
