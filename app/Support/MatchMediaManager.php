<?php

namespace App\Support;

use App\Models\FootballMatch;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a match photo gallery in sync with the CMS form: removals, ordering,
 * captions and newly uploaded images. Shared by the admin and author panels.
 */
class MatchMediaManager
{
    /** Validation rules for the gallery portion of a match form. */
    public static function validationRules(int $maxUploadKb = 8192): array
    {
        return [
            'gallery_order' => ['array'],
            'gallery_order.*' => ['integer', 'exists:media,id'],
            'gallery_captions' => ['array'],
            'gallery_captions.*' => ['nullable', 'string', 'max:255'],
            'delete_gallery' => ['array'],
            'delete_gallery.*' => ['integer', 'exists:media,id'],
            'gallery_images' => ['array'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', "max:{$maxUploadKb}"],
        ];
    }

    public function syncGallery(Request $request, FootballMatch $match): void
    {
        $this->detachDeleted($request, $match);

        $attachedIds = $match->media()
            ->pluck('media.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $sort = $this->applyOrdering($request, $match, $attachedIds);
        $this->applyCaptions($request, $match, $attachedIds);
        $this->attachUploads($request, $match, $sort);
    }

    private function detachDeleted(Request $request, FootballMatch $match): void
    {
        $deleteIds = collect($request->input('delete_gallery', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($deleteIds->isEmpty()) {
            return;
        }

        $mediaToDelete = $match->media()->whereIn('media.id', $deleteIds)->get();

        $match->media()->detach($mediaToDelete->pluck('id'));
        $mediaToDelete->each(fn (Media $media) => $this->deleteMediaIfUnused($media));
    }

    /** @return int the highest sort position assigned so far */
    private function applyOrdering(Request $request, FootballMatch $match, array $attachedIds): int
    {
        $orderedIds = collect($request->input('gallery_order', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => in_array($id, $attachedIds, true))
            ->unique()
            ->values();

        $sort = 0;

        foreach ($orderedIds as $mediaId) {
            $match->media()->updateExistingPivot($mediaId, ['sort_order' => ++$sort]);
        }

        foreach (collect($attachedIds)->diff($orderedIds) as $mediaId) {
            $match->media()->updateExistingPivot($mediaId, ['sort_order' => ++$sort]);
        }

        return $sort;
    }

    private function applyCaptions(Request $request, FootballMatch $match, array $attachedIds): void
    {
        foreach ($request->input('gallery_captions', []) as $mediaId => $caption) {
            $mediaId = (int) $mediaId;

            if (in_array($mediaId, $attachedIds, true)) {
                $match->media()->updateExistingPivot($mediaId, [
                    'caption' => trim((string) $caption) ?: null,
                ]);
            }
        }
    }

    private function attachUploads(Request $request, FootballMatch $match, int $sort): void
    {
        foreach ($request->file('gallery_images', []) as $image) {
            $processed = app(ImagePipeline::class)
                ->process($image, $request->user()?->id, null, 'matches/galleries', true);

            $media = Media::where('path', $processed['path'])->latest('id')->first();

            if ($media) {
                $match->media()->attach($media->id, ['sort_order' => ++$sort]);
            }
        }
    }

    /** Removes the underlying files once no post or match references the media. */
    public function deleteMediaIfUnused(Media $media): void
    {
        $isUsedByMatch = DB::table('match_media')->where('media_id', $media->id)->exists();

        if ($isUsedByMatch || $media->post_id) {
            return;
        }

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
