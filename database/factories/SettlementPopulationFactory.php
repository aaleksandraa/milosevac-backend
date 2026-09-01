<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementPopulation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementPopulation>
 */
class SettlementPopulationFactory extends Factory
{
    protected $model = SettlementPopulation::class;

    public function definition(): array
    {
        return [
            'settlement_id' => GeoSettlement::factory(),
            'census_year' => 2013,
            'population' => fake()->numberBetween(50, 5000),
            'households' => null,
            'statistical_settlement_code' => null,
            'source_id' => DataSource::factory(),
            'boundary_version' => null,
            'notes' => null,
        ];
    }
}
