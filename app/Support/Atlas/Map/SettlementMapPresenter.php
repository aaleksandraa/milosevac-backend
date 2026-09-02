<?php

namespace App\Support\Atlas\Map;

use App\Enums\CensusComparability;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementClimateProfile;
use App\Models\SettlementSurfaceProfile;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\LandCover\ClcNomenclature;
use App\Support\Atlas\PopulationChangeCalculator;
use App\Support\Atlas\PopulationSelector;
use App\Support\Atlas\SettlementIndexability;
use Illuminate\Support\Collection;

final class SettlementMapPresenter
{
    public const BOUNDARY_NOTE = 'Prikazane granice zasnovane su na OpenStreetMap administrativnim poligonima dostupnim za ovo naselje. Ne predstavljaju nužno službene katastarske ili statističke granice naseljenih mjesta.';

    public const TERRAIN_NOTE = 'Visinski podaci izvedeni su iz Copernicus DEM GLO-30 modela terena unutar izabrane granice naselja. To nisu službene izmjerene visine.';

    public const COVERAGE_NOTE = 'Pokretanje karte obuhvata samo naselja s važećom granicom. U pilotu Modriča + Šamac granica postoji za 37 od 45 uvezenih naselja.';

    public const CLIMATE_NOTE = 'Klimatske vrijednosti su ERA5-Land / ERA5 reanaliza za period 1991–2020, ne mjerenja meteorološke stanice.';

    public const LAND_COVER_NOTE = 'Pokrov zemljišta je CLC 2018 / Copernicus HRL 2018 unutar trenutno dostupne atlas granice naselja. Referentna 2018. godina, ne trenutno stanje.';

    public const POPULATION_CHANGE_NOTE = 'Promjena stanovništva je razlika između Popisa 1991. i Popisa 2013. za uporediva naselja. Nije procjena uzroka niti trenutnog broja stanovnika.';

    /**
     * Canonical M4 geometry is served as GeoJSON without simplification.
     *
     * @param  Collection<int, GeoSettlement>  $settlements
     * @return array{type: string, features: list<array<string, mixed>>, bbox?: list<float>, meta: array<string, mixed>}
     */
    public function collection(Collection $settlements): array
    {
        $features = [];
        $minLng = $minLat = $maxLng = $maxLat = null;

        foreach ($settlements as $settlement) {
            $feature = $this->feature($settlement);
            if ($feature === null) {
                continue;
            }

            $features[] = $feature;

            $boundary = $this->validBoundary($settlement);
            if ($boundary === null) {
                continue;
            }

            $minLng = min($minLng ?? (float) $boundary->bbox_min_lng, (float) $boundary->bbox_min_lng);
            $minLat = min($minLat ?? (float) $boundary->bbox_min_lat, (float) $boundary->bbox_min_lat);
            $maxLng = max($maxLng ?? (float) $boundary->bbox_max_lng, (float) $boundary->bbox_max_lng);
            $maxLat = max($maxLat ?? (float) $boundary->bbox_max_lat, (float) $boundary->bbox_max_lat);
        }

        $payload = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'boundary_source_type' => 'osm_settlement_boundaries',
                'terrain_source_type' => 'copernicus_dem_glo30',
                'geometry_strategy' => 'canonical_geojson',
                'feature_count' => count($features),
                'boundary_note' => self::BOUNDARY_NOTE,
                'terrain_note' => self::TERRAIN_NOTE,
                'coverage_note' => self::COVERAGE_NOTE,
                'climate_note' => self::CLIMATE_NOTE,
                'climate_source_type' => 'copernicus_era5_land',
                'landcover_note' => self::LAND_COVER_NOTE,
                'landcover_source_type' => 'copernicus_clc_2018',
                'population_change_note' => self::POPULATION_CHANGE_NOTE,
            ],
        ];

        if ($minLng !== null && $minLat !== null && $maxLng !== null && $maxLat !== null) {
            $payload['bbox'] = [$minLng, $minLat, $maxLng, $maxLat];
        }

        return $payload;
    }

    /**
     * @return array{type: string, id: int, properties: array<string, mixed>, geometry: array<string, mixed>}|null
     */
    public function feature(GeoSettlement $settlement): ?array
    {
        $boundary = $this->validBoundary($settlement);
        $geometry = $boundary?->geometry();
        if ($boundary === null || $geometry === null) {
            return null;
        }

        $population = $settlement->relationLoaded('populationRecords')
            ? (new PopulationSelector)->preferredForYear($settlement->populationRecords, 2013)
            : null;
        $terrain = $this->publicTerrain($settlement);
        $climate = $this->publicClimate($settlement);
        $clc = $this->publicSurface($settlement, ClcNomenclature::PRODUCT_CLC);
        $tcd = $this->publicSurface($settlement, ClcNomenclature::PRODUCT_TCD);
        $imd = $this->publicSurface($settlement, ClcNomenclature::PRODUCT_IMD);

        $properties = [
            'settlement_id' => $settlement->id,
            'settlement_name' => $settlement->name,
            'settlement_slug' => $settlement->slug,
            'municipality_name' => $settlement->municipality?->name,
            'municipality_slug' => $settlement->municipality?->slug,
            'path' => $settlement->municipality
                ? '/mjesta/'.$settlement->municipality->slug.'/'.$settlement->slug
                : null,
            'indexable' => SettlementIndexability::isReady($settlement),
            'area_km2' => $boundary->area_km2 !== null ? (float) $boundary->area_km2 : null,
            'population_2013' => $population?->population,
            'elevation_min_m' => $this->decimal($terrain?->elevation_min_m),
            'elevation_max_m' => $this->decimal($terrain?->elevation_max_m),
            'elevation_mean_m' => $this->decimal($terrain?->elevation_mean_m),
            'elevation_median_m' => $this->decimal($terrain?->elevation_median_m),
            'elevation_range_m' => $this->decimal($terrain?->elevation_range_m),
            'slope_mean_deg' => $this->decimal($terrain?->slope_mean_deg),
            'boundary_source_type' => $boundary->relationLoaded('source') ? $boundary->source?->source_type : null,
            'terrain_quality_flag' => $terrain?->quality_flag,
            'temperature_mean_annual_c' => $this->decimal($climate?->temperature_mean_annual_c),
            'precipitation_annual_mm' => $this->decimal($climate?->precipitation_annual_mm),
            'climate_quality_flag' => $climate?->quality_flag,
            'climate_normal_period' => $climate ? '1991–2020' : null,
            'landcover_agricultural_pct' => $this->surfacePct($clc, 'landcover_agricultural_pct'),
            'landcover_forest_pct' => $this->surfacePct($clc, 'landcover_forest_pct'),
            'landcover_artificial_pct' => $this->surfacePct($clc, 'landcover_artificial_pct'),
            'tree_cover_mean_pct' => $this->surfacePct($tcd, 'tree_cover_mean_pct'),
            'imperviousness_mean_pct' => $this->surfacePct($imd, 'imperviousness_mean_pct'),
            'population_change_1991_2013_pct' => $this->populationChangePct($settlement),
        ];

        return [
            'type' => 'Feature',
            'id' => $settlement->id,
            'properties' => array_filter(
                $properties,
                fn ($value) => $value !== null && $value !== '',
            ),
            'geometry' => $geometry,
        ];
    }

    private function populationChangePct(GeoSettlement $settlement): ?float
    {
        if (! $settlement->relationLoaded('populationRecords')) {
            return null;
        }

        $selector = new PopulationSelector;
        $from = $selector->preferredForYear($settlement->populationRecords, 1991);
        $to = $selector->preferredForYear($settlement->populationRecords, 2013);
        if ($from === null || $to === null) {
            return null;
        }

        $mapping = $settlement->relationLoaded('censusMappings')
            ? $settlement->censusMappings->first(
                fn (SettlementCensusMapping $row) => (int) $row->census_year === 1991
                    && (int) $row->settlement_id === (int) $settlement->id,
            )
            : null;
        if ($mapping?->comparability !== CensusComparability::Comparable) {
            return null;
        }

        return (new PopulationChangeCalculator)->percentageChange((int) $from->population, (int) $to->population);
    }

    private function validBoundary(GeoSettlement $settlement): ?SettlementBoundary
    {
        if (! $settlement->relationLoaded('boundaries')) {
            return null;
        }

        return $settlement->boundaries->firstWhere('geometry_valid', true);
    }

    private function publicTerrain(GeoSettlement $settlement): ?SettlementTerrainStat
    {
        if (! $settlement->relationLoaded('terrainStats')) {
            return null;
        }

        return $settlement->terrainStats
            ->filter(fn (SettlementTerrainStat $row) => $row->isPubliclyUsable())
            ->sortByDesc(fn (SettlementTerrainStat $row) => $row->calculated_at?->timestamp ?? 0)
            ->first();
    }

    private function publicClimate(GeoSettlement $settlement): ?SettlementClimateProfile
    {
        if (! $settlement->relationLoaded('climateProfiles')) {
            return null;
        }

        return $settlement->climateProfiles
            ->filter(fn (SettlementClimateProfile $row) => $row->isPubliclyUsable())
            ->sortByDesc(fn (SettlementClimateProfile $row) => $row->calculated_at?->timestamp ?? 0)
            ->first();
    }

    private function publicSurface(GeoSettlement $settlement, string $product): ?SettlementSurfaceProfile
    {
        if (! $settlement->relationLoaded('surfaceProfiles')) {
            return null;
        }

        return $settlement->surfaceProfiles
            ->filter(fn (SettlementSurfaceProfile $row) => $row->product_code === $product && $row->isPubliclyUsable())
            ->sortByDesc(fn (SettlementSurfaceProfile $row) => $row->calculated_at?->timestamp ?? 0)
            ->first();
    }

    private function surfacePct(?SettlementSurfaceProfile $profile, string $key): ?float
    {
        $value = $profile?->summaryValue($key);

        return $value === null ? null : round($value, 1);
    }

    private function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
