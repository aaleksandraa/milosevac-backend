<?php

namespace App\Support;

use App\Models\Media;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class ImagePipeline
{
    private const WIDTHS = [480, 768, 1200, 1600];

    public function process(UploadedFile $file, ?int $userId = null, ?int $postId = null, string $directory = 'posts', bool $watermark = false): array
    {
        $manager = ImageManager::gd();
        $image = $manager->read($file->getRealPath());
        $baseName = Str::uuid()->toString();
        $relativeDirectory = trim($directory, '/').'/'.now()->format('Y/m');
        $absoluteDirectory = storage_path('app/public/'.$relativeDirectory);
        File::ensureDirectoryExists($absoluteDirectory);

        $responsive = [];
        foreach (self::WIDTHS as $width) {
            $variant = clone $image;
            if ($watermark) {
                $this->applyWatermark($variant, $manager, $width);
            }
            $encoded = $variant->scaleDown(width: $width)->toWebp(quality: 82);
            $path = "{$relativeDirectory}/{$baseName}-{$width}.webp";
            $encoded->save(storage_path('app/public/'.$path));
            $responsive[] = ['width' => $width, 'path' => $path, 'mime' => 'image/webp'];
        }

        $originalPath = "{$relativeDirectory}/{$baseName}-source.".$file->extension();
        $file->storeAs($relativeDirectory, "{$baseName}-source.".$file->extension(), 'public');

        $primary = collect($responsive)->firstWhere('width', 1200) ?? end($responsive);

        Media::create([
            'user_id' => $userId,
            'post_id' => $postId,
            'disk' => 'public',
            'path' => $primary['path'],
            'filename' => basename($primary['path']),
            'mime_type' => 'image/webp',
            'size' => filesize(storage_path('app/public/'.$primary['path'])) ?: 0,
            'alt_text' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'responsive_paths' => [
                'original' => $originalPath,
                'variants' => $responsive,
            ],
        ]);

        return [
            'path' => $primary['path'],
            'responsive' => [
                'original' => $originalPath,
                'variants' => $responsive,
            ],
        ];
    }

    private function applyWatermark($image, ImageManager $manager, int $targetWidth): void
    {
        $settings = Setting::where('key', 'gallery_watermark')->first()?->value ?? [];
        $path = $settings['path'] ?? null;
        if (! $path || ! file_exists(storage_path('app/public/'.$path))) {
            return;
        }

        $opacity = max(0, min(100, (int) ($settings['opacity'] ?? 35)));
        $watermark = $manager->read(storage_path('app/public/'.$path));
        $watermark->scaleDown(width: max(72, (int) round($targetWidth * 0.18)));
        $image->place($watermark, 'bottom-right', 24, 24, $opacity);
    }

    public static function srcset(?array $responsive): ?string
    {
        if (! $responsive || empty($responsive['variants'])) {
            return null;
        }

        return collect($responsive['variants'])
            ->map(fn (array $variant) => asset('storage/'.$variant['path']).' '.$variant['width'].'w')
            ->implode(', ');
    }

    public function socialImage(?string $sourcePath, bool $force = false): ?string
    {
        if (! $sourcePath || Str::startsWith($sourcePath, ['http://', 'https://'])) {
            return $sourcePath;
        }

        $source = storage_path('app/public/'.$sourcePath);
        if (! File::exists($source)) {
            return null;
        }

        $targetPath = preg_replace('/\.[^.]+$/', '-social.jpg', $sourcePath);
        $target = storage_path('app/public/'.$targetPath);
        if ($force || ! File::exists($target)) {
            File::ensureDirectoryExists(dirname($target));
            ImageManager::gd()->read($source)->scale(width: 1200)->toJpeg(quality: 86)->save($target);
        }

        return $targetPath;
    }
}
