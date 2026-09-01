<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementTerrainStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementTerrainStat>
 */
class SettlementTerrainStatFactory extends Factory
{
    protected $model = SettlementTerrainStat::class;

    public function definition(): array
    {
        $min = fake()->randomFloat(1, 80, 90);
        $max = $min + fake()->randomFloat(1, 5, 40);

        return [
            'settlement_id' => GeoSettlement::factory(),
            'boundary_id' => SettlementBoundary::factory(),
            'source_id' => DataSource::factory(),
            'elevation_min_m' => $min,
            'elevation_max_m' => $max,
            'elevation_mean_m' => ($min + $max) / 2,
            'elevation_median_m' => ($min + $max) / 2,
            'elevation_range_m' => $max - $min,
            'slope_mean_deg' => 1.2,
            'slope_median_deg' => 0.8,
            'slope_max_deg' => 6.5,
            'dem_source' => 'Copernicus DEM GLO-30 Public',
            'dem_version' => '2021_1',
            'dem_resolution_m' => 30,
            'sample_count' => 40,
            'valid_cell_count' => 40,
            'nodata_cell_count' => 0,
            'quality_flag' => SettlementTerrainStat::QUALITY_OK,
            'algorithm_version' => 'm4.1',
            'calculated_at' => now(),
            'metadata' => ['cell_inclusion' => 'pixel_center'],
        ];
    }
}
