<?php

namespace Database\Factories;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingMethod;
use App\Enums\CensusMappingStatus;
use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementCensusMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementCensusMapping>
 */
class SettlementCensusMappingFactory extends Factory
{
    protected $model = SettlementCensusMapping::class;

    public function definition(): array
    {
        return [
            'settlement_id' => GeoSettlement::factory(),
            'source_id' => DataSource::factory(),
            'census_year' => 1991,
            'historical_settlement_name' => fake()->unique()->city(),
            'historical_settlement_code' => null,
            'historical_municipality_name' => 'Modriča',
            'historical_municipality_code' => null,
            'mapping_status' => CensusMappingStatus::ExactIdentity,
            'mapping_method' => CensusMappingMethod::CheckedInMapping,
            'comparability' => CensusComparability::Comparable,
            'verification_status' => VerificationStatus::OfficialSourceVerified,
            'notes' => null,
            'source_properties' => null,
        ];
    }
}
