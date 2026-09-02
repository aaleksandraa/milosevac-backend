<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementClimateProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementClimateProfile>
 */
class SettlementClimateProfileFactory extends Factory
{
    protected $model = SettlementClimateProfile::class;

    public function definition(): array
    {
        return [
            'settlement_id' => GeoSettlement::factory(),
            'source_id' => DataSource::factory(),
            'boundary_id' => SettlementBoundary::factory(),
            'normal_period_start' => '1991-01-01',
            'normal_period_end' => '2020-12-31',
            'historical_period_start' => '1950-01-01',
            'historical_period_end' => '2025-12-31',
            'spatial_method' => 'area_weighted_grid_intersection',
            'source_resolution_km' => 9.0,
            'grid_cell_count' => 1,
            'temperature_mean_annual_c' => 11.6,
            'precipitation_annual_mm' => 780.0,
            'coldest_month' => 1,
            'warmest_month' => 7,
            'wettest_month' => 6,
            'driest_month' => 2,
            'temperature_range_annual_c' => 21.4,
            'temperature_mean_1961_1990_c' => 10.9,
            'temperature_mean_change_c' => 0.7,
            'indices' => [
                ['code' => 'hot_days_30c', 'value' => 18.2, 'threshold' => 30, 'unit' => 'days_per_year'],
            ],
            'algorithm_version' => 'm7.1',
            'quality_flag' => SettlementClimateProfile::QUALITY_COARSE,
            'calculated_at' => now(),
            'metadata' => ['timezone' => 'Europe/Sarajevo'],
        ];
    }
}
