<?php

namespace Tests\Feature;

use App\Enums\DataSourceClass;
use App\Enums\GeoEntityKind;
use App\Enums\GeoMunicipalityAdminType;
use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Models\GeoCanton;
use App\Models\GeoCountry;
use App\Models\GeoEntity;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Models\Place;
use App\Models\SettlementPopulation;
use App\Support\Atlas\SourceAuthority;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GeoAtlasHierarchyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
    }

    public function test_country_has_three_entities(): void
    {
        $country = GeoCountry::query()->where('iso2', 'BA')->firstOrFail();

        $this->assertSame('Bosna i Hercegovina', $country->name);
        $this->assertSame('BIH', $country->iso3);
        $this->assertCount(3, $country->entities);

        $kinds = $country->entities->pluck('kind')->all();
        $this->assertContains(GeoEntityKind::Federation, $kinds);
        $this->assertContains(GeoEntityKind::Republic, $kinds);
        $this->assertContains(GeoEntityKind::District, $kinds);
    }

    public function test_fbih_canton_can_own_a_municipality(): void
    {
        $federation = GeoEntity::query()->where('slug', 'federacija-bih')->firstOrFail();
        $canton = GeoCanton::query()->where('slug', 'tuzlanski')->firstOrFail();

        $this->assertTrue($federation->cantons->contains($canton));
        $this->assertCount(10, $federation->cantons);
        $this->assertSame($federation->id, $canton->entity_id);

        $municipality = GeoMunicipality::factory()->create([
            'entity_id' => $federation->id,
            'canton_id' => $canton->id,
            'name' => 'Testna općina FBiH',
            'slug' => 'testna-opcina-fbih',
            'admin_type' => GeoMunicipalityAdminType::Municipality,
        ]);

        $this->assertTrue($canton->municipalities->contains($municipality));
        $this->assertSame($canton->id, $municipality->canton_id);
        $this->assertSame($federation->id, $municipality->entity_id);
    }

    public function test_rs_municipality_has_null_canton(): void
    {
        $modrica = GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail();
        $samac = GeoMunicipality::query()->where('slug', 'samac')->firstOrFail();
        $rs = GeoEntity::query()->where('slug', 'republika-srpska')->firstOrFail();

        $this->assertNull($modrica->canton_id);
        $this->assertNull($samac->canton_id);
        $this->assertSame($rs->id, $modrica->entity_id);
        $this->assertSame($rs->id, $samac->entity_id);
        $this->assertSame(GeoMunicipalityAdminType::Municipality, $modrica->admin_type);
        $this->assertSame(GeoMunicipalityAdminType::Municipality, $samac->admin_type);
        $this->assertSame(0, $rs->cantons()->count());
    }

    public function test_brcko_district_hierarchy(): void
    {
        $entity = GeoEntity::query()->where('slug', 'brcko-distrikt')->firstOrFail();
        $unit = GeoMunicipality::query()->where('slug', 'brcko')->firstOrFail();

        $this->assertSame(GeoEntityKind::District, $entity->kind);
        $this->assertSame($entity->id, $unit->entity_id);
        $this->assertNull($unit->canton_id);
        $this->assertSame(GeoMunicipalityAdminType::District, $unit->admin_type);
        $this->assertSame(0, $entity->cantons()->count());
    }

    public function test_municipality_admin_types_are_cast(): void
    {
        $city = GeoMunicipality::factory()->create([
            'entity_id' => GeoEntity::query()->where('slug', 'republika-srpska')->value('id'),
            'canton_id' => null,
            'slug' => 'testni-grad',
            'name' => 'Testni grad',
            'admin_type' => GeoMunicipalityAdminType::City,
        ]);

        $this->assertSame(GeoMunicipalityAdminType::City, $city->fresh()->admin_type);
        $this->assertSame(GeoMunicipalityAdminType::Municipality, GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail()->admin_type);
        $this->assertSame(GeoMunicipalityAdminType::District, GeoMunicipality::query()->where('slug', 'brcko')->firstOrFail()->admin_type);
    }

    public function test_settlement_belongs_to_municipality(): void
    {
        $modrica = GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail();
        $settlement = GeoSettlement::factory()->create([
            'municipality_id' => $modrica->id,
            'name' => 'Testno naselje',
            'slug' => 'testno-naselje',
        ]);

        $this->assertTrue($settlement->municipality->is($modrica));
        $this->assertTrue($modrica->settlements->contains($settlement));
    }

    public function test_same_settlement_slug_is_allowed_in_different_municipalities(): void
    {
        $modrica = GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail();
        $samac = GeoMunicipality::query()->where('slug', 'samac')->firstOrFail();

        GeoSettlement::factory()->create([
            'municipality_id' => $modrica->id,
            'slug' => 'centar',
            'name' => 'Centar',
        ]);

        $duplicate = GeoSettlement::factory()->create([
            'municipality_id' => $samac->id,
            'slug' => 'centar',
            'name' => 'Centar',
        ]);

        $this->assertSame('centar', $duplicate->slug);
        $this->assertSame(2, GeoSettlement::query()->where('slug', 'centar')->count());
    }

    public function test_duplicate_settlement_slug_is_rejected_inside_the_same_municipality(): void
    {
        $modrica = GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail();

        GeoSettlement::factory()->create([
            'municipality_id' => $modrica->id,
            'slug' => 'centar',
            'name' => 'Centar',
        ]);

        $this->expectException(QueryException::class);

        GeoSettlement::factory()->create([
            'municipality_id' => $modrica->id,
            'slug' => 'centar',
            'name' => 'Centar 2',
        ]);
    }

    public function test_population_is_a_time_series_and_allows_two_sources_for_the_same_year(): void
    {
        $settlement = GeoSettlement::factory()->create([
            'municipality_id' => GeoMunicipality::query()->where('slug', 'modrica')->value('id'),
            'slug' => 'test-pop',
            'name' => 'Test pop',
        ]);

        $official = DataSource::factory()->create([
            'name' => 'BHAS census A',
            'source_class' => DataSourceClass::Official,
            'authority_rank' => SourceAuthority::OFFICIAL_STATE,
        ]);
        $secondary = DataSource::factory()->create([
            'name' => 'Secondary yearbook',
            'source_class' => DataSourceClass::ReputablePublic,
            'authority_rank' => SourceAuthority::SECONDARY,
        ]);

        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => 1991,
            'population' => 1200,
            'source_id' => $official->id,
            'boundary_version' => '1991-census',
        ]);
        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => 2013,
            'population' => 980,
            'source_id' => $official->id,
            'boundary_version' => '2013-census',
        ]);
        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => 2013,
            'population' => 995,
            'source_id' => $secondary->id,
            'boundary_version' => '2013-census',
        ]);

        $this->assertCount(3, $settlement->populationRecords);
        $this->assertCount(2, $settlement->populationRecords()->where('census_year', 2013)->get());
        $this->assertFalse(Schema::hasColumn('geo_settlements', 'population'));
        $this->assertFalse(Schema::hasColumn('geo_settlements', 'population_2013'));
    }

    public function test_duplicate_population_row_for_same_source_and_year_is_rejected(): void
    {
        $settlement = GeoSettlement::factory()->create([
            'municipality_id' => GeoMunicipality::query()->where('slug', 'modrica')->value('id'),
            'slug' => 'test-pop-dup',
        ]);
        $source = DataSource::factory()->create();

        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => 2013,
            'source_id' => $source->id,
        ]);

        $this->expectException(QueryException::class);

        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => 2013,
            'source_id' => $source->id,
        ]);
    }

    public function test_seeded_data_sources_store_authority_rank(): void
    {
        $fzs = DataSource::query()->where('name', 'like', 'Federalni zavod za statistiku%')->firstOrFail();
        $osm = DataSource::query()->where('name', 'OpenStreetMap')->firstOrFail();

        $this->assertSame(DataSourceClass::Official, $fzs->source_class);
        $this->assertSame(SourceAuthority::OFFICIAL_STATE, $fzs->authority_rank);
        $this->assertSame(DataSourceClass::CommunityGeographic, $osm->source_class);
        $this->assertSame(SourceAuthority::OPENSTREETMAP, $osm->authority_rank);
        $this->assertGreaterThan($osm->authority_rank, $fzs->authority_rank);
    }

    public function test_outage_place_id_is_optional_and_can_link_existing_places(): void
    {
        $unlinked = GeoSettlement::factory()->create([
            'municipality_id' => GeoMunicipality::query()->where('slug', 'modrica')->value('id'),
            'slug' => 'bez-place',
            'outage_place_id' => null,
        ]);

        $this->assertNull($unlinked->outage_place_id);
        $this->assertNull($unlinked->outagePlace);

        $place = Place::query()->create([
            'name' => 'Atlas test place',
            'slug' => 'atlas-test-place',
            'locative' => 'Atlas test placeu',
            'aliases' => ['atlas test'],
            'is_primary' => false,
            'sort_order' => 99,
        ]);

        $linked = GeoSettlement::factory()->create([
            'municipality_id' => GeoMunicipality::query()->where('slug', 'modrica')->value('id'),
            'slug' => 'sa-place',
            'outage_place_id' => $place->id,
            'label_point_verification_status' => VerificationStatus::Unverified,
        ]);

        $this->assertTrue($linked->outagePlace->is($place));
        $this->assertTrue($place->atlasSettlement->is($linked));
        $this->assertSame(VerificationStatus::Unverified, $linked->label_point_verification_status);
    }

    public function test_atlas_migrations_roll_back_and_can_be_reapplied(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 12])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('geo_places'));
        $this->assertFalse(Schema::hasTable('geo_place_types'));
        $this->assertFalse(Schema::hasTable('settlement_terrain_stats'));
        $this->assertFalse(Schema::hasTable('settlement_boundaries'));
        $this->assertFalse(Schema::hasTable('settlement_population'));
        $this->assertFalse(Schema::hasTable('geo_settlements'));
        $this->assertFalse(Schema::hasTable('geo_countries'));
        $this->assertFalse(Schema::hasTable('data_sources'));
        $this->assertTrue(Schema::hasTable('places'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('geo_settlements'));
        $this->assertTrue(Schema::hasTable('geo_places'));
        $this->assertTrue(Schema::hasTable('geo_place_types'));
        $this->assertTrue(Schema::hasTable('places'));
    }
}
