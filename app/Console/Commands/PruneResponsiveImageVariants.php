<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PruneResponsiveImageVariants extends Command
{
    protected $signature = 'images:prune-responsive-variants
        {--force : Delete files and clear responsive image references}
        {--delete-sources : Also delete unreferenced *-source.* originals}
        {--directory=wordpress : Public storage directory to prune}';

    protected $description = 'Remove unused responsive image variants while keeping article-linked images.';

    public function handle(): int
    {
        $directory = trim((string) $this->option('directory'), '/');
        $storageRoot = storage_path('app/public');
        $targetRoot = $storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

        if (! is_dir($targetRoot)) {
            $this->error("Directory does not exist: {$targetRoot}");

            return self::FAILURE;
        }

        [$keptPaths, $variantCandidates, $sourceCandidates] = $this->collectReferencedPaths($directory);
        [$fileVariantCandidates, $fileSourceCandidates] = $this->collectFilePatternCandidates($targetRoot, $directory);
        $variantCandidates = $variantCandidates->merge($fileVariantCandidates)->unique()->values();
        $sourceCandidates = $sourceCandidates->merge($fileSourceCandidates)->unique()->values();
        $candidates = $this->existingFiles($storageRoot, $variantCandidates);

        if ($this->option('delete-sources')) {
            $candidates = $candidates->merge($this->existingFiles($storageRoot, $sourceCandidates));
        }

        $candidates = $candidates
            ->reject(fn (string $path) => $keptPaths->contains($path))
            ->unique()
            ->values();

        $bytes = $candidates->sum(fn (string $path) => File::size($storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path)));

        $this->table(['Metric', 'Value'], [
            ['Kept referenced paths', $keptPaths->count()],
            ['Files to delete', $candidates->count()],
            ['Estimated freed space', $this->humanBytes($bytes)],
            ['Mode', $this->option('force') ? 'force' : 'dry-run'],
        ]);

        foreach ($candidates->take(20) as $path) {
            $this->line('delete: '.$path);
        }
        if ($candidates->count() > 20) {
            $this->line('...and '.($candidates->count() - 20).' more');
        }

        if (! $this->option('force')) {
            $this->info('Dry run only. Re-run with --force to delete files and clear responsive references.');

            return self::SUCCESS;
        }

        foreach ($candidates as $path) {
            File::delete($storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        }

        Post::query()->where(function ($query) {
            $query->whereNotNull('featured_image_responsive')
                ->orWhereNotNull('og_image_responsive');
        })->update([
            'featured_image_responsive' => null,
            'og_image_responsive' => null,
        ]);

        Media::query()->whereNotNull('responsive_paths')->update([
            'responsive_paths' => null,
        ]);

        Post::invalidatePortalContentCacheForImages();

        $this->info('Responsive variants pruned.');

        return self::SUCCESS;
    }

    private function collectReferencedPaths(string $directory): array
    {
        $keptPaths = collect();
        $variantCandidates = collect();
        $sourceCandidates = collect();

        Post::query()->select([
            'id',
            'featured_image',
            'featured_image_responsive',
            'og_image',
            'og_image_responsive',
            'content',
        ])->chunkById(200, function ($posts) use ($directory, $keptPaths, $variantCandidates, $sourceCandidates): void {
            foreach ($posts as $post) {
                $this->addPath($keptPaths, $post->featured_image, $directory);
                $this->addPath($keptPaths, $post->og_image, $directory);
                $this->addPathsFromHtml($keptPaths, (string) $post->content, $directory);
                $this->addResponsiveCandidates($variantCandidates, $sourceCandidates, $post->featured_image_responsive, $directory);
                $this->addResponsiveCandidates($variantCandidates, $sourceCandidates, $post->og_image_responsive, $directory);
            }
        });

        Media::query()->select(['id', 'path', 'responsive_paths'])->chunkById(200, function ($mediaItems) use ($directory, $keptPaths, $variantCandidates, $sourceCandidates): void {
            foreach ($mediaItems as $media) {
                $this->addPath($keptPaths, $media->path, $directory);
                $this->addResponsiveCandidates($variantCandidates, $sourceCandidates, $media->responsive_paths, $directory);
            }
        });

        $this->addSocialImagePairs($keptPaths);

        return [
            $keptPaths->unique()->values(),
            $variantCandidates->unique()->values(),
            $sourceCandidates->unique()->values(),
        ];
    }

    private function addResponsiveCandidates($variantCandidates, $sourceCandidates, mixed $responsive, string $directory): void
    {
        if (! is_array($responsive)) {
            return;
        }

        foreach (Arr::get($responsive, 'variants', []) as $variant) {
            $this->addPath($variantCandidates, is_array($variant) ? ($variant['path'] ?? null) : null, $directory);
        }

        $this->addPath($sourceCandidates, Arr::get($responsive, 'original'), $directory);
    }

    private function addPathsFromHtml($paths, string $html, string $directory): void
    {
        if ($html === '') {
            return;
        }

        preg_match_all('#/storage/('.preg_quote($directory, '#').'/[^"\')\s<>]+)#i', $html, $matches);
        foreach ($matches[1] ?? [] as $path) {
            $this->addPath($paths, html_entity_decode($path), $directory);
        }
    }

    private function addSocialImagePairs($paths): void
    {
        foreach ($paths->values() as $path) {
            if (Str::endsWith($path, '-social.jpg')) {
                continue;
            }

            $socialPath = preg_replace('/\.[^.]+$/', '-social.jpg', $path);
            if (is_string($socialPath)) {
                $paths->push($socialPath);
            }
        }
    }

    private function collectFilePatternCandidates(string $targetRoot, string $directory): array
    {
        $variantCandidates = collect();
        $sourceCandidates = collect();

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($targetRoot) + 1);
            $relative = $directory.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $name = $file->getFilename();

            if (preg_match('/-(?:480|768|1600)\.webp$/i', $name)) {
                $variantCandidates->push($relative);
            }

            if (preg_match('/-source\.[^.]+$/i', $name)) {
                $sourceCandidates->push($relative);
            }
        }

        return [$variantCandidates->unique()->values(), $sourceCandidates->unique()->values()];
    }

    private function addPath($paths, ?string $path, string $directory): void
    {
        if (! $path) {
            return;
        }

        $path = ltrim(preg_replace('#^https?://[^/]+/storage/#i', '', $path), '/');
        $path = ltrim(preg_replace('#^/storage/#i', '', $path), '/');

        if (Str::startsWith($path, $directory.'/')) {
            $paths->push($path);
        }
    }

    private function existingFiles(string $storageRoot, $paths)
    {
        return $paths->filter(function (string $path) use ($storageRoot): bool {
            return File::isFile($storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        })->values();
    }

    private function humanBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return number_format($bytes, $index === 0 ? 0 : 2).' '.$units[$index];
    }
}
