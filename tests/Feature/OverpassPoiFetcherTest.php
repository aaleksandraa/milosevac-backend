<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Support\Atlas\Geometry\GeoJsonPolygon;
use App\Support\Atlas\Poi\OverpassPoiFetcher;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverpassPoiFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $this->attachBoundary();
        config(['atlas_poi.overpass.endpoints' => ['https://overpass.test/api/interpreter']]);
        config(['atlas_poi.overpass.retry_sleep_ms' => [0, 0, 0]]);
        config(['atlas_poi.overpass.inter_municipality_sleep_ms' => 0]);
        config(['atlas_poi.overpass.max_attempts' => 3]);
    }

    public function test_successful_response_is_cached_with_hash_and_query_metadata(): void
    {
        Http::fake([
            'overpass.test/*' => Http::response([
                'osm3s' => ['timestamp_osm_base' => '2026-09-01T12:00:00Z'],
                'elements' => [
                    ['type' => 'node', 'id' => 1, 'lat' => 44.99, 'lon' => 18.40, 'tags' => ['amenity' => 'pharmacy']],
                ],
            ], 200),
        ]);

        $result = (new OverpassPoiFetcher)->fetchMunicipality('modrica');
        $this->assertFileExists($result['path']);
        $this->assertSame(1, $result['meta']['element_count']);
        $this->assertSame('https://overpass.test/api/interpreter', $result['meta']['endpoint']);
        $this->assertNotEmpty($result['meta']['response_hash']);
        $this->assertNotEmpty($result['meta']['query']);
        $this->assertSame('2026-09-01T12:00:00Z', $result['meta']['overpass_timestamp']);
        Http::assertSentCount(1);
    }

    public function test_retries_on_429_then_succeeds_without_long_sleep(): void
    {
        Http::fake([
            'overpass.test/*' => Http::sequence()
                ->push('rate limit', 429)
                ->push(['elements' => []], 200),
        ]);

        $started = microtime(true);
        (new OverpassPoiFetcher)->fetchMunicipality('modrica');
        $this->assertLessThan(1.5, microtime(true) - $started);
        Http::assertSentCount(2);
    }

    public function test_malformed_response_fails(): void
    {
        Http::fake([
            'overpass.test/*' => Http::response('not-json', 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('validan JSON');
        (new OverpassPoiFetcher)->fetchMunicipality('modrica');
    }

    public function test_timeout_is_not_retried_forever(): void
    {
        Http::fake([
            'overpass.test/*' => Http::response('gateway timeout', 504),
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            (new OverpassPoiFetcher)->fetchMunicipality('modrica');
        } finally {
            Http::assertSentCount(3);
        }
    }

    private function attachBoundary(): void
    {
        $settlement = GeoSettlement::query()
            ->where('slug', 'milosevac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'modrica'))
            ->firstOrFail();
        $geometry = GeoJsonPolygon::from([
            'type' => 'Polygon',
            'coordinates' => [[
                [18.40, 44.99],
                [18.41, 44.99],
                [18.41, 45.00],
                [18.40, 45.00],
                [18.40, 44.99],
            ]],
        ]);
        $bbox = $geometry->bbox();
        SettlementBoundary::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => DataSource::query()->where('source_type', 'osm_settlement_boundaries')->value('id'),
            'geom_geojson' => $geometry->encoded(),
            'bbox_min_lng' => $bbox['min_lng'],
            'bbox_min_lat' => $bbox['min_lat'],
            'bbox_max_lng' => $bbox['max_lng'],
            'bbox_max_lat' => $bbox['max_lat'],
            'geometry_valid' => true,
        ]);
    }
}
