<?php

namespace Database\Factories;

use App\Enums\GeoMunicipalityAdminType;
use App\Models\GeoEntity;
use App\Models\GeoMunicipality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeoMunicipality>
 */
class GeoMunicipalityFactory extends Factory
{
    protected $model = GeoMunicipality::class;

    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'entity_id' => GeoEntity::factory(),
            'canton_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'admin_type' => GeoMunicipalityAdminType::Municipality,
            'official_code' => null,
            'statistical_code' => null,
        ];
    }
}
