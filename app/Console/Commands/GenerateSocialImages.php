<?php

namespace App\Console\Commands;

use App\Models\FootballMatch;
use App\Models\Post;
use App\Support\ImagePipeline;
use Illuminate\Console\Command;

class GenerateSocialImages extends Command
{
    protected $signature = 'posts:generate-social-images {--force : Regenerate existing JPEG social images}';

    protected $description = 'Generate broadly compatible JPEG social thumbnails from published article and match images.';

    public function handle(): int
    {
        $pipeline = app(ImagePipeline::class);
        $generated = 0;
        $reused = 0;
        $failed = 0;

        Post::published()->whereNotNull('featured_image')->orderBy('id')->chunkById(50, function ($posts) use ($pipeline, &$generated, &$reused, &$failed): void {
            foreach ($posts as $post) {
                $existed = $this->socialImageExists($post->featured_image);
                $targetPath = $pipeline->socialImage($post->featured_image, $this->option('force'));
                if (! $targetPath) {
                    $this->warn("Missing source: {$post->slug}");
                    $failed++;
                    continue;
                }

                $this->option('force') || ! $existed ? $generated++ : $reused++;
                Post::query()->whereKey($post->id)->update(['og_image' => $targetPath]);
            }
        });

        FootballMatch::published()->whereNotNull('cover_image')->orderBy('id')->chunkById(50, function ($matches) use ($pipeline, &$generated, &$reused, &$failed): void {
            foreach ($matches as $match) {
                $existed = $this->socialImageExists($match->cover_image);
                if (! $pipeline->socialImage($match->cover_image, $this->option('force'))) {
                    $this->warn("Missing match cover: {$match->slug}");
                    $failed++;
                    continue;
                }

                $this->option('force') || ! $existed ? $generated++ : $reused++;
            }
        });

        $this->info("Social thumbnails ready. Generated: {$generated}, reused: {$reused}, failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function socialImageExists(string $path): bool
    {
        $socialPath = preg_replace('/\.[^.]+$/', '-social.jpg', $path);

        return $socialPath && file_exists(storage_path('app/public/'.$socialPath));
    }
}
