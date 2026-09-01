<?php

namespace Tests\Feature;

use App\Enums\GeoPlaceAssignmentStatus;
use App\Enums\GeoPlaceClassificationStatus;
use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Models\GeoPlace;
use App\Models\GeoPlaceType;
use App\Models\GeoSettlement;
use App\Support\Atlas\Map\PlaceMapPresenter;
use Database\Seeders\GeoAtlasSeeder;
use Tests\TestCase;

class GeoPlaceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $this->seedPlaces();
    }

    public function test_places_list_filters_by_family_type_and_municipality(): void
    {
        $response = $this->getJson('/api/geo/places?municipality=modrica&family=health')->assertOk();
        $this->assertCount(1, $response->json('places'));
        $this->assertSame('pharmacy', $response->json('places.0.type'));
        $this->assertSame('Apoteka Modriča', $response->json('places.0.name'));
        $this->assertArrayNotHasKey('source_properties', $response->json('places.0'));
        $this->assertStringNotContainsString('amenity', $response->getContent());

        $this->getJson('/api/geo/places?municipality=modrica&type=football_pitch')
            ->assertOk()
            ->assertJsonCount(1, 'places')
            ->assertJsonPath('places.0.name', null)
            ->assertJsonPath('places.0.display_label', 'Fudbalski teren')
            ->assertJsonPath('places.0.name_is_generic', true);

        $this->getJson('/api/geo/places?family=not-a-family')->assertStatus(422);
        $this->getJson('/api/geo/places?type=spaceship')->assertStatus(422);
        $this->getJson('/api/geo/places?municipality=nepostoji')->assertNotFound();
    }

    public function test_settlement_places_do_not_cross_resolve(): void
    {
        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac/places')
            ->assertOk()
            ->assertJsonPath('meta.count', 2);

        $this->getJson('/api/geo/municipalities/samac/settlements/milosevac/places')->assertNotFound();
        $this->getJson('/api/geo/places?municipality=samac&settlement=milosevac')->assertNotFound();
        $this->getJson('/api/geo/places?municipality=modrica&settlement=obudovac')->assertNotFound();
    }

    public function test_map_places_geojson_is_compact_points(): void
    {
        $payload = $this->getJson('/api/geo/map/places?municipality=modrica')->assertOk()->json();
        $this->assertSame('FeatureCollection', $payload['type']);
        $this->assertSame(2, $payload['meta']['feature_count']);
        $this->assertSame(PlaceMapPresenter::COVERAGE_NOTE, $payload['meta']['coverage_note']);
        $this->assertSame('Point', $payload['features'][0]['geometry']['type']);
        $this->assertArrayNotHasKey('source_properties', $payload['features'][0]['properties']);
        $this->assertArrayHasKey('display_label', $payload['features'][0]['properties']);

        $filtered = $this->getJson('/api/geo/map/places?municipality=modrica&family=sport')->assertOk()->json('features');
        $this->assertCount(1, $filtered);
        $this->assertSame('football_pitch', $filtered[0]['properties']['type']);

        $this->getJson('/api/geo/map/places?municipality=modrica&settlement=milosevac')->assertOk()->assertJsonPath('meta.feature_count', 2);
        $this->getJson('/api/geo/map/places?settlement=milosevac')->assertStatus(422);
        $this->getJson('/api/geo/map/places?municipality=nepostoji')->assertNotFound();
    }

    public function test_m3_m5_endpoints_are_unchanged(): void
    {
        $detail = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->assertOk();
        $this->assertSame(1366, $detail->json('settlement.population.population'));
        $this->assertArrayNotHasKey('places', $detail->json('settlement'));
        $this->assertArrayNotHasKey('geometry', $detail->json('settlement'));

        $this->getJson('/api/geo/municipalities')->assertOk()->assertJsonPath('municipalities.0.slug', 'modrica');
        $this->getJson('/api/geo/map/settlements')->assertOk()->assertJsonPath('type', 'FeatureCollection');
    }

    private function seedPlaces(): void
    {
        $milosevac = GeoSettlement::query()
            ->where('slug', 'milosevac')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'modrica'))
            ->firstOrFail();
        $source = DataSource::query()->where('source_type', 'osm_pois')->firstOrFail();
        $pharmacy = GeoPlaceType::query()->where('code', 'pharmacy')->firstOrFail();
        $pitch = GeoPlaceType::query()->where('code', 'football_pitch')->firstOrFail();

        GeoPlace::factory()->create([
            'place_type_id' => $pharmacy->id,
            'settlement_id' => $milosevac->id,
            'municipality_id' => $milosevac->municipality_id,
            'source_id' => $source->id,
            'name' => 'Apoteka Modriča',
            'lat' => 44.995,
            'lng' => 18.405,
            'osm_type' => 'node',
            'osm_id' => 555001,
            'verification_status' => VerificationStatus::OsmImported,
            'classification_status' => GeoPlaceClassificationStatus::ExactOsmMapping,
            'assignment_status' => GeoPlaceAssignmentStatus::BoundaryMatch,
            'source_properties' => ['amenity' => 'pharmacy', 'name' => 'Apoteka Modriča'],
        ]);

        GeoPlace::factory()->create([
            'place_type_id' => $pitch->id,
            'settlement_id' => $milosevac->id,
            'municipality_id' => $milosevac->municipality_id,
            'source_id' => $source->id,
            'name' => null,
            'lat' => 44.994,
            'lng' => 18.404,
            'osm_type' => 'way',
            'osm_id' => 555002,
            'verification_status' => VerificationStatus::OsmImported,
            'classification_status' => GeoPlaceClassificationStatus::ExactOsmMapping,
            'assignment_status' => GeoPlaceAssignmentStatus::BoundaryMatch,
            'source_properties' => ['leisure' => 'pitch', 'sport' => 'soccer'],
        ]);
    }
}
