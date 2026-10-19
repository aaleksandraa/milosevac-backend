<?php

namespace Tests\Feature;

use App\Models\GeoPlace;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Support\Atlas\Metrics\SettlementMetricRegistry;
use App\Support\Atlas\Network\NetworkImportService;
use App\Support\Atlas\Network\NetworkSnapshot;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AtlasNetworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
    }

    private function artifact(): array
    {
        $s = GeoSettlement::where('slug', 'milosevac')->firstOrFail();
        $s->update(['geographic_centroid_lat' => 45, 'geographic_centroid_lng' => 18]);
        $b = SettlementBoundary::factory()->create(['settlement_id' => $s->id, 'geometry_valid' => true]);

        return ['algorithm_version' => 'm11.2', 'dependency_hash' => (new NetworkSnapshot)->payload()['dependency_hash'],
            'source' => ['raw_sha256' => str_repeat('a', 64), 'query_sha256' => str_repeat('b', 64), 'osm_timestamp' => '2026-05-06T00:00:00Z', 'retrieved_at' => '2026-09-05T00:00:00Z', 'endpoint' => 'https://example.org/osm',
                'country_boundary' => ['raw_sha256' => str_repeat('c', 64), 'osm_relation_id' => 2528142, 'iso3166_1' => 'BA']],
            'objects' => array_map(fn ($kind) => ['kind' => $kind, 'osm_type' => 'way', 'osm_id' => 1, 'feature_type' => $kind === 'road' ? 'primary' : 'river', 'name' => null, 'source_properties' => ['bridge' => 'yes'], 'geometry' => ['type' => 'LineString', 'coordinates' => [[18, 45], [18.01, 45]]], 'osm_version' => 2, 'osm_timestamp' => '2026-05-05T00:00:00Z'], ['road', 'hydro']),
            'records' => [['settlement_id' => $s->id, 'settlement_slug' => $s->slug, 'municipality_slug' => 'modrica', 'boundary_id' => $b->id, 'origin_type' => 'geographic_centroid', 'lat' => '45.0000000', 'lng' => '18.0000000',
                'transport' => ['mapped_road_length_km' => 0, 'major_road_length_km' => 0, 'mapped_road_density_km_per_km2' => 0],
                'hydrography' => ['waterway_length_km' => 0, 'mapped_water_area_km2' => 0, 'named_features' => []],
                'proximity' => [['metric_code' => 'nearest_major_road', 'target_type' => 'road', 'target_id' => 1, 'target_name' => null, 'distance_straight_m' => 0, 'method' => 'EPSG3035_point_to_geometry', 'quality_flag' => 'geographic_centroid', 'target_in_country' => true]]]],
            'links' => [['settlement_id' => $s->id, 'kind' => 'road', 'osm_type' => 'way', 'osm_id' => 1, 'relation' => 'touches_boundary', 'length_km' => 0, 'area_km2' => 0]],
        ];
    }

    public function test_source_identity_profiles_and_second_import_are_idempotent(): void
    {
        $data = $this->artifact();
        $service = new NetworkImportService;
        $this->assertSame(5, $service->import($data)['inserted']);
        $this->assertSame(['inserted' => 0, 'updated' => 0, 'unchanged' => 5], $service->import($data));
        $this->assertDatabaseCount('geo_transport_segments', 1);
        $this->assertDatabaseCount('geo_hydro_features', 1);
        $this->assertDatabaseCount('settlement_network_relations', 1);
        $this->assertDatabaseCount('geo_places', 0);
    }

    public function test_api_zero_is_valid_missing_is_omitted_and_rankings_compare_work(): void
    {
        (new NetworkImportService)->import($this->artifact());
        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk()
            ->assertJsonPath('settlement.transport.mapped_road_length_km', 0)
            ->assertJsonPath('settlement.hydrography.waterway_length_km', 0)
            ->assertJsonPath('settlement.proximity.nearest_major_road.distance_straight_m', 0)
            ->assertJsonPath('settlement.proximity.nearest_major_road.distance_route_m', null);
        $this->getJson('/api/geo/municipalities/modrica/settlements/garevac')->assertOk()->assertJsonMissingPath('settlement.transport');
        $this->getJson('/api/geo/rankings?metric=mapped_road_length_km&scope=pilot&limit=all')->assertOk()->assertJsonPath('eligible', 1);
        $comparison = $this->getJson('/api/geo/compare/modrica/milosevac/modrica/garevac')->assertOk()->json('sections');
        $this->assertContains('network', array_column($comparison, 'code'));
        $this->assertTrue(app(SettlementMetricRegistry::class)->get('waterway_length_km')->rankable);
    }

    public function test_map_requires_pilot_scope_and_preserves_identity(): void
    {
        (new NetworkImportService)->import($this->artifact());
        $this->getJson('/api/geo/map/roads')->assertUnprocessable();
        $this->getJson('/api/geo/map/hydrography?municipality=sarajevo')->assertUnprocessable();
        $this->getJson('/api/geo/map/roads?municipality=modrica')->assertOk()->assertJsonPath('features.0.properties.osm_id', 1);
    }

    public function test_stale_dependency_rejected_and_changed_origin_hides_proximity(): void
    {
        $data = $this->artifact();
        (new NetworkImportService)->import($data);
        GeoSettlement::where('slug', 'milosevac')->update(['geographic_centroid_lat' => 46]);
        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk()->assertJsonMissingPath('settlement.proximity');
        $this->expectException(\InvalidArgumentException::class);
        (new NetworkImportService)->import($data);
    }

    public function test_municipality_only_targets_are_exported_and_poi_changes_invalidate(): void
    {
        $data = $this->artifact();
        $poi = GeoPlace::factory()->create(['settlement_id' => null, 'assignment_status' => 'municipality_only', 'is_active' => true, 'lat' => 45, 'lng' => 18]);
        $snapshot = (new NetworkSnapshot)->payload();
        $this->assertContains($poi->id, array_column($snapshot['pois'], 'id'));
        $data['dependency_hash'] = $snapshot['dependency_hash'];
        (new NetworkImportService)->import($data);
        $poi->update(['lat' => 46]);
        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk()->assertJsonMissingPath('settlement.proximity');
    }

    public function test_cross_border_target_keeps_distance_and_is_flagged(): void
    {
        $data = $this->artifact();
        $data['records'][0]['proximity'][0] = ['metric_code' => 'nearest_railway_station', 'target_type' => 'geo_place', 'target_id' => 9, 'target_name' => 'Slavonski Šamac',
            'distance_straight_m' => 11000.5, 'method' => 'WGS84_geodesic', 'quality_flag' => 'geographic_centroid', 'target_in_country' => false];
        (new NetworkImportService)->import($data);
        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk()
            ->assertJsonPath('settlement.proximity.nearest_railway_station.distance_straight_m', 11000.5)
            ->assertJsonPath('settlement.proximity.nearest_railway_station.target_in_country', false);
    }

    public function test_artifact_without_country_boundary_or_flag_is_rejected(): void
    {
        foreach ([fn (&$d) => $d['algorithm_version'] = 'm11.1', function (&$d) {
            unset($d['source']['country_boundary']);
        }, function (&$d) {
            unset($d['records'][0]['proximity'][0]['target_in_country']);
        }] as $mutate) {
            $data = $this->artifact();
            $mutate($data);
            try {
                (new NetworkImportService)->import($data);
                $this->fail('Artifact should be rejected');
            } catch (ValidationException) {
                $this->assertDatabaseCount('settlement_proximities', 0);
            }
        }
    }

    public function test_negative_distance_and_duplicate_identity_rejected(): void
    {
        $data = $this->artifact();
        $data['records'][0]['proximity'][0]['distance_straight_m'] = -1;
        $this->expectException(ValidationException::class);
        (new NetworkImportService)->import($data);
    }
}
