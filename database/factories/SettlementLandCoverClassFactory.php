<?php

namespace Database\Factories;

use App\Models\SettlementLandCoverClass;
use App\Models\SettlementSurfaceProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementLandCoverClass>
 */
class SettlementLandCoverClassFactory extends Factory
{
    protected $model = SettlementLandCoverClass::class;

    public function definition(): array
    {
        return [
            'surface_profile_id' => SettlementSurfaceProfile::factory(),
            'class_code' => '211',
            'source_class_code' => '211',
            'source_class_label' => 'Neirigirane oranice',
            'atlas_group' => 'agricultural',
            'area_km2' => 7.0,
            'share_pct' => 70.0,
            'pixel_count' => null,
            'metadata' => null,
        ];
    }
}
