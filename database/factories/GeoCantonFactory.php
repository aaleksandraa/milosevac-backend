<?php

namespace Database\Factories;

use App\Models\GeoCanton;
use App\Models\GeoEntity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeoCanton>
 */
class GeoCantonFactory extends Factory
{
    protected $model = GeoCanton::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'entity_id' => GeoEntity::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('##'),
            'official_code' => fake()->unique()->numerify('##'),
        ];
    }
}
