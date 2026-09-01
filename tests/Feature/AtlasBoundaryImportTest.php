<?php

namespace Tests\Feature;

use App\Enums\VerificationStatus;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\Geometry\GeoJsonPolygon;
use Database\Seeders\GeoAtlasSeeder;
use Tests\TestCase;

class AtlasBoundaryImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
    }

    public function test_boundary_belongs_to_correct_settlement_and_does_not_cross_match_same_name(): void
    {
        $samacId = GeoSettlement::query()
            ->where('slug', 'samac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'samac'))
            ->value('id');
        GeoSettlement::factory()->create([
            'municipality_id' => GeoSettlement::query()->findOrFail($samacId)->municipality_id,
            'name' => 'Miloševac',
            'slug' => 'milosevac',
            'statistical_code' => '999001',
            'label_point_lat' => 45.1111111,
            'label_point_lng' => 18.2222222,
        ]);

        $this->artisan('atlas:import-boundaries', [
            '--pilot' => true,
            '--file' => base_path('tests/fixtures/atlas/boundaries-two-milosevac.geojson'),
            '--mapping' => base_path('tests/fixtures/atlas/boundaries-two-milosevac-mapping.json'),
        ])->assertSuccessful();

        $modrica = GeoSettlement::query()
            ->where('slug', 'milosevac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'modrica'))
            ->firstOrFail();
        $samac = GeoSettlement::query()
            ->where('slug', 'milosevac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'samac'))
            ->firstOrFail();

        $this->assertSame('relation/1001', $modrica->boundaries()->value('source_feature_id'));
        $this->assertSame('relation/2001', $samac->boundaries()->value('source_feature_id'));
        $this->assertSame('45.1111111', (string) $samac->fresh()->label_point_lat);
        $this->assertSame('18.2222222', (string) $samac->fresh()->label_point_lng);
        $this->assertNotNull($modrica->fresh()->geographic_centroid_lat);
        $this->assertNotEquals($modrica->geographic_centroid_lat, $modrica->label_point_lat);
    }

    public function test_boundary_import_is_idempotent(): void
    {
        $args = [
            '--municipality' => 'modrica',
            '--file' => base_path('tests/fixtures/atlas/boundaries-two-milosevac.geojson'),
            '--mapping' => base_path('tests/fixtures/atlas/boundaries-two-milosevac-mapping.json'),
        ];
        $this->artisan('atlas:import-boundaries', $args)->assertSuccessful();
        $this->artisan('atlas:import-boundaries', $args)
            ->expectsOutputToContain('Inserted boundaries: 0')
            ->expectsOutputToContain('Unchanged boundaries: 1')
            ->assertSuccessful();

        $this->assertSame(1, SettlementBoundary::query()->count());
    }

    public function test_ambiguous_boundary_mapping_is_rejected(): void
    {
        $this->artisan('atlas:import-boundaries', [
            '--pilot' => true,
            '--file' => base_path('tests/fixtures/atlas/boundaries-two-milosevac.geojson'),
            '--mapping' => base_path('tests/fixtures/atlas/boundaries-ambiguous-mapping.json'),
        ])->assertFailed();

        $this->assertSame(0, SettlementBoundary::query()->count());
    }

    public function test_invalid_geometry_is_flagged(): void
    {
        $this->artisan('atlas:import-boundaries', [
            '--municipality' => 'modrica',
            '--file' => base_path('tests/fixtures/atlas/boundaries-invalid-point.geojson'),
            '--mapping' => base_path('tests/fixtures/atlas/boundaries-invalid-mapping.json'),
        ])->expectsOutputToContain('Invalid geometries: 1')->assertSuccessful();

        $this->assertSame(0, SettlementBoundary::query()->count());
    }

    public function test_area_metadata_and_centroid_are_stored(): void
    {
        $this->artisan('atlas:import-boundaries', [
            '--municipality' => 'modrica',
            '--file' => base_path('tests/fixtures/atlas/boundaries-two-milosevac.geojson'),
            '--mapping' => base_path('tests/fixtures/atlas/boundaries-two-milosevac-mapping.json'),
        ])->assertSuccessful();

        $boundary = SettlementBoundary::query()->firstOrFail();
        $this->assertSame(GeoJsonPolygon::AREA_ALGORITHM, $boundary->area_algorithm);
        $this->assertGreaterThan(0, (float) $boundary->area_km2);
        $this->assertTrue($boundary->geometry_valid);
        $this->assertSame(VerificationStatus::OsmImported, $boundary->verification_status);
        $this->assertNotNull($boundary->settlement->geographic_centroid_lat);
        $this->assertNull($boundary->settlement->label_point_lat);
    }

    public function test_terrain_api_block_and_missing_terrain_and_population_unchanged(): void
    {
        $this->artisan('atlas:import-boundaries', [
            '--municipality' => 'modrica',
            '--file' => base_path('tests/fixtures/atlas/boundaries-two-milosevac.geojson'),
            '--mapping' => base_path('tests/fixtures/atlas/boundaries-two-milosevac-mapping.json'),
        ])->assertSuccessful();

        $withoutTerrain = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk();
        $this->assertArrayNotHasKey('terrain', $withoutTerrain->json('settlement.geography') ?? []);
        $this->assertSame(1366, $withoutTerrain->json('settlement.population.population'));
        $this->assertNotNull($withoutTerrain->json('settlement.geography.area_km2'));

        $settlement = GeoSettlement::query()
            ->where('slug', 'milosevac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'modrica'))
            ->firstOrFail();
        $boundary = $settlement->boundaries()->firstOrFail();
        SettlementTerrainStat::factory()->create([
            'settlement_id' => $settlement->id,
            'boundary_id' => $boundary->id,
            'source_id' => $boundary->source_id,
            'elevation_min_m' => 0,
            'elevation_max_m' => 12.5,
            'elevation_mean_m' => 6.2,
            'elevation_median_m' => 6.0,
            'elevation_range_m' => 12.5,
            'slope_mean_deg' => 1.4,
            'valid_cell_count' => 20,
            'sample_count' => 20,
            'nodata_cell_count' => 0,
            'quality_flag' => SettlementTerrainStat::QUALITY_OK,
        ]);

        $withTerrain = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk();
        $this->assertSame(0, $withTerrain->json('settlement.geography.terrain.elevation_min_m'));
        $this->assertSame(12.5, $withTerrain->json('settlement.geography.terrain.elevation_max_m'));
        $this->assertSame(1366, $withTerrain->json('settlement.population.population'));
        $this->assertSame(2013, $withTerrain->json('settlement.census.0.census_year'));
        $this->assertArrayNotHasKey('path', $withTerrain->json('settlement.geography.terrain'));
    }

    public function test_terrain_nodata_zero_is_not_fabricated_in_import_payload(): void
    {
        $this->artisan('atlas:import-boundaries', [
            '--municipality' => 'modrica',
            '--file' => base_path('tests/fixtures/atlas/boundaries-two-milosevac.geojson'),
            '--mapping' => base_path('tests/fixtures/atlas/boundaries-two-milosevac-mapping.json'),
        ])->assertSuccessful();

        $settlement = GeoSettlement::query()
            ->where('slug', 'milosevac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'modrica'))
            ->firstOrFail();
        $boundary = $settlement->boundaries()->firstOrFail();

        $path = storage_path('app/private/atlas/terrain/test-stats.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode([
            'source' => ['dataset_version' => '2021_1', 'retrieval_date' => '2026-09-02'],
            'records' => [[
                'settlement_id' => $settlement->id,
                'boundary_id' => $boundary->id,
                'elevation_min_m' => 0,
                'elevation_max_m' => 8,
                'elevation_mean_m' => 4,
                'elevation_median_m' => 4,
                'elevation_range_m' => 8,
                'sample_count' => 12,
                'valid_cell_count' => 9,
                'nodata_cell_count' => 3,
                'quality_flag' => 'ok',
                'dem_source' => 'Copernicus DEM GLO-30 Public',
                'dem_version' => '2021_1',
                'dem_resolution_m' => 30,
                'algorithm_version' => 'm4.1',
                'calculated_at' => '2026-09-02T00:00:00Z',
                'metadata' => ['nodata_is_not_zero' => true],
            ]],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('atlas:import-terrain', ['--pilot' => true, '--file' => $path])->assertSuccessful();
        $this->artisan('atlas:import-terrain', ['--pilot' => true, '--file' => $path])
            ->expectsOutputToContain('Inserted: 0')
            ->assertSuccessful();

        $row = SettlementTerrainStat::query()->firstOrFail();
        $this->assertSame(0.0, (float) $row->elevation_min_m);
        $this->assertSame(3, $row->nodata_cell_count);
        $this->assertSame($boundary->id, $row->boundary_id);
        $this->assertSame('2021_1', $row->dem_version);
        $this->assertSame('m4.1', $row->algorithm_version);
    }
}
