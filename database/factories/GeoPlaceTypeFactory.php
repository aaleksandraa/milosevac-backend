<?php

namespace Database\Factories;

use App\Enums\GeoPlaceFamily;
use App\Models\GeoPlaceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeoPlaceType>
 */
class GeoPlaceTypeFactory extends Factory
{
    protected $model = GeoPlaceType::class;

    public function definition(): array
    {
        $code = 'test_'.fake()->unique()->lexify('??????');

        return [
            'family' => GeoPlaceFamily::Education,
            'code' => $code,
            'name' => 'Test type '.$code,
            'singular_label' => 'Test objekat',
            'plural_label' => 'Test objekti',
            'icon_key' => 'school',
            'default_visible' => true,
            'sort_order' => 999,
            'metadata' => null,
        ];
    }
}
