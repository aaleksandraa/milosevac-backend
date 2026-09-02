<?php

namespace App\Support\Atlas;

use App\Enums\GeoPlaceFamily;
use App\Models\GeoMunicipality;
use App\Models\GeoPlace;
use App\Models\GeoPlaceType;
use App\Models\GeoSettlement;
use App\Models\SettlementClimateProfile;
use App\Models\SettlementTerrainStat;
use Illuminate\Database\Eloquent\Builder;
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

    public function settlementSlugInMunicipalityExists(string $municipalitySlug, string $settlementSlug): bool
    {
        return GeoSettlement::query()
            ->where('slug', $settlementSlug)
            ->whereHas('municipality', fn ($query) => $query->where('slug', $municipalitySlug))
            ->exists();
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
                'climateProfiles' => function (HasMany $query) {
                    $query->whereNotIn('quality_flag', [
                        SettlementClimateProfile::QUALITY_UNAVAILABLE,
                        SettlementClimateProfile::QUALITY_INSUFFICIENT,
                        SettlementClimateProfile::QUALITY_NO_BOUNDARY,
                    ]);
                },
            ])
            ->orderBy('name');

        if ($municipalitySlug !== null && $municipalitySlug !== '') {
            $query->whereHas('municipality', fn ($builder) => $builder->where('slug', $municipalitySlug));
        }

        return $query->get();
    }

    /**
     * Active atlas POIs for public list/map endpoints.
     *
     * @param  list<string>  $families
     * @return Collection<int, GeoPlace>
     */
    public function placesForPublic(
        ?string $municipalitySlug = null,
        ?string $settlementSlug = null,
        array $families = [],
        ?string $typeCode = null,
    ): Collection {
        $query = GeoPlace::query()
            ->where('is_active', true)
            ->with([
                'placeType',
                'municipality:id,name,slug',
                'settlement:id,municipality_id,name,slug',
            ])
            ->orderBy('id');

        if ($municipalitySlug !== null && $municipalitySlug !== '') {
            $query->whereHas('municipality', fn (Builder $builder) => $builder->where('slug', $municipalitySlug));
        }

        if ($settlementSlug !== null && $settlementSlug !== '') {
            $query->whereHas('settlement', fn (Builder $builder) => $builder->where('slug', $settlementSlug));
        }

        if ($families !== []) {
            $query->whereHas('placeType', fn (Builder $builder) => $builder->whereIn('family', $families));
        }

        if ($typeCode !== null && $typeCode !== '') {
            $query->whereHas('placeType', fn (Builder $builder) => $builder->where('code', $typeCode));
        }

        return $query->get();
    }

    /**
     * @return list<string>
     */
    public function parseFamilies(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $values = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $family = GeoPlaceFamily::tryFrom($part);
            abort_if($family === null, 422, 'Nepoznata familija objekata.');
            $values[] = $family->value;
        }

        return array_values(array_unique($values));
    }

    public function placeTypeCodeExists(string $code): bool
    {
        return GeoPlaceType::query()->where('code', $code)->exists();
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
            'climateProfiles' => function (HasMany $query) {
                $query->whereNotIn('quality_flag', ['unavailable', 'insufficient_source', 'no_boundary'])
                    ->with(['source', 'monthly', 'extremes']);
            },
        ];
    }
}
