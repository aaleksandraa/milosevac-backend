<?php

namespace App\Support\Atlas;

use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Models\SettlementTerrainStat;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class AtlasQueryService
{
    /**
     * @return array<int, mixed>
     */
    public function municipalityRelations(): array
    {
        return [
            'entity:id,country_id,name,slug,kind',
            'canton:id,entity_id,name,slug',
            'settlements' => function (HasMany $query) {
                $query->orderBy('name')->with(['populationRecords.source']);
            },
        ];
    }

    /**
     * Public index lists only municipalities that already have imported settlements.
     *
     * @return Collection<int, GeoMunicipality>
     */
    public function municipalitiesForIndex(): Collection
    {
        return GeoMunicipality::query()
            ->whereHas('settlements')
            ->with($this->municipalityRelations())
            ->withCount('settlements')
            ->orderBy('name')
            ->get();
    }

    public function municipalityBySlug(string $slug): ?GeoMunicipality
    {
        return GeoMunicipality::query()
            ->where('slug', $slug)
            ->with($this->municipalityRelations())
            ->withCount('settlements')
            ->first();
    }

    public function settlementInMunicipality(string $municipalitySlug, string $settlementSlug): ?GeoSettlement
    {
        return GeoSettlement::query()
            ->where('slug', $settlementSlug)
            ->whereHas('municipality', fn ($query) => $query->where('slug', $municipalitySlug))
            ->with($this->settlementDetailRelations())
            ->first();
    }

    public function settlementById(int $id): ?GeoSettlement
    {
        return GeoSettlement::query()
            ->whereKey($id)
            ->with($this->settlementDetailRelations())
            ->first();
    }

    public function municipalitySlugExists(string $slug): bool
    {
        return GeoMunicipality::query()->where('slug', $slug)->exists();
    }

    /**
     * Settlements with a valid canonical boundary, for the public map FeatureCollection.
     *
     * @return Collection<int, GeoSettlement>
     */
    public function settlementsForMap(?string $municipalitySlug = null): Collection
    {
        $query = GeoSettlement::query()
            ->whereHas('boundaries', fn ($builder) => $builder->where('geometry_valid', true))
            ->with([
                'municipality:id,name,slug',
                'populationRecords.source',
                'boundaries' => function (HasMany $query) {
                    $query->where('geometry_valid', true)->with('source:id,source_type,name,provider');
                },
                'terrainStats' => function (HasMany $query) {
                    $query->where('quality_flag', '!=', SettlementTerrainStat::QUALITY_UNAVAILABLE);
                },
            ])
            ->orderBy('name');

        if ($municipalitySlug !== null && $municipalitySlug !== '') {
            $query->whereHas('municipality', fn ($builder) => $builder->where('slug', $municipalitySlug));
        }

        return $query->get();
    }

    /**
     * @return Collection<int, GeoSettlement>
     */
    public function indexableSettlements(): Collection
    {
        return GeoSettlement::query()
            ->with(['municipality:id,slug,name', 'populationRecords'])
            ->orderBy('municipality_id')
            ->orderBy('name')
            ->get()
            ->filter(fn (GeoSettlement $settlement) => SettlementIndexability::isReady($settlement))
            ->values();
    }

    /**
     * @return array<int, mixed>
     */
    private function settlementDetailRelations(): array
    {
        return [
            'municipality.entity:id,country_id,name,slug,kind',
            'municipality.canton:id,entity_id,name,slug',
            'municipality.settlements' => function (HasMany $query) {
                $query->orderBy('name')->select(['id', 'municipality_id', 'name', 'slug']);
            },
            'populationRecords.source',
            'labelPointSource',
            'boundaries.source',
            'terrainStats.source',
            'terrainStats.boundary',
        ];
    }
}
