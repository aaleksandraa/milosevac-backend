<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementSurfaceProfile;
use App\Support\Atlas\LandCover\ClcNomenclature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementSurfaceProfile>
 */
class SettlementSurfaceProfileFactory extends Factory
{
    protected $model = SettlementSurfaceProfile::class;

    public function definition(): array
    {
        return [
            'settlement_id' => GeoSettlement::factory(),
            'source_id' => DataSource::factory(),
            'boundary_id' => null,
            'product_code' => ClcNomenclature::PRODUCT_CLC,
            'reference_year' => 2018,
            'algorithm_version' => ClcNomenclature::ALGORITHM_VERSION,
            'processing_method' => 'clc_vector_intersection_epsg3035',
            'source_resolution_m' => null,
            'total_analysis_area_km2' => 10.0,
            'valid_area_km2' => 10.0,
            'nodata_area_km2' => 0.0,
            'quality_flag' => SettlementSurfaceProfile::QUALITY_OK,
            'summary' => [
                'landcover_artificial_pct' => 4.0,
                'landcover_agricultural_pct' => 70.0,
                'landcover_forest_pct' => 20.0,
                'landcover_seminatural_pct' => 3.0,
                'landcover_open_or_sparse_pct' => 0.0,
                'landcover_wetland_pct' => 0.0,
                'landcover_water_pct' => 3.0,
                'dominant_class' => [
                    'code' => '211',
                    'label' => 'Neirigirane oranice',
                    'atlas_group' => 'agricultural',
                    'area_km2' => 7.0,
                    'share_pct' => 70.0,
                ],
                'dominant_group' => [
                    'code' => 'agricultural',
                    'label' => 'Poljoprivredne površine',
                    'share_pct' => 70.0,
                    'area_km2' => 7.0,
                ],
            ],
            'calculated_at' => now(),
            'metadata' => ['share_sum_pct' => 100.0],
        ];
    }
}
