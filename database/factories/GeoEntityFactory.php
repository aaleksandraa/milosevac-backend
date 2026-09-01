<?php

namespace Database\Factories;

use App\Enums\GeoEntityKind;
use App\Models\GeoCountry;
use App\Models\GeoEntity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeoEntity>
 */
class GeoEntityFactory extends Factory
{
    protected $model = GeoEntity::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'country_id' => GeoCountry::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('##'),
            'kind' => GeoEntityKind::Republic,
        ];
    }
}
