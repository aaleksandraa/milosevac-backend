<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\Post;
use App\Support\Seo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AuditSocialPreviews extends Command
{
    protected $signature = 'seo:audit-social';

    protected $description = 'Audit social sharing title, description, canonical URL and thumbnail for every published article.';

    public function handle(): int
    {
        $failures = collect();
        $genericThumbnails = 0;
        $count = 0;
        $matchCount = 0;

        Post::published()
            ->with(['author', 'category', 'tags'])
            ->orderBy('id')
            ->chunkById(100, function ($posts) use (&$count, &$genericThumbnails, $failures): void {
                foreach ($posts as $post) {
                    $count++;
                    $seo = Seo::post($post);
                    $issues = $this->issues($seo);

                    $thumbnail = $post->og_image ?: $post->featured_image;
                    if (! $thumbnail) {
                        $genericThumbnails++;
                        $issues[] = 'uses generic thumbnail';
                    } elseif (! Str::startsWith($thumbnail, ['http://', 'https://'])
                        && ! Storage::disk('public')->exists($thumbnail)) {
                        $issues[] = 'thumbnail file is missing';
                    }

                    if ($issues !== []) {
                        $failures->push([$post->slug, implode(', ', $issues)]);
                    }
                }
            });

        FootballMatch::published()->orderBy('id')->each(function (FootballMatch $match) use (&$matchCount, &$genericThumbnails, $failures): void {
            $matchCount++;
            $seo = Seo::page(
                $match->meta_title ?: $match->title,
                $match->meta_description ?: $match->excerpt,
                route('matches.show', $match->slug),
                Seo::storageImage($match->cover_image)
            );
            $issues = $this->issues($seo);
            if (! $match->cover_image) {
                $genericThumbnails++;
                $issues[] = 'uses generic thumbnail';
            } else {
                $socialPath = preg_replace('/\.[^.]+$/', '-social.jpg', $match->cover_image);
                $thumbnail = $socialPath && Storage::disk('public')->exists($socialPath) ? $socialPath : $match->cover_image;
                if (! Storage::disk('public')->exists($thumbnail)) {
                    $issues[] = 'thumbnail file is missing';
                }
            }
            if ($issues !== []) {
                $failures->push(['match:'.$match->slug, implode(', ', $issues)]);
            }
        });

        $this->info("Audited {$count} published articles and {$matchCount} published match pages.");
        $this->line("Content pages using generic thumbnail: {$genericThumbnails}");

        if ($failures->isEmpty()) {
            $this->info('Every published article has valid social sharing metadata and an available thumbnail.');

            return self::SUCCESS;
        }

        $this->table(['Article', 'Issue'], $failures->all());

        return self::FAILURE;
    }

    /** @return array<int, string> */
    private function issues(array $seo): array
    {
        $issues = [];

        if (blank($seo['title'] ?? null)) {
            $issues[] = 'missing title';
        }
        if (blank($seo['description'] ?? null)) {
            $issues[] = 'missing description';
        }
        if (! filter_var($seo['canonical'] ?? null, FILTER_VALIDATE_URL)) {
            $issues[] = 'invalid canonical URL';
        }
        if (! filter_var($seo['image'] ?? null, FILTER_VALIDATE_URL)) {
            $issues[] = 'invalid thumbnail URL';
        }

        return $issues;
    }
}
