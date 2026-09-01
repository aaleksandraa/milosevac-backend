<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\Map\SettlementMapPresenter;
use Database\Seeders\GeoAtlasSeeder;
use Tests\TestCase;

class GeoMapSettlementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
    }

    public function test_map_endpoint_returns_feature_collection_only_for_valid_boundaries(): void
    {
        $modrica = $this->settlement('modrica', 'milosevac');
        $njegosevo = $this->settlement('samac', 'njegosevo');
        $missing = $this->settlement('modrica', 'botajica');

        $this->attachBoundary($modrica, [
            'source_feature_id' => 'relation/14590939',
            'area_km2' => 17.3782,
        ]);
        $this->attachBoundary($njegosevo, [
            'source_feature_id' => 'relation/njegosevo-test',
            'area_km2' => 1.25,
        ]);
        SettlementBoundary::factory()->create([
            'settlement_id' => $missing->id,
            'source_id' => $this->boundarySource()->id,
            'geometry_valid' => false,
            'source_feature_id' => 'relation/invalid-botajica',
        ]);
        SettlementTerrainStat::factory()->create([
            'settlement_id' => $modrica->id,
            'boundary_id' => $modrica->boundaries()->firstOrFail()->id,
            'source_id' => $this->terrainSource()->id,
            'elevation_min_m' => 88.0,
            'elevation_max_m' => 119.0,
            'elevation_mean_m' => 96.4,
            'elevation_median_m' => 95.1,
            'elevation_range_m' => 31.0,
            'slope_mean_deg' => 2.45,
            'quality_flag' => SettlementTerrainStat::QUALITY_OK,
        ]);

        $response = $this->getJson('/api/geo/map/settlements')->assertOk();
        $payload = $response->json();

        $this->assertSame('FeatureCollection', $payload['type']);
        $this->assertCount(2, $payload['features']);
        $this->assertSame(2, $payload['meta']['feature_count']);
        $this->assertSame('osm_settlement_boundaries', $payload['meta']['boundary_source_type']);
        $this->assertSame(SettlementMapPresenter::BOUNDARY_NOTE, $payload['meta']['boundary_note']);
        $this->assertSame(SettlementMapPresenter::TERRAIN_NOTE, $payload['meta']['terrain_note']);
        $this->assertArrayHasKey('bbox', $payload);

        $ids = collect($payload['features'])->pluck('properties.settlement_id')->all();
        $this->assertContains($modrica->id, $ids);
        $this->assertContains($njegosevo->id, $ids);
        $this->assertNotContains($missing->id, $ids);

        $milosevac = collect($payload['features'])->firstWhere('properties.settlement_slug', 'milosevac');
        $this->assertSame($modrica->id, $milosevac['id']);
        $this->assertSame('modrica', $milosevac['properties']['municipality_slug']);
        $this->assertSame('/mjesta/modrica/milosevac', $milosevac['properties']['path']);
        $this->assertSame(17.3782, $milosevac['properties']['area_km2']);
        $this->assertSame(1366, $milosevac['properties']['population_2013']);
        $this->assertSame(96.4, $milosevac['properties']['elevation_mean_m']);
        $this->assertSame(2.45, $milosevac['properties']['slope_mean_deg']);
        $this->assertSame('ok', $milosevac['properties']['terrain_quality_flag']);
        $this->assertSame('osm_settlement_boundaries', $milosevac['properties']['boundary_source_type']);
        $this->assertContains($milosevac['geometry']['type'], ['Polygon', 'MultiPolygon']);
        $this->assertIsArray($milosevac['geometry']['coordinates']);

        $zeroPop = collect($payload['features'])->firstWhere('properties.settlement_slug', 'njegosevo');
        $this->assertSame(0, $zeroPop['properties']['population_2013']);
        $this->assertSame('samac', $zeroPop['properties']['municipality_slug']);

        $encoded = $response->getContent();
        $this->assertStringNotContainsString('authority_rank', $encoded);
        $this->assertStringNotContainsString('geom_geojson', $encoded);
        $this->assertStringNotContainsString('storage/', $encoded);
        $this->assertStringNotContainsString('tools/atlas', $encoded);
        $this->assertArrayNotHasKey('source_properties', $milosevac['properties']);

        $this->assertNotEmpty($response->headers->get('ETag'));
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=300', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=600', $cacheControl);
        $this->assertNotEmpty($response->headers->get('Last-Modified'));
    }

    public function test_municipality_filter_keeps_qualified_identities(): void
    {
        $modrica = $this->settlement('modrica', 'milosevac');
        $samacTwin = GeoSettlement::factory()->create([
            'municipality_id' => $this->settlement('samac', 'samac')->municipality_id,
            'name' => 'Miloševac',
            'slug' => 'milosevac',
            'statistical_code' => '999001',
        ]);

        $this->attachBoundary($modrica, ['source_feature_id' => 'relation/modrica-milosevac']);
        $this->attachBoundary($samacTwin, ['source_feature_id' => 'relation/samac-milosevac']);

        $filtered = $this->getJson('/api/geo/map/settlements?municipality=modrica')->assertOk()->json('features');
        $this->assertCount(1, $filtered);
        $this->assertSame($modrica->id, $filtered[0]['properties']['settlement_id']);
        $this->assertSame('modrica', $filtered[0]['properties']['municipality_slug']);
        $this->assertSame('milosevac', $filtered[0]['properties']['settlement_slug']);

        $all = $this->getJson('/api/geo/map/settlements')->assertOk()->json('features');
        $twins = collect($all)->filter(fn (array $feature) => $feature['properties']['settlement_slug'] === 'milosevac');
        $this->assertCount(2, $twins);
        $this->assertNotEquals(
            $twins->first()['properties']['municipality_slug'],
            $twins->last()['properties']['municipality_slug'],
        );
    }

    public function test_nonexistent_municipality_returns_404(): void
    {
        $this->getJson('/api/geo/map/settlements?municipality=nepostoji')->assertNotFound();
    }

    public function test_etag_allows_304_and_changes_when_boundaries_change(): void
    {
        $this->attachBoundary($this->settlement('modrica', 'milosevac'));

        $first = $this->getJson('/api/geo/map/settlements')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/geo/map/settlements')
            ->assertStatus(304);

        SettlementBoundary::query()->firstOrFail()->forceFill([
            'updated_at' => now()->addMinute(),
        ])->save();

        $second = $this->getJson('/api/geo/map/settlements')->assertOk();
        $this->assertNotSame($etag, $second->headers->get('ETag'));
        $this->assertSame('FeatureCollection', $second->json('type'));
    }

    public function test_existing_settlement_endpoints_remain_unchanged(): void
    {
        $this->attachBoundary($this->settlement('modrica', 'milosevac'));

        $detail = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk();
        $this->assertSame(1366, $detail->json('settlement.population.population'));
        $this->assertSame(2013, $detail->json('settlement.census.0.census_year'));
        $this->assertArrayNotHasKey('geometry', $detail->json('settlement') ?? []);
        $this->assertArrayNotHasKey('features', $detail->json());
        $this->assertNull($detail->headers->get('ETag'));

        $this->getJson('/api/geo/municipalities')->assertOk()->assertJsonPath('municipalities.0.slug', 'modrica');
        $this->getJson('/api/geo/municipalities/modrica')->assertOk()->assertJsonPath('municipality.slug', 'modrica');
    }

    private function settlement(string $municipalitySlug, string $settlementSlug): GeoSettlement
    {
        return GeoSettlement::query()
            ->where('slug', $settlementSlug)
            ->whereHas('municipality', fn ($query) => $query->where('slug', $municipalitySlug))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function attachBoundary(GeoSettlement $settlement, array $overrides = []): SettlementBoundary
    {
        return SettlementBoundary::factory()->create(array_merge([
            'settlement_id' => $settlement->id,
            'source_id' => $this->boundarySource()->id,
            'geometry_valid' => true,
        ], $overrides));
    }

    private function boundarySource(): DataSource
    {
        return DataSource::query()->where('source_type', 'osm_settlement_boundaries')->firstOrFail();
    }

    private function terrainSource(): DataSource
    {
        return DataSource::query()->where('source_type', 'copernicus_dem_glo30')->firstOrFail();
    }
}
