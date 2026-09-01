<?php

namespace Database\Factories;

use App\Enums\GeoPlaceAssignmentStatus;
use App\Enums\GeoPlaceClassificationStatus;
use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Models\GeoMunicipality;
use App\Models\GeoPlace;
use App\Models\GeoPlaceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeoPlace>
 */
class GeoPlaceFactory extends Factory
{
    protected $model = GeoPlace::class;

    public function definition(): array
    {
        return [
            'place_type_id' => GeoPlaceType::factory(),
            'settlement_id' => null,
            'municipality_id' => GeoMunicipality::factory(),
            'source_id' => DataSource::factory(),
            'name' => fake()->optional()->company(),
            'slug' => null,
            'name_cyrillic' => null,
            'lat' => 44.99 + fake()->randomFloat(5, 0, 0.05),
            'lng' => 18.40 + fake()->randomFloat(5, 0, 0.05),
            'osm_type' => 'node',
            'osm_id' => fake()->unique()->numberBetween(1_000_000, 9_000_000_000),
            'osm_version' => 1,
            'osm_timestamp' => now(),
            'last_synced_at' => now(),
            'operator' => null,
            'address' => null,
            'phone' => null,
            'website' => null,
            'opening_hours' => null,
            'verification_status' => VerificationStatus::OsmImported,
            'classification_status' => GeoPlaceClassificationStatus::ExactOsmMapping,
            'assignment_status' => GeoPlaceAssignmentStatus::MunicipalityOnly,
            'point_algorithm' => 'osm_node',
            'source_properties' => ['amenity' => 'pharmacy'],
            'metadata' => ['assignment_note' => 'test'],
            'is_active' => true,
            'source_deleted_at' => null,
        ];
    }
}
