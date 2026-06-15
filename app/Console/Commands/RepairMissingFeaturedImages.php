<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Post;
use App\Support\ImagePipeline;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

class RepairMissingFeaturedImages extends Command
{
    protected $signature = 'posts:repair-missing-featured-images
        {file=database/imports/miloevac.WordPress.2026-05-10.xml : WordPress WXR export path}
        {--slug= : Repair only one post slug}
        {--limit= : Stop after repairing this many posts}
        {--dry-run : Show repairable posts without changing anything}';

    protected $description = 'Fill missing featured images without reimporting or reseeding posts.';

    /** @var array<int, array{url: string, alt: ?string}> */
    private array $attachments = [];

    private int $repaired = 0;

    private int $failed = 0;

    public function handle(): int
    {
        $path = base_path($this->argument('file'));
        if (! File::exists($path)) {
            $this->error("WordPress export not found: {$path}");

            return self::FAILURE;
        }

        $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (! $xml instanceof SimpleXMLElement) {
            $this->error('WordPress export could not be read.');

            return self::FAILURE;
        }

        $items = $xml->channel->item ?? [];
        $this->indexAttachments($items);
        $limit = $this->option('limit') ? max(1, (int) $this->option('limit')) : null;

        foreach ($items as $item) {
            if ($this->wp($item, 'post_type') !== 'post') {
                continue;
            }

            $slug = trim($this->wp($item, 'post_name')) ?: Str::slug(trim((string) $item->title));
            if ($this->option('slug') && $slug !== $this->option('slug')) {
                continue;
            }

            $post = Post::query()->where('slug', $slug)->whereNull('featured_image')->first();
            if (! $post) {
                continue;
            }

            $source = $this->sourceFor($item, $post);
            if (! $source) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Repairable: {$slug} <- {$source['label']}");
                $this->repaired++;
            } elseif ($this->repair($post, $source)) {
                $this->info("Repaired: {$slug}");
                $this->repaired++;
            } else {
                $this->warn("Image unavailable: {$slug} <- {$source['label']}");
                $this->failed++;
            }

            if ($limit !== null && $this->repaired >= $limit) {
                break;
            }
        }

        if ($this->repaired > 0 && ! $this->option('dry-run')) {
            Cache::add('api.content.version', 1);
            Cache::increment('api.content.version');
        }

        $this->newLine();
        $this->info("Finished. Repaired: {$this->repaired}, unavailable: {$this->failed}.");

        return self::SUCCESS;
    }

    private function indexAttachments(iterable $items): void
    {
        foreach ($items as $item) {
            if ($this->wp($item, 'post_type') !== 'attachment') {
                continue;
            }

            $id = (int) $this->wp($item, 'post_id');
            $url = trim($this->wp($item, 'attachment_url'));
            if (! $id || $url === '') {
                continue;
            }

            $meta = $this->meta($item);
            $this->attachments[$id] = [
                'url' => $this->normalizeWordpressUrl($url),
                'alt' => $meta['_wp_attachment_image_alt'] ?? trim((string) $item->title) ?: null,
            ];
        }
    }

    /**
     * @return array{path?: string, responsive?: array, url?: string, alt: string, label: string}|null
     */
    private function sourceFor(SimpleXMLElement $item, Post $post): ?array
    {
        if (preg_match('/\/storage\/(wordpress\/[^\s"\']+\.(?:jpe?g|png|webp|gif))/i', $post->content, $match)) {
            $media = Media::query()->where('path', $match[1])->first();
            if ($media && File::exists(storage_path('app/public/'.$media->path))) {
                return [
                    'path' => $media->path,
                    'responsive' => $media->responsive_paths ?: [],
                    'alt' => $media->alt_text ?: $post->title,
                    'label' => $media->path,
                ];
            }
        }

        $meta = $this->meta($item);
        $thumbnailId = isset($meta['_thumbnail_id']) ? (int) $meta['_thumbnail_id'] : null;
        $attachment = $thumbnailId ? ($this->attachments[$thumbnailId] ?? null) : null;
        $url = $attachment['url'] ?? $this->firstContentImage($this->namespacedValue($item, 'content', 'encoded'));
        if (! $url) {
            return null;
        }

        $existing = Media::query()->where('source_url', $url)->first();
        if ($existing && File::exists(storage_path('app/public/'.$existing->path))) {
            return [
                'path' => $existing->path,
                'responsive' => $existing->responsive_paths ?: [],
                'alt' => $attachment['alt'] ?? $existing->alt_text ?? $post->title,
                'label' => $existing->path,
            ];
        }

        return [
            'url' => $url,
            'alt' => $attachment['alt'] ?? $post->title,
            'label' => $url,
        ];
    }

    private function repair(Post $post, array $source): bool
    {
        $image = isset($source['path']) ? $source : $this->download($source['url'], $post, $source['alt']);
        if (! $image) {
            return false;
        }

        Post::query()->whereKey($post->id)->update([
            'featured_image' => $image['path'],
            'featured_image_alt' => $source['alt'],
            'featured_image_responsive' => $image['responsive'] ?? [],
            'og_image' => $post->og_image ?: $image['path'],
            'og_image_responsive' => $post->og_image_responsive ?: ($image['responsive'] ?? []),
        ]);

        Media::query()->where('path', $image['path'])->whereNull('post_id')->update(['post_id' => $post->id]);

        return true;
    }

    private function download(string $url, Post $post, string $alt): ?array
    {
        try {
            $response = Http::withOptions([
                'verify' => config('services.wordpress.verify_ssl', true),
            ])->timeout(30)->retry(2, 500)->get($url);
            if (! $response->successful()) {
                return null;
            }

            $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'jpg';
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $extension = 'jpg';
            }

            $tmpDirectory = storage_path('app/tmp/featured-image-repair');
            File::ensureDirectoryExists($tmpDirectory);
            $tmpPath = $tmpDirectory.'/'.Str::uuid().'.'.$extension;
            File::put($tmpPath, $response->body());

            $uploaded = new UploadedFile(
                $tmpPath,
                basename(parse_url($url, PHP_URL_PATH) ?: 'featured-image.'.$extension),
                mime_content_type($tmpPath) ?: null,
                null,
                true
            );
            $processed = app(ImagePipeline::class)->process($uploaded, null, $post->id, 'wordpress');
            @unlink($tmpPath);

            Media::query()->where('path', $processed['path'])->update([
                'source_url' => $url,
                'alt_text' => $alt,
            ]);

            return $processed;
        } catch (Throwable $exception) {
            $this->warn($exception->getMessage());

            return null;
        }
    }

    private function firstContentImage(string $html): ?string
    {
        return preg_match('/https?:\/\/[^\s"\']+wp-content\/uploads\/[^\s"\']+\.(?:jpe?g|png|webp|gif)/i', $html, $match)
            ? $this->normalizeWordpressUrl(html_entity_decode($match[0]))
            : null;
    }

    private function normalizeWordpressUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host && Str::endsWith($host, 'milosevac.com')
            ? (preg_replace('/^http:\/\//i', 'https://', $url) ?: $url)
            : $url;
    }

    private function meta(SimpleXMLElement $item): array
    {
        $meta = [];
        foreach ($this->wpChildren($item)->postmeta ?? [] as $postmeta) {
            $children = $postmeta->children('wp', true);
            $key = trim((string) $children->meta_key);
            if ($key !== '') {
                $meta[$key] = trim((string) $children->meta_value);
            }
        }

        return $meta;
    }

    private function wp(SimpleXMLElement $item, string $field): string
    {
        return trim((string) ($this->wpChildren($item)->{$field} ?? ''));
    }

    private function wpChildren(SimpleXMLElement $item): SimpleXMLElement
    {
        return $item->children('wp', true);
    }

    private function namespacedValue(SimpleXMLElement $item, string $namespace, string $field): string
    {
        return (string) ($item->children($namespace, true)->{$field} ?? '');
    }
}
