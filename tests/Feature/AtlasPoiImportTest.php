<?php

namespace Tests\Feature;

use App\Enums\DataImportStatus;
use App\Enums\GeoPlaceAssignmentStatus;
use App\Enums\GeoPlaceFamily;
use App\Enums\VerificationStatus;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoPlace;
use App\Models\GeoPlaceType;
use App\Models\GeoSettlement;
use App\Models\Place;
use App\Models\SettlementBoundary;
use App\Support\Atlas\Geometry\GeoJsonPolygon;
use App\Support\Atlas\Import\PoiImportService;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasPoiImportTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $this->fixture = base_path('tests/fixtures/atlas/osm-pois-mini.json');
        $this->attachMilosevacSquare();
    }

    public function test_geo_place_types_registry_is_seeded_and_places_table_is_untouched(): void
    {
        $this->assertGreaterThanOrEqual(50, GeoPlaceType::query()->count());
        $this->assertTrue(GeoPlaceType::query()->where('code', 'pharmacy')->where('family', GeoPlaceFamily::Health)->exists());
        $this->assertTrue(GeoPlaceType::query()->where('code', 'football_pitch')->exists());
        $this->assertTrue(Schema::hasTable('places'));
        $this->assertSame(0, Place::query()->where('name', 'Apoteka Test')->count());
    }

    public function test_imports_nodes_and_way_representative_points_with_stable_osm_identity(): void
    {
        $this->artisan('atlas:import-pois', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $pharmacy = GeoPlace::query()->where('osm_type', 'node')->where('osm_id', 1001)->firstOrFail();
        $this->assertSame('Apoteka Test', $pharmacy->name);
        $this->assertSame('pharmacy', $pharmacy->placeType->code);
        $this->assertSame(VerificationStatus::OsmImported, $pharmacy->verification_status);
        $this->assertSame(GeoPlaceAssignmentStatus::BoundaryMatch, $pharmacy->assignment_status);
        $this->assertSame('milosevac', $pharmacy->settlement->slug);
        $this->assertSame('modrica', $pharmacy->municipality->slug);
        $this->assertSame('https://example.test/apoteka', $pharmacy->website);
        $this->assertStringContainsString('Ulica Test', (string) $pharmacy->address);
        $this->assertArrayHasKey('amenity', $pharmacy->source_properties);
        $this->assertSame('osm_node', $pharmacy->point_algorithm);

        $pitch = GeoPlace::query()->where('osm_type', 'way')->where('osm_id', 2001)->firstOrFail();
        $this->assertNull($pitch->name);
        $this->assertSame('football_pitch', $pitch->placeType->code);
        $this->assertNotEquals(18.4040, (float) $pitch->lng);
        $this->assertSame('geographic_centroid_inside', $pitch->point_algorithm);

        $twin = GeoPlace::query()->where('osm_type', 'node')->where('osm_id', 1002)->firstOrFail();
        $this->assertSame('Apoteka Test', $twin->name);
        $this->assertNotEquals($pharmacy->id, $twin->id);

        $this->assertFalse(GeoPlace::query()->where('osm_id', 1006)->exists());
        $this->assertFalse(GeoPlace::query()->where('osm_id', 1007)->exists());
        $this->assertFalse(GeoPlace::query()->where('osm_id', 1008)->exists());

        $outside = GeoPlace::query()->where('osm_id', 1005)->firstOrFail();
        $this->assertNull($outside->settlement_id);
        $this->assertSame(GeoPlaceAssignmentStatus::MunicipalityOnly, $outside->assignment_status);
        $this->assertSame('modrica', $outside->municipality->slug);

        $import = DataImport::query()->where('import_type', PoiImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertSame(DataImportStatus::Completed, $import->status);
        $this->assertSame(9, $import->records_read);
        $this->assertSame(6, $import->records_inserted);
        $this->assertSame('osm_pois', DataSource::query()->find($import->data_source_id)?->source_type);
    }

    public function test_reimport_is_idempotent_and_osm_version_change_updates(): void
    {
        $this->artisan('atlas:import-pois', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $first = GeoPlace::query()->where('osm_id', 1001)->firstOrFail();
        $updatedAt = $first->updated_at->toDateTimeString();

        $this->artisan('atlas:import-pois', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $secondLog = DataImport::query()->where('import_type', PoiImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertSame(0, $secondLog->records_inserted);
        $this->assertSame(0, $secondLog->records_updated);
        $this->assertSame(6, $secondLog->metadata['unchanged']);
        $this->assertSame($updatedAt, GeoPlace::query()->where('osm_id', 1001)->firstOrFail()->updated_at->toDateTimeString());

        $changed = json_decode((string) file_get_contents($this->fixture), true);
        $changed['elements'][0]['version'] = 9;
        $changed['elements'][0]['tags']['phone'] = '+387 53 111 111';
        $tmp = storage_path('app/private/atlas/osm/test-changed.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($changed));

        $this->artisan('atlas:import-pois', [
            '--municipality' => 'modrica',
            '--from-file' => $tmp,
        ])->assertSuccessful();

        $updated = GeoPlace::query()->where('osm_id', 1001)->firstOrFail();
        $this->assertSame(9, $updated->osm_version);
        $this->assertSame('+387 53 111 111', $updated->phone);
        $thirdLog = DataImport::query()->where('import_type', PoiImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertSame(1, $thirdLog->records_updated);
    }

    public function test_outage_places_are_not_used_as_poi_identity(): void
    {
        Place::query()->create([
            'name' => 'Apoteka Test',
            'slug' => 'apoteka-test-outage',
            'locative' => 'Apoteci Test',
            'is_primary' => false,
            'sort_order' => 50,
        ]);

        $this->artisan('atlas:import-pois', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $this->assertSame(2, GeoPlace::query()->where('name', 'Apoteka Test')->count());
        $this->assertNull(GeoPlace::query()->where('osm_id', 1001)->first()?->settlement?->outage_place_id);
    }

    private function attachMilosevacSquare(): void
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
            'source_feature_id' => 'relation/test-milosevac-poi',
            'bbox_min_lng' => $bbox['min_lng'],
            'bbox_min_lat' => $bbox['min_lat'],
            'bbox_max_lng' => $bbox['max_lng'],
            'bbox_max_lat' => $bbox['max_lat'],
            'geometry_valid' => true,
            'area_km2' => $geometry->areaKm2(),
            'geometry_fingerprint' => $geometry->fingerprint(),
        ]);
    }
}
