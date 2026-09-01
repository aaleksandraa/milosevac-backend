<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Support\Atlas\Geometry\GeoJsonPolygon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementBoundary>
 */
class SettlementBoundaryFactory extends Factory
{
    protected $model = SettlementBoundary::class;

    public function definition(): array
    {
        $lng = 18.40 + fake()->randomFloat(5, 0, 0.02);
        $lat = 44.99 + fake()->randomFloat(5, 0, 0.02);
        $geometry = GeoJsonPolygon::from([
            'type' => 'Polygon',
            'coordinates' => [[
                [$lng, $lat],
                [$lng + 0.01, $lat],
                [$lng + 0.01, $lat + 0.01],
                [$lng, $lat + 0.01],
                [$lng, $lat],
            ]],
        ]);
        $bbox = $geometry->bbox();

        return [
            'settlement_id' => GeoSettlement::factory(),
            'source_id' => DataSource::factory(),
            'geom_geojson' => $geometry->encoded(),
            'source_feature_id' => 'relation/'.fake()->unique()->numberBetween(1000, 999999),
            'boundary_version' => 'osm-test',
            'source_properties' => ['name' => fake()->city()],
            'bbox_min_lng' => $bbox['min_lng'],
            'bbox_min_lat' => $bbox['min_lat'],
            'bbox_max_lng' => $bbox['max_lng'],
            'bbox_max_lat' => $bbox['max_lat'],
            'geometry_valid' => true,
            'verification_status' => VerificationStatus::OsmImported,
            'area_km2' => $geometry->areaKm2(),
            'area_algorithm' => GeoJsonPolygon::AREA_ALGORITHM,
            'area_calculated_at' => now(),
            'geometry_fingerprint' => $geometry->fingerprint(),
            'imported_at' => now(),
        ];
    }
}
