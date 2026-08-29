<?php

namespace App\Support\PowerOutage;

use App\Models\Place;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OutageQueryService
{
    /** @return Collection<int, Post> */
    public function fetch(?string $placeSlug = null, ?string $status = null, int $limit = 100): Collection
    {
        $now = now('Europe/Sarajevo');

        return Post::query()
            ->published()
            ->where('service_type', 'power_outage')
            ->with(['places', 'author:id,name', 'category.parent'])
            ->when($placeSlug, fn (Builder $query) => $query->whereHas(
                'places',
                fn (Builder $placeQuery) => $placeQuery->where('slug', $placeSlug)
            ))
            ->when($status === 'upcoming', fn (Builder $query) => $query
                ->whereNotNull('notice_starts_at')
                ->where('notice_starts_at', '>', $now))
            ->when($status === 'active', fn (Builder $query) => $query
                ->whereNotNull('notice_starts_at')
                ->whereNotNull('notice_ends_at')
                ->where('notice_starts_at', '<=', $now)
                ->where('notice_ends_at', '>', $now))
            ->when($status === 'past', fn (Builder $query) => $query
                ->whereNotNull('notice_ends_at')
                ->where('notice_ends_at', '<=', $now))
            ->portalOrder()
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listPlaces(): Collection
    {
        return Place::query()
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Place $place) => [
                'slug' => $place->slug,
                'name' => $place->name,
                'locative' => $place->locative,
                'isPrimary' => (bool) $place->is_primary,
            ]);
    }
}
