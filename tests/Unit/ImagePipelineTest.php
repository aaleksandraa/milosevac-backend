<?php

namespace Tests\Unit;

use App\Support\ImagePipeline;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImagePipelineTest extends TestCase
{
    public function test_discovers_responsive_variants_from_wordpress_style_filename(): void
    {
        $base = 'wordpress/2026/06/sample-1200.webp';
        $directory = storage_path('app/public/wordpress/2026/06');
        File::ensureDirectoryExists($directory);

        foreach ([480, 768, 1200] as $width) {
            File::put(storage_path('app/public/wordpress/2026/06/sample-'.$width.'.webp'), 'webp');
        }

        $responsive = (new ImagePipeline())->discoverResponsiveVariants($base);

        $this->assertNotNull($responsive);
        $this->assertSame('wordpress/2026/06/sample-1200.webp', $responsive['original']);
        $this->assertCount(3, $responsive['variants']);
        $this->assertSame('wordpress/2026/06/sample-480.webp', $responsive['variants'][0]['path']);

        File::deleteDirectory(storage_path('app/public/wordpress'));
    }
}
