<?php

namespace App\Support\PowerOutage;

use App\Models\Post;
use App\Support\ImagePipeline;
use Illuminate\Support\Facades\Storage;

class PowerOutageFeaturedImage
{
    public function __construct(private ImagePipeline $pipeline = new ImagePipeline()) {}

    /** @return array{path: ?string, responsive: ?array, alt: string, og_image: ?string, og_image_responsive: ?array} */
    public function bundle(?string $currentPath = null, ?array $currentResponsive = null): array
    {
        $path = $this->resolvePath($currentPath);
        $responsive = $this->resolveResponsive($path, $currentResponsive);
        $alt = (string) config('outages.default_image_alt', 'Planirani prekid struje');
        $ogImage = $path ? ($this->pipeline->socialImage($path) ?: $path) : null;

        return [
            'path' => $path,
            'responsive' => $responsive,
            'alt' => $alt,
            'og_image' => $ogImage,
            'og_image_responsive' => $responsive,
        ];
    }

    /** @return array{image: ?string, imageSrcSet: ?string, imageAlt: string} */
    public function apiPayload(Post $post): array
    {
        if ($post->service_type !== 'power_outage') {
            return [
                'image' => $this->storageUrl($post->featured_image),
                'imageSrcSet' => $this->relativeSrcSet($post->featured_image_responsive),
                'imageAlt' => $post->featured_image_alt ?: $post->title,
            ];
        }

        $bundle = $this->bundle($post->featured_image, $post->featured_image_responsive);

        return [
            'image' => $this->storageUrl($bundle['path']),
            'imageSrcSet' => $this->relativeSrcSet($bundle['responsive']),
            'imageAlt' => $post->featured_image_alt ?: $bundle['alt'],
        ];
    }

    public function resolvePath(?string $path): ?string
    {
        $path = trim((string) ($path ?: Post::powerOutageDefaultImagePath()), '/');
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            return $path;
        }

        $default = trim((string) Post::powerOutageDefaultImagePath(), '/');
        if ($default !== '' && Storage::disk('public')->exists($default)) {
            return $default;
        }

        return $path !== '' ? $path : ($default !== '' ? $default : null);
    }

    /** @return array{original: string, variants: list<array{width: int, path: string, mime: string}>}|null */
    public function resolveResponsive(?string $path, ?array $responsive = null): ?array
    {
        if ($this->responsiveHasStoredVariants($responsive)) {
            return $responsive;
        }

        if (! $path) {
            return null;
        }

        return $this->pipeline->discoverResponsiveVariants($path);
    }

    /** @param array<string, mixed>|null $responsive */
    private function responsiveHasStoredVariants(?array $responsive): bool
    {
        return collect($responsive['variants'] ?? [])
            ->contains(fn (array $variant) => ! empty($variant['path']) && Storage::disk('public')->exists($variant['path']));
    }

    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return '/storage/'.ltrim($path, '/');
    }

    /** @param array<string, mixed>|null $responsive */
    private function relativeSrcSet(?array $responsive): ?string
    {
        $srcSet = collect($responsive['variants'] ?? [])
            ->filter(fn (array $variant) => ! empty($variant['path']) && Storage::disk('public')->exists($variant['path']))
            ->map(fn (array $variant) => '/storage/'.ltrim($variant['path'], '/').' '.$variant['width'].'w')
            ->implode(', ');

        return $srcSet !== '' ? $srcSet : null;
    }
}
