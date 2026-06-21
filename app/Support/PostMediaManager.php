<?php

namespace App\Support;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostMediaManager
{
    public function appendContentImages(Request $request, Post $post): void
    {
        $figures = [];

        foreach ($request->file('content_images', []) as $image) {
            $processed = app(ImagePipeline::class)->process($image, $request->user()?->id, $post->id, 'posts/content');
            $media = Media::where('path', $processed['path'])->latest('id')->first();
            if (! $media) {
                continue;
            }

            $alt = $media->alt_text ?: $post->title;
            $media->update([
                'post_id' => $post->id,
                'media_type' => 'post_content',
                'caption' => $alt,
            ]);

            $figures[] = sprintf(
                '<figure><img src="/storage/%s" alt="%s"><figcaption>%s</figcaption></figure>',
                e(ltrim($media->path, '/')),
                e($alt),
                e($alt)
            );
        }

        if ($figures === []) {
            return;
        }

        $post->content = trim($post->content)."\n\n".implode("\n\n", $figures);
        $post->save();
    }

    public function syncGallery(Request $request, Post $post): void
    {
        $deleteIds = collect($request->input('delete_gallery', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($deleteIds->isNotEmpty()) {
            $post->galleryMedia()
                ->whereIn('id', $deleteIds)
                ->get()
                ->each(fn (Media $media) => $this->deleteMedia($media));
        }

        $attachedIds = $post->galleryMedia()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $orderedIds = collect($request->input('gallery_order', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $attachedIds, true))
            ->unique()
            ->values();

        $sort = 0;
        foreach ($orderedIds as $mediaId) {
            Media::where('id', $mediaId)
                ->where('post_id', $post->id)
                ->where('media_type', 'post_gallery')
                ->update(['sort_order' => ++$sort]);
        }

        foreach (collect($attachedIds)->diff($orderedIds) as $mediaId) {
            Media::where('id', $mediaId)
                ->where('post_id', $post->id)
                ->where('media_type', 'post_gallery')
                ->update(['sort_order' => ++$sort]);
        }

        foreach ($request->input('gallery_captions', []) as $mediaId => $caption) {
            Media::where('id', (int) $mediaId)
                ->where('post_id', $post->id)
                ->where('media_type', 'post_gallery')
                ->update(['caption' => trim((string) $caption) ?: null]);
        }

        foreach ($request->file('gallery_images', []) as $image) {
            $processed = app(ImagePipeline::class)->process($image, $request->user()?->id, $post->id, 'posts/galleries');
            $media = Media::where('path', $processed['path'])->latest('id')->first();
            if ($media) {
                $media->update([
                    'post_id' => $post->id,
                    'media_type' => 'post_gallery',
                    'caption' => null,
                    'sort_order' => ++$sort,
                ]);
            }
        }
    }

    private function deleteMedia(Media $media): void
    {
        $paths = collect($media->responsive_paths['variants'] ?? [])
            ->pluck('path')
            ->push($media->responsive_paths['original'] ?? null)
            ->push($media->path)
            ->filter()
            ->unique()
            ->all();

        Storage::disk($media->disk ?: 'public')->delete($paths);
        $media->delete();
    }
}
