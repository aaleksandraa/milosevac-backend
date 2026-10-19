<?php

namespace Tests\Feature;

use App\Models\GeoPlace;
use App\Models\GeoSettlement;
use App\Models\Place;
use App\Models\SettlementBoundary;
use App\Models\User;
use App\Support\Atlas\Network\NetworkImportService;
use App\Support\Atlas\Network\NetworkSnapshot;
use App\Support\Atlas\Snapshot\AtlasSnapshotService;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AtlasSnapshotTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $s = GeoSettlement::where('slug', 'milosevac')->firstOrFail();
        $s->update(['geographic_centroid_lat' => 45, 'geographic_centroid_lng' => 18]);
        $b = SettlementBoundary::factory()->create(['settlement_id' => $s->id, 'geometry_valid' => true]);
        GeoPlace::factory()->create(['settlement_id' => $s->id, 'is_active' => true, 'lat' => 45.01, 'lng' => 18.01]);
        (new NetworkImportService)->import([
            'algorithm_version' => 'm11.2', 'dependency_hash' => (new NetworkSnapshot)->payload()['dependency_hash'],
            'source' => ['raw_sha256' => str_repeat('a', 64), 'query_sha256' => str_repeat('b', 64), 'osm_timestamp' => '2026-05-06T00:00:00Z', 'retrieved_at' => '2026-09-05T00:00:00Z', 'endpoint' => 'https://example.org/osm',
                'country_boundary' => ['raw_sha256' => str_repeat('c', 64), 'osm_relation_id' => 2528142]],
            'objects' => [['kind' => 'road', 'osm_type' => 'way', 'osm_id' => 1, 'feature_type' => 'primary', 'name' => null, 'source_properties' => [], 'geometry' => ['type' => 'LineString', 'coordinates' => [[18, 45], [18.01, 45]]], 'osm_version' => 1, 'osm_timestamp' => '2026-05-05T00:00:00Z']],
            'records' => [['settlement_id' => $s->id, 'settlement_slug' => $s->slug, 'municipality_slug' => 'modrica', 'boundary_id' => $b->id, 'origin_type' => 'geographic_centroid', 'lat' => '45.0000000', 'lng' => '18.0000000',
                'transport' => ['mapped_road_length_km' => 0.5, 'major_road_length_km' => 0.5, 'mapped_road_density_km_per_km2' => 1], 'hydrography' => null,
                'proximity' => [['metric_code' => 'nearest_major_road', 'target_type' => 'road', 'target_id' => 1, 'target_name' => null, 'distance_straight_m' => 0, 'method' => 'EPSG3035_point_to_geometry', 'quality_flag' => 'geographic_centroid', 'target_in_country' => true]]]],
            'links' => [],
        ]);
        $this->path = sys_get_temp_dir().'/atlas-snapshot-test-'.uniqid().'.ndjson.gz';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function atlasRows(): array
    {
        return collect(AtlasSnapshotService::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    private function wipeAtlas(): void
    {
        foreach (array_reverse(AtlasSnapshotService::TABLES) as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_round_trip_preserves_ids_and_leaves_other_tables_alone(): void
    {
        $before = $this->atlasRows();
        $this->artisan('atlas:export-snapshot', ['--output' => $this->path])->assertSuccessful();
        $this->wipeAtlas();
        $user = User::factory()->create();

        $this->artisan('atlas:import-snapshot', ['--from-file' => $this->path])->assertSuccessful()->expectsOutputToContain('Network dependency hash verified');

        $this->assertEquals($before, $this->atlasRows());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk()
            ->assertJsonPath('settlement.proximity.nearest_major_road.distance_straight_m', 0);
    }

    public function test_refuses_when_atlas_has_data_and_dry_run_writes_nothing(): void
    {
        $this->artisan('atlas:export-snapshot', ['--output' => $this->path])->assertSuccessful();
        $before = $this->atlasRows();
        $this->artisan('atlas:import-snapshot', ['--from-file' => $this->path])->assertFailed()->expectsOutputToContain('--replace');
        $this->assertEquals($before, $this->atlasRows());

        $this->wipeAtlas();
        $this->artisan('atlas:import-snapshot', ['--from-file' => $this->path, '--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Dry run');
        $this->assertSame(0, DB::table('geo_settlements')->count());
    }

    public function test_replace_only_touches_atlas_tables(): void
    {
        $this->artisan('atlas:export-snapshot', ['--output' => $this->path])->assertSuccessful();
        $before = $this->atlasRows();
        $user = User::factory()->create();
        GeoSettlement::where('slug', 'milosevac')->update(['name' => 'Changed']);

        $this->artisan('atlas:import-snapshot', ['--from-file' => $this->path, '--replace' => true])->assertSuccessful();

        $this->assertEquals($before, $this->atlasRows());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_outage_place_link_is_remapped_by_slug(): void
    {
        $place = Place::firstOrCreate(['slug' => 'milosevac'], ['name' => 'Miloševac']);
        GeoSettlement::where('slug', 'milosevac')->update(['outage_place_id' => $place->id]);
        $this->artisan('atlas:export-snapshot', ['--output' => $this->path])->assertSuccessful();
        $this->wipeAtlas();
        $place->delete();
        $moved = Place::create(['slug' => 'milosevac', 'name' => 'Miloševac', 'sort_order' => 0]);

        $this->artisan('atlas:import-snapshot', ['--from-file' => $this->path])->assertSuccessful();

        $this->assertSame($moved->id, GeoSettlement::where('slug', 'milosevac')->value('outage_place_id'));
    }

    public function test_network_hash_mismatch_rolls_back_everything(): void
    {
        $this->artisan('atlas:export-snapshot', ['--output' => $this->path])->assertSuccessful();
        $lines = gzfile($this->path);
        $header = json_decode($lines[0], true);
        $header['header']['network_dependency_hash'] = str_repeat('0', 64);
        $lines[0] = json_encode($header)."\n";
        file_put_contents($this->path, gzencode(implode('', $lines)));
        $this->wipeAtlas();

        $this->artisan('atlas:import-snapshot', ['--from-file' => $this->path])->assertFailed()->expectsOutputToContain('hash differs');

        $this->assertSame(0, DB::table('geo_settlements')->count());
    }
}
