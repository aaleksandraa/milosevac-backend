<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\PowerOutage\PowerOutageFeaturedImage;
use Illuminate\Console\Command;

class SyncOutageImages extends Command
{
    protected $signature = 'outages:sync-images {--dry-run : Preview image fixes without saving}';

    protected $description = 'Ensure power_outage posts use the configured featured image and responsive variants';

    public function handle(PowerOutageFeaturedImage $featuredImage): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        Post::query()
            ->where('service_type', 'power_outage')
            ->orderBy('id')
            ->chunkById(50, function ($posts) use ($featuredImage, $dryRun, &$updated) {
                foreach ($posts as $post) {
                    $bundle = $featuredImage->bundle($post->featured_image, $post->featured_image_responsive);
                    $changes = array_filter([
                        'featured_image' => $bundle['path'],
                        'featured_image_alt' => $post->featured_image_alt ?: $bundle['alt'],
                        'featured_image_responsive' => $bundle['responsive'],
                        'og_image' => $bundle['og_image'],
                        'og_image_responsive' => $bundle['og_image_responsive'],
                    ], fn ($value) => $value !== null);

                    $needsUpdate = $post->featured_image !== ($changes['featured_image'] ?? null)
                        || $post->featured_image_responsive != ($changes['featured_image_responsive'] ?? null)
                        || $post->og_image !== ($changes['og_image'] ?? null);

                    if (! $needsUpdate) {
                        continue;
                    }

                    $this->line(sprintf(
                        '[%s] #%d %s',
                        $dryRun ? 'DRY' : 'SYNC',
                        $post->id,
                        $post->title,
                    ));
                    $this->line('  slika: '.($post->featured_image ?: '—').' → '.($changes['featured_image'] ?: '—'));

                    if ($dryRun) {
                        continue;
                    }

                    $post->update($changes);
                    $updated++;
                }
            });

        $this->info($dryRun
            ? 'Dry run complete.'
            : "Updated {$updated} power outage post image(s).");

        return self::SUCCESS;
    }
}
