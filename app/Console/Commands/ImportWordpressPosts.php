<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\ImagePipeline;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

class ImportWordpressPosts extends Command
{
    protected $signature = 'wordpress:import-posts
        {file=database/imports/miloevac.WordPress.2026-05-10.xml : WordPress WXR export path}
        {--author= : Existing user ID or email to assign imported posts}
        {--slug= : Import only the post with this slug}
        {--limit= : Import only the first N posts}
        {--skip-images : Import content without downloading images}
        {--retry-failed-images : Retry image URLs previously confirmed as missing}';

    protected $description = 'Import WordPress WXR posts, SEO metadata, categories, tags, and images.';

    /** @var array<int, array{url: string, alt: ?string, title: ?string}> */
    private array $attachmentsById = [];

    /** @var array<string, array{path: string, responsive: array, alt: ?string}> */
    private array $downloadedImages = [];

    /** @var array<string, true> */
    private array $permanentlyMissingImages = [];

    private int $created = 0;

    private int $updated = 0;

    private int $imagesImported = 0;

    private int $imagesFailed = 0;

    public function handle(): int
    {
        $this->loadMissingImages();
        $path = base_path($this->argument('file'));

        if (! File::exists($path)) {
            $this->error("WordPress export nije pronadjen: {$path}");

            return self::FAILURE;
        }

        $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NOCDATA);
        if (! $xml instanceof SimpleXMLElement) {
            $this->error('XML nije moguce procitati.');

            return self::FAILURE;
        }

        $items = $xml->channel->item ?? [];
        $this->indexAttachments($items);
        $this->indexExistingLocalImages($items);

        $author = $this->resolveAuthor();
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $imported = 0;

        foreach ($items as $item) {
            if ($this->wp($item, 'post_type') !== 'post') {
                continue;
            }

            $slug = trim($this->wp($item, 'post_name')) ?: Str::slug(trim((string) $item->title));
            if ($this->option('slug') && $slug !== $this->option('slug')) {
                continue;
            }

            $status = $this->wp($item, 'status');
            if (! in_array($status, ['publish', 'future', 'draft', 'pending'], true)) {
                continue;
            }

            $this->importPost($item, $author);
            $imported++;

            if ($limit !== null && $imported >= $limit) {
                break;
            }
        }

        $this->newLine();
        $this->info("Import zavrsen. Novi: {$this->created}, azurirani: {$this->updated}, slike: {$this->imagesImported}, neuspjele slike: {$this->imagesFailed}.");

        return self::SUCCESS;
    }

    private function resolveAuthor(): User
    {
        $value = $this->option('author');

        if ($value) {
            $query = User::query();
            $user = is_numeric($value)
                ? $query->whereKey((int) $value)->first()
                : $query->where('email', $value)->first();

            if ($user) {
                return $user;
            }
        }

        return User::query()->firstOrCreate(
            ['email' => 'import@milosevac.local'],
            ['name' => 'Miloševac', 'slug' => 'milosevac', 'password' => Str::password(32)]
        );
    }

    private function importPost(SimpleXMLElement $item, User $author): void
    {
        $postId = (int) $this->wp($item, 'post_id');
        $title = trim((string) $item->title);
        $slug = trim($this->wp($item, 'post_name')) ?: Str::slug($title);
        $slug = $slug !== '' ? $slug : 'wordpress-'.$postId;
        $content = $this->namespacedValue($item, 'content', 'encoded');
        $excerpt = trim($this->namespacedValue($item, 'excerpt', 'encoded'));
        $meta = $this->meta($item);
        $categoryIds = $this->categoryIds($item);
        $tagIds = $this->tagIds($item, $meta);
        $publishedAt = $this->publishedAt($item);
        $status = $this->mappedStatus($this->wp($item, 'status'));
        $thumbnailId = isset($meta['_thumbnail_id']) ? (int) $meta['_thumbnail_id'] : null;

        $post = Post::query()->firstOrNew(['slug' => $slug]);
        $exists = $post->exists;

        $localContent = $this->option('skip-images')
            ? $content
            : $this->localizeContentImages($content, $post);

        $featuredData = $this->option('skip-images') || ! $thumbnailId || $post->featured_image
            ? null
            : $this->downloadAttachment($thumbnailId, $post, $title);

        $post->fill([
            'author_id' => $author->id,
            'category_id' => $categoryIds[0] ?? $this->fallbackCategory()->id,
            'title' => $title,
            'excerpt' => $excerpt ?: null,
            'content' => $localContent,
            'status' => $status,
            'published_at' => $publishedAt,
            'scheduled_at' => $status === 'scheduled' ? $publishedAt : null,
            'meta_title' => $meta['_aioseop_title'] ?? $meta['_yoast_wpseo_title'] ?? null,
            'meta_description' => $meta['_aioseop_description'] ?? $meta['_yoast_wpseo_metadesc'] ?? null,
            'canonical_url' => route('posts.show', $slug, false),
            'featured_image' => $featuredData['path'] ?? $post->featured_image,
            'featured_image_alt' => $featuredData['alt'] ?? $post->featured_image_alt ?? $title,
            'featured_image_responsive' => $featuredData['responsive'] ?? $post->featured_image_responsive,
            'og_image' => $featuredData['path'] ?? $post->og_image,
            'og_image_responsive' => $featuredData['responsive'] ?? $post->og_image_responsive,
            'seo_metadata' => array_filter([
                'wordpress_id' => $postId,
                'wordpress_link' => (string) $item->link,
                'aioseo_keywords' => $meta['_aioseop_keywords'] ?? null,
                'wordpress_meta' => array_intersect_key($meta, array_flip([
                    '_aioseop_title',
                    '_aioseop_description',
                    '_aioseop_keywords',
                    '_yoast_wpseo_title',
                    '_yoast_wpseo_metadesc',
                ])),
            ]),
        ]);

        $post->save();
        $post->categories()->sync($categoryIds ?: [$post->category_id]);
        $post->tags()->sync($tagIds);

        Media::query()
            ->whereNull('post_id')
            ->whereIn('path', collect($this->downloadedImages)->pluck('path')->all())
            ->update(['post_id' => $post->id]);

        $exists ? $this->updated++ : $this->created++;
        $this->line(($exists ? 'Azuriran' : 'Kreiran').' clanak: '.$post->slug);
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
            $this->attachmentsById[$id] = [
                'url' => $url,
                'alt' => $meta['_wp_attachment_image_alt'] ?? null,
                'title' => trim((string) $item->title) ?: null,
            ];
        }
    }

    /**
     * @return array<int>
     */
    private function categoryIds(SimpleXMLElement $item): array
    {
        $ids = [];

        foreach ($item->category as $category) {
            $attributes = $category->attributes();
            if ((string) ($attributes['domain'] ?? '') !== 'category') {
                continue;
            }

            $name = trim((string) $category);
            $slug = trim((string) ($attributes['nicename'] ?? '')) ?: Str::slug($name);
            if ($name === '' || $slug === '') {
                continue;
            }

            $model = Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'color' => '#9f1d1d', 'is_active' => true]
            );
            $ids[] = $model->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int>
     */
    private function tagIds(SimpleXMLElement $item, array $meta): array
    {
        $ids = [];

        foreach ($item->category as $category) {
            $attributes = $category->attributes();
            if ((string) ($attributes['domain'] ?? '') !== 'post_tag') {
                continue;
            }

            $name = trim((string) $category);
            $slug = trim((string) ($attributes['nicename'] ?? '')) ?: Str::slug($name);
            if ($name === '' || $slug === '') {
                continue;
            }

            $ids[] = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        foreach (explode(',', (string) ($meta['_aioseop_keywords'] ?? '')) as $keyword) {
            $name = trim($keyword);
            $slug = Str::slug($name);
            if ($name === '' || $slug === '') {
                continue;
            }

            $ids[] = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        return array_values(array_unique($ids));
    }

    private function fallbackCategory(): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => 'vijesti'],
            ['name' => 'Vijesti', 'color' => '#9f1d1d', 'is_active' => true]
        );
    }

    private function localizeContentImages(string $html, Post $post): string
    {
        if (trim($html) === '' || ! preg_match('/<(img|a)\b/i', $html)) {
            return $html;
        }

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        foreach ($document->getElementsByTagName('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $src = $image->getAttribute('src');
            $attachmentId = $this->attachmentIdFromImage($image);
            if (! $this->isUploadImageUrl($src) && ! $attachmentId) {
                continue;
            }

            $alt = $image->getAttribute('alt') ?: null;
            $local = $this->downloadImageUrl($src, $post, $alt);
            if (! $local && $attachmentId) {
                $local = $this->downloadAttachment($attachmentId, $post, $alt ?: $post->title);
            }

            if (! $local) {
                if (! $this->isUploadImageUrl($src) || $this->isPermanentlyMissing($src)) {
                    $image->parentNode?->removeChild($image);
                }

                continue;
            }

            $image->setAttribute('src', $this->storageUrl($local['path']));
            $image->removeAttribute('srcset');
            $image->removeAttribute('sizes');
        }

        foreach ($document->getElementsByTagName('a') as $link) {
            if (! $link instanceof DOMElement || ! $this->isUploadImageUrl($link->getAttribute('href'))) {
                continue;
            }

            $local = $this->downloadImageUrl($link->getAttribute('href'), $post, $link->getAttribute('title') ?: null);
            if ($local) {
                $link->setAttribute('href', $this->storageUrl($local['path']));
            } elseif ($this->isPermanentlyMissing($link->getAttribute('href'))) {
                $link->removeAttribute('href');
            }
        }

        $wrapper = $document->getElementsByTagName('div')->item(0);
        $output = '';
        if ($wrapper) {
            foreach ($wrapper->childNodes as $child) {
                $output .= $document->saveHTML($child);
            }
        }

        $output = $output ?: $html;

        $output = preg_replace_callback(
            '/https?:\/\/[^\s"\']+wp-content\/uploads\/[^\s"\']+\.(?:jpe?g|png|webp|gif)/i',
            function (array $match) use ($post) {
                $url = html_entity_decode($match[0]);
                $local = $this->downloadImageUrl($url, $post);

                if ($local) {
                    return $this->storageUrl($local['path']);
                }

                return $this->isPermanentlyMissing($url) ? '' : $match[0];
            },
            $output
        ) ?: $output;

        return preg_replace('/<img\b[^>]*\bsrc=(["\'])\1[^>]*>/i', '', $output) ?: $output;
    }

    private function attachmentIdFromImage(DOMElement $image): ?int
    {
        return preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $image->getAttribute('class'), $match)
            ? (int) $match[1]
            : null;
    }

    private function downloadAttachment(int $attachmentId, Post $post, ?string $fallbackAlt): ?array
    {
        $attachment = $this->attachmentsById[$attachmentId] ?? null;
        if (! $attachment) {
            return null;
        }

        return $this->downloadImageUrl($attachment['url'], $post, $attachment['alt'] ?: $attachment['title'] ?: $fallbackAlt);
    }

    private function storageUrl(string $path): string
    {
        return '/storage/'.ltrim($path, '/');
    }

    private function downloadImageUrl(?string $url, Post $post, ?string $alt = null): ?array
    {
        $url = trim((string) $url);
        if ($url === '' || ! Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }
        $url = $this->normalizeWordpressUrl($url);

        if (isset($this->downloadedImages[$url])) {
            return $this->downloadedImages[$url];
        }

        if (! $this->option('retry-failed-images') && isset($this->permanentlyMissingImages[$url])) {
            return null;
        }

        $existing = Media::query()->where('source_url', $url)->first();
        if ($existing && File::exists(storage_path('app/public/'.$existing->path))) {
            return $this->downloadedImages[$url] = [
                'path' => $existing->path,
                'responsive' => $existing->responsive_paths ?: [],
                'alt' => $alt ?: $existing->alt_text,
            ];
        }

        try {
            $response = Http::withOptions([
                'verify' => config('services.wordpress.verify_ssl', true),
            ])->timeout(30)->retry(2, 500)->get($url);
            if (! $response->successful()) {
                $this->imagesFailed++;
                if (in_array($response->status(), [404, 410], true)) {
                    $this->rememberMissingImage($url);
                }

                return null;
            }

            $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'jpg';
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $extension = 'jpg';
            }

            $tmpDirectory = storage_path('app/tmp/wordpress-import');
            File::ensureDirectoryExists($tmpDirectory);
            $tmpPath = $tmpDirectory.'/'.Str::uuid().'.'.$extension;
            File::put($tmpPath, $response->body());

            $uploaded = new UploadedFile($tmpPath, basename(parse_url($url, PHP_URL_PATH) ?: 'wordpress-image.'.$extension), mime_content_type($tmpPath) ?: null, null, true);
            $processed = app(ImagePipeline::class)->process($uploaded, null, $post->exists ? $post->id : null, 'wordpress');
            @unlink($tmpPath);

            Media::query()->where('path', $processed['path'])->update([
                'source_url' => $url,
                'alt_text' => $alt,
            ]);

            $this->imagesImported++;
            unset($this->permanentlyMissingImages[$url]);
            $this->saveMissingImages();

            return $this->downloadedImages[$url] = [
                'path' => $processed['path'],
                'responsive' => $processed['responsive'],
                'alt' => $alt,
            ];
        } catch (Throwable) {
            $this->imagesFailed++;

            return null;
        }
    }

    private function indexExistingLocalImages(iterable $items): void
    {
        foreach ($items as $item) {
            if ($this->wp($item, 'post_type') !== 'post') {
                continue;
            }

            $slug = trim($this->wp($item, 'post_name')) ?: Str::slug(trim((string) $item->title));
            $post = Post::query()->where('slug', $slug)->first();
            if (! $post) {
                continue;
            }

            $meta = $this->meta($item);
            $thumbnailId = isset($meta['_thumbnail_id']) ? (int) $meta['_thumbnail_id'] : null;
            $thumbnailUrl = $thumbnailId ? data_get($this->attachmentsById, "{$thumbnailId}.url") : null;
            if ($thumbnailUrl && $post->featured_image) {
                $this->rememberExistingMedia($post->featured_image, $this->normalizeWordpressUrl($thumbnailUrl));
            }

            $sourceImages = $this->imageUrls($this->namespacedValue($item, 'content', 'encoded'));
            $localImages = $this->localStoragePaths($post->content);
            foreach ($sourceImages as $index => $sourceUrl) {
                if (isset($localImages[$index])) {
                    $this->rememberExistingMedia($localImages[$index], $this->normalizeWordpressUrl($sourceUrl));
                }
            }
        }
    }

    private function rememberExistingMedia(string $path, string $sourceUrl): void
    {
        $media = Media::query()->where('path', $path)->first();
        if (! $media || $media->source_url) {
            return;
        }

        $media->update(['source_url' => $sourceUrl]);
        $this->downloadedImages[$sourceUrl] = [
            'path' => $media->path,
            'responsive' => $media->responsive_paths ?: [],
            'alt' => $media->alt_text,
        ];
    }

    /** @return array<int, string> */
    private function imageUrls(string $html): array
    {
        preg_match_all('/https?:\/\/[^\s"\']+wp-content\/uploads\/[^\s"\']+\.(?:jpe?g|png|webp|gif)/i', $html, $matches);

        return array_values(array_unique(array_map(fn ($url) => html_entity_decode($url), $matches[0] ?? [])));
    }

    /** @return array<int, string> */
    private function localStoragePaths(string $html): array
    {
        preg_match_all('/\/storage\/(wordpress\/[^\s"\']+\.(?:jpe?g|png|webp|gif))/i', $html, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function isUploadImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return str_contains($url, 'wp-content/uploads/')
            && (bool) preg_match('/\.(jpe?g|png|webp|gif)$/i', $path);
    }

    private function normalizeWordpressUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && Str::endsWith($host, 'milosevac.com')) {
            return preg_replace('/^http:\/\//i', 'https://', $url) ?: $url;
        }

        return $url;
    }

    private function isPermanentlyMissing(string $url): bool
    {
        return isset($this->permanentlyMissingImages[$this->normalizeWordpressUrl($url)]);
    }

    private function rememberMissingImage(string $url): void
    {
        $this->permanentlyMissingImages[$url] = true;
        $this->saveMissingImages();
    }

    private function loadMissingImages(): void
    {
        $path = storage_path('app/wordpress-import-missing-images.json');
        if (! File::exists($path)) {
            return;
        }

        $urls = json_decode(File::get($path), true);
        if (is_array($urls)) {
            $this->permanentlyMissingImages = array_fill_keys($urls, true);
        }
    }

    private function saveMissingImages(): void
    {
        File::put(
            storage_path('app/wordpress-import-missing-images.json'),
            json_encode(array_keys($this->permanentlyMissingImages), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function meta(SimpleXMLElement $item): array
    {
        $meta = [];

        foreach ($this->wpChildren($item)->postmeta ?? [] as $postmeta) {
            $children = $postmeta->children('wp', true);
            $key = trim((string) $children->meta_key);
            if ($key === '') {
                continue;
            }

            $meta[$key] = trim((string) $children->meta_value);
        }

        return $meta;
    }

    private function publishedAt(SimpleXMLElement $item): ?Carbon
    {
        $date = $this->wp($item, 'post_date') ?: (string) $item->pubDate;

        return $date ? Carbon::parse($date) : null;
    }

    private function mappedStatus(string $wpStatus): string
    {
        return match ($wpStatus) {
            'publish' => 'published',
            'future' => 'scheduled',
            'pending' => 'pending_review',
            default => 'draft',
        };
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
