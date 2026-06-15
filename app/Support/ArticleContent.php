<?php

namespace App\Support;

class ArticleContent
{
    public static function withYoutubeEmbeds(?string $html): ?string
    {
        if (! $html || ! str_contains(strtolower($html), 'youtu')) {
            return $html;
        }

        $replace = function (array $match): string {
            $videoId = self::youtubeVideoId($match[1]);

            return $videoId ? self::youtubeEmbed($videoId) : $match[0];
        };

        $html = preg_replace_callback(
            '~<p(?:\s[^>]*)?>\s*(?:<a(?:\s[^>]*)?href=["\']([^"\']+)["\'][^>]*>.*?</a>|(https?://[^\s<]+))\s*</p>~is',
            function (array $match) use ($replace): string {
                return $replace([0 => $match[0], 1 => $match[1] ?: $match[2]]);
            },
            $html
        ) ?? $html;

        return preg_replace_callback(
            '~(?m)^[ \t]*(https?://(?:www\.)?(?:youtube\.com/watch\?[^\r\n<]+|youtu\.be/[^\r\n<]+))[ \t]*$~i',
            $replace,
            $html
        ) ?? $html;
    }

    private static function youtubeVideoId(string $url): ?string
    {
        $url = html_entity_decode(trim(strip_tags($url)));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_ends_with($host, 'youtu.be')) {
            $videoId = trim((string) parse_url($url, PHP_URL_PATH), '/');
        } elseif (str_ends_with($host, 'youtube.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $videoId = (string) ($query['v'] ?? '');
        } else {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) ? $videoId : null;
    }

    private static function youtubeEmbed(string $videoId): string
    {
        return '<div class="youtube-embed"><iframe src="https://www.youtube-nocookie.com/embed/'
            .$videoId
            .'" title="YouTube video" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>';
    }
}
