<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ArticleContent
{
    /** @var array<string, string>|null */
    private static ?array $wordpressMediaMap = null;

    public static function prepare(?string $html): ?string
    {
        return self::withYoutubeEmbeds(self::localizeWordpressMedia($html));
    }

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

    private static function localizeWordpressMedia(?string $html): ?string
    {
        if (! $html || ! str_contains($html, 'wp-content/uploads/')) {
            return $html;
        }

        return preg_replace_callback(
            '/https?:\/\/[^\s"\']+wp-content\/uploads\/[^\s"\']+\.(?:jpe?g|png|webp|gif)/i',
            function (array $match): string {
                $url = html_entity_decode($match[0]);
                $path = self::wordpressMediaMap()[self::normalizeWordpressUrl($url)] ?? null;

                return $path ? '/storage/'.ltrim($path, '/') : $match[0];
            },
            $html
        ) ?? $html;
    }

    /** @return array<string, string> */
    private static function wordpressMediaMap(): array
    {
        if (self::$wordpressMediaMap !== null) {
            return self::$wordpressMediaMap;
        }

        return self::$wordpressMediaMap = Media::query()
            ->whereNotNull('source_url')
            ->where('source_url', 'like', '%wp-content/uploads/%')
            ->get(['source_url', 'path'])
            ->filter(fn (Media $media) => $media->source_url && $media->path && Storage::disk('public')->exists($media->path))
            ->mapWithKeys(fn (Media $media) => [self::normalizeWordpressUrl($media->source_url) => $media->path])
            ->all();
    }

    private static function normalizeWordpressUrl(string $url): string
    {
        $url = html_entity_decode(trim($url));
        $host = parse_url($url, PHP_URL_HOST);

        if ($host && Str::endsWith(strtolower($host), 'milosevac.com')) {
            return preg_replace('/^http:\/\//i', 'https://', $url) ?: $url;
        }

        return $url;
    }
}
