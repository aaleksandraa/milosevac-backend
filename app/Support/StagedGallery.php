<?php

namespace App\Support;

use App\Models\FootballMatch;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Gallery photos are uploaded one at a time before the match or article is saved.
 * That keeps each request under the proxy body-size and timeout limits.
 */
class StagedGallery
{
    public const MATCH = 'staged_match_gallery';

    public const POST = 'staged_post_gallery';

    public static function validationRules(): array
    {
        return [
            'staged_gallery' => ['array', 'max:500'],
            'staged_gallery.*' => ['integer'],
        ];
    }

    public static function validationMessages(): array
    {
        return [
            'gallery_images.max' => 'Odjednom se može poslati najviše 24 fotografije. Veća galerija se učitava pojedinačno prije snimanja.',
            'staged_gallery.max' => 'Galerija može imati najviše 500 novih fotografija odjednom.',
        ];
    }

    public function assertClaimable(Request $request, string $type): void
    {
        $ids = $this->claimableIds($request);

        if ($ids->isEmpty()) {
            return;
        }

        $found = $this->stagedQuery($request, $type, $ids)->count();

        if ($found !== $ids->count()) {
            throw ValidationException::withMessages([
                'staged_gallery' => 'Neke fotografije nisu učitane ili više nisu dostupne. Uklonite ih iz galerije ili ih učitajte ponovo.',
            ]);
        }
    }

    public function discardMarked(Request $request, string $type): void
    {
        $deleteIds = collect($request->input('delete_gallery', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $stagedIds = collect($request->input('staged_gallery', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->intersect($deleteIds);

        if ($stagedIds->isEmpty()) {
            return;
        }

        $this->stagedQuery($request, $type, $stagedIds)
            ->get()
            ->each(fn (Media $media) => $this->deleteFiles($media));
    }

    public function attachToMatch(Request $request, FootballMatch $match): void
    {
        foreach ($this->claimableIds($request) as $id) {
            DB::transaction(function () use ($request, $match, $id) {
                $media = $this->lockStaged($request, self::MATCH, $id);

                if (! $media) {
                    throw ValidationException::withMessages([
                        'staged_gallery' => 'Neke fotografije više nisu dostupne. Sačuvajte ponovo.',
                    ]);
                }

                $match->media()->attach($media->id, ['sort_order' => 0]);
                $media->update(['media_type' => 'match_gallery']);
            });
        }
    }

    public function attachToPost(Request $request, Post $post): void
    {
        foreach ($this->claimableIds($request) as $id) {
            DB::transaction(function () use ($request, $post, $id) {
                $media = $this->lockStaged($request, self::POST, $id);

                if (! $media) {
                    throw ValidationException::withMessages([
                        'staged_gallery' => 'Neke fotografije više nisu dostupne. Sačuvajte ponovo.',
                    ]);
                }

                $media->update([
                    'post_id' => $post->id,
                    'media_type' => 'post_gallery',
                    'sort_order' => 0,
                    'caption' => null,
                ]);
            });
        }
    }

    public function deleteFiles(Media $media): void
    {
        $responsive = $media->responsive_paths ?? [];
        $paths = collect($responsive['variants'] ?? [])
            ->pluck('path')
            ->push($responsive['original'] ?? null)
            ->push($media->path)
            ->filter()
            ->unique()
            ->all();

        Storage::disk($media->disk ?: 'public')->delete($paths);
        $media->delete();
    }

    /** @param  Collection<int, int>|array<int, int>  $ids */
    private function stagedQuery(Request $request, string $type, Collection|array $ids)
    {
        return Media::query()
            ->whereIn('id', $ids)
            ->where('user_id', $request->user()?->id)
            ->where('media_type', $type)
            ->whereNull('post_id')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('match_media')
                    ->whereColumn('match_media.media_id', 'media.id');
            });
    }

    private function lockStaged(Request $request, string $type, int $id): ?Media
    {
        return $this->stagedQuery($request, $type, [$id])->lockForUpdate()->first();
    }

    /** @return Collection<int, int> */
    private function claimableIds(Request $request): Collection
    {
        $deleteIds = collect($request->input('delete_gallery', []))
            ->map(fn ($id) => (int) $id)
            ->all();

        return collect($request->input('staged_gallery', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->reject(fn (int $id) => in_array($id, $deleteIds, true))
            ->unique()
            ->values();
    }
}
