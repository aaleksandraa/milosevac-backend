<?php

namespace App\Support\Atlas\Metrics;

use App\Enums\CensusComparability;
use App\Enums\GeoPlaceAssignmentStatus;
use App\Enums\GeoPlaceFamily;
use App\Models\GeoPlace;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementClimateProfile;
use App\Models\SettlementSurfaceProfile;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\Network\NetworkPresenter;
use App\Support\Atlas\Network\NetworkRegistry;
use App\Support\Atlas\PopulationChangeCalculator;
use App\Support\Atlas\PopulationSelector;

final class MetricValueExtractor
{
    public function __construct(
        private PopulationSelector $selector = new PopulationSelector,
        private PopulationChangeCalculator $changes = new PopulationChangeCalculator,
    ) {}

    public function extract(GeoSettlement $settlement, MetricDefinition $metric, bool $strictPopulationChange = true): mixed
    {
        return match ($metric->code) {
            'population_2013' => $this->populationYear($settlement, 2013),
            'population_1991' => $this->populationYear($settlement, 1991),
            'population_change_1991_2013_abs' => $this->populationChange($settlement, 'absolute', false),
            'population_change_1991_2013_pct' => $this->populationChange($settlement, 'percentage', $strictPopulationChange),
            'area_km2' => $this->areaKm2($settlement),
            'elevation_mean_m' => $this->terrainField($settlement, 'elevation_mean_m'),
            'elevation_min_m' => $this->terrainField($settlement, 'elevation_min_m'),
            'elevation_max_m' => $this->terrainField($settlement, 'elevation_max_m'),
            'elevation_range_m' => $this->terrainField($settlement, 'elevation_range_m'),
            'slope_mean_deg' => $this->terrainField($settlement, 'slope_mean_deg'),
            'temperature_mean_annual_c' => $this->climateField($settlement, 'temperature_mean_annual_c'),
            'precipitation_annual_mm' => $this->climateField($settlement, 'precipitation_annual_mm'),
            'hot_days_30c' => $this->climateIndex($settlement, 'hot_days_30c'),
            'frost_days' => $this->climateIndex($settlement, 'frost_days'),
            'poi_count_total' => $this->poiCount($settlement, null),
            'poi_count_sport' => $this->poiCount($settlement, GeoPlaceFamily::Sport),
            'poi_count_health' => $this->poiCount($settlement, GeoPlaceFamily::Health),
            'poi_count_education' => $this->poiCount($settlement, GeoPlaceFamily::Education),
            'poi_count_religion' => $this->poiCount($settlement, GeoPlaceFamily::Religion),
            'poi_count_historic' => $this->poiCount($settlement, GeoPlaceFamily::Historic),
            'landcover_agricultural_pct' => $this->surfaceSummary($settlement, 'clc2018', 'landcover_agricultural_pct'),
            'landcover_forest_pct' => $this->surfaceSummary($settlement, 'clc2018', 'landcover_forest_pct'),
            'landcover_artificial_pct' => $this->surfaceSummary($settlement, 'clc2018', 'landcover_artificial_pct'),
            'landcover_water_pct' => $this->surfaceSummary($settlement, 'clc2018', 'landcover_water_pct'),
            'tree_cover_mean_pct' => $this->surfaceSummary($settlement, 'hrl_tcd_2018', 'tree_cover_mean_pct'),
            'imperviousness_mean_pct' => $this->surfaceSummary($settlement, 'hrl_imd_2018', 'imperviousness_mean_pct'),
            default => $this->networkValue($settlement, $metric->code),
        };
    }

    public function isEligible(GeoSettlement $settlement, MetricDefinition $metric): bool
    {
        $value = $this->extract($settlement, $metric);
        if ($value === null) {
            return false;
        }
        if ($value === 0 || $value === 0.0) {
            return $metric->zeroIsValid;
        }

        return true;
    }

    public function populationComparability(GeoSettlement $settlement, int $year): ?CensusComparability
    {
        if (! $settlement->relationLoaded('censusMappings') && ! $settlement->relationLoaded('populationRecords')) {
            return null;
        }

        if ($this->populationYear($settlement, $year) === null) {
            return null;
        }

        return $this->changes->comparabilityForYear($year, $settlement->censusMappings);
    }

    public function changeComparability(GeoSettlement $settlement): ?CensusComparability
    {
        $pop1991 = $this->populationYear($settlement, 1991);
        $pop2013 = $this->populationYear($settlement, 2013);
        if ($pop1991 === null || $pop2013 === null) {
            return null;
        }

        $from = $this->changes->comparabilityForYear(1991, $settlement->censusMappings);
        $to = $this->changes->comparabilityForYear(2013, $settlement->censusMappings);
        if (! $from->mayComputeChange() || ! $to->mayComputeChange()) {
            return CensusComparability::NotDirectlyComparable;
        }
        if ($from === CensusComparability::ComparableWithCaveat || $to === CensusComparability::ComparableWithCaveat) {
            return CensusComparability::ComparableWithCaveat;
        }

        return CensusComparability::Comparable;
    }

    private function populationYear(GeoSettlement $settlement, int $year): ?int
    {
        if (! $settlement->relationLoaded('populationRecords')) {
            return null;
        }

        $row = $this->selector->preferredForYear($settlement->populationRecords, $year);

        return $row === null ? null : (int) $row->population;
    }

    private function populationChange(GeoSettlement $settlement, string $kind, bool $strict): int|float|null
    {
        $earlier = $this->populationYear($settlement, 1991);
        $later = $this->populationYear($settlement, 2013);
        if ($earlier === null || $later === null) {
            return null;
        }

        $comparability = $this->changeComparability($settlement);
        if ($comparability === null) {
            return null;
        }
        if ($strict && ! $comparability->isDirectlyComparable()) {
            return null;
        }
        if (! $strict && ! $comparability->mayComputeChange()) {
            return null;
        }

        if ($kind === 'percentage') {
            if ($earlier === 0) {
                return null;
            }

            return round((($later - $earlier) / $earlier) * 100, 1);
        }

        return $later - $earlier;
    }

    private function areaKm2(GeoSettlement $settlement): ?float
    {
        $boundary = $this->validBoundary($settlement);
        if ($boundary?->area_km2 === null) {
            return null;
        }

        return (float) $boundary->area_km2;
    }

    private function terrainField(GeoSettlement $settlement, string $field): ?float
    {
        $terrain = $this->usableTerrain($settlement);
        if ($terrain === null || $terrain->{$field} === null) {
            return null;
        }

        return (float) $terrain->{$field};
    }

    private function climateField(GeoSettlement $settlement, string $field): ?float
    {
        $profile = $this->usableClimate($settlement);
        if ($profile === null || $profile->{$field} === null) {
            return null;
        }

        return (float) $profile->{$field};
    }

    private function climateIndex(GeoSettlement $settlement, string $code): ?float
    {
        $profile = $this->usableClimate($settlement);
        if ($profile === null) {
            return null;
        }

        foreach ($profile->indices ?? [] as $index) {
            if (! is_array($index) || ($index['code'] ?? null) !== $code) {
                continue;
            }
            if (! array_key_exists('value', $index) || $index['value'] === null) {
                return null;
            }

            return (float) $index['value'];
        }

        return null;
    }

    private function poiCount(GeoSettlement $settlement, ?GeoPlaceFamily $family): int
    {
        if (! $settlement->relationLoaded('places')) {
            return 0;
        }

        return $settlement->places
            ->filter(function (GeoPlace $place) use ($family) {
                if (! $place->is_active) {
                    return false;
                }
                if (! in_array($place->assignment_status, [
                    GeoPlaceAssignmentStatus::BoundaryMatch,
                    GeoPlaceAssignmentStatus::ExplicitVerified,
                ], true)) {
                    return false;
                }
                if ($family === null) {
                    return true;
                }

                return $place->placeType?->family === $family;
            })
            ->count();
    }

    private function validBoundary(GeoSettlement $settlement): ?SettlementBoundary
    {
        if (! $settlement->relationLoaded('boundaries')) {
            return null;
        }

        return $settlement->boundaries->firstWhere('geometry_valid', true);
    }

    private function usableTerrain(GeoSettlement $settlement): ?SettlementTerrainStat
    {
        if (! $settlement->relationLoaded('terrainStats')) {
            return null;
        }

        return $settlement->terrainStats
            ->filter(fn (SettlementTerrainStat $row) => $row->isPubliclyUsable())
            ->sortByDesc(fn (SettlementTerrainStat $row) => $row->calculated_at?->timestamp ?? 0)
            ->first();
    }

    private function usableClimate(GeoSettlement $settlement): ?SettlementClimateProfile
    {
        if (! $settlement->relationLoaded('climateProfiles')) {
            return null;
        }

        return $settlement->climateProfiles
            ->filter(fn (SettlementClimateProfile $row) => $row->isPubliclyUsable())
            ->sortByDesc(fn (SettlementClimateProfile $row) => $row->calculated_at?->timestamp ?? 0)
            ->first();
    }

    private function surfaceSummary(GeoSettlement $settlement, string $product, string $key): ?float
    {
        $profile = $this->usableSurface($settlement, $product);
        if ($profile === null) {
            return null;
        }

        return $profile->summaryValue($key);
    }

    private function usableSurface(GeoSettlement $settlement, string $product): ?SettlementSurfaceProfile
    {
        if (! $settlement->relationLoaded('surfaceProfiles')) {
            return null;
        }

        return $settlement->surfaceProfiles
            ->filter(fn (SettlementSurfaceProfile $row) => $row->product_code === $product && $row->isPubliclyUsable())
            ->sortByDesc(fn (SettlementSurfaceProfile $row) => $row->calculated_at?->timestamp ?? 0)
            ->first();
    }

    private function networkValue(GeoSettlement $s, string $code): ?float
    {
        $definition = NetworkRegistry::METRICS[$code] ?? null;
        if (! $definition) {
            return null;
        }
        [, , $block, $field] = $definition;
        $data = (new NetworkPresenter)->present($s);
        $value = $block === 'proximity' ? ($data[$block][$field]['distance_straight_m'] ?? null) : ($data[$block][$field] ?? null);

        return $value === null ? null : (float) $value / ($block === 'proximity' ? 1000 : 1);
    }
}
