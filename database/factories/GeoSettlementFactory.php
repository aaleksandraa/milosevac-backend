<?php

namespace Database\Factories;

use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeoSettlement>
 */
class GeoSettlementFactory extends Factory
{
    protected $model = GeoSettlement::class;

    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'municipality_id' => GeoMunicipality::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'name_cyrillic' => null,
            'official_code' => null,
            'statistical_code' => null,
            'osm_relation_id' => null,
            'wikidata_id' => null,
            'geonames_id' => null,
            'outage_place_id' => null,
            'geographic_centroid_lat' => null,
            'geographic_centroid_lng' => null,
            'label_point_lat' => null,
            'label_point_lng' => null,
            'label_point_source_id' => null,
            'label_point_verification_status' => null,
        ];
    }
}
