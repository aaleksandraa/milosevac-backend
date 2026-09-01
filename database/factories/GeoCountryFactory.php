<?php

namespace Database\Factories;

use App\Models\GeoCountry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeoCountry>
 */
class GeoCountryFactory extends Factory
{
    protected $model = GeoCountry::class;

    public function definition(): array
    {
        $name = fake()->unique()->country();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('##'),
            'iso2' => fake()->unique()->regexify('[A-Z]{2}'),
            'iso3' => fake()->unique()->regexify('[A-Z]{3}'),
        ];
    }
}
