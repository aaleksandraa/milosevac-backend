<?php

namespace Tests\Feature;

use App\Models\GeoSettlement;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GeoAtlasApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
    }

    public function test_municipality_index_lists_pilot_municipalities_only(): void
    {
        $response = $this->getJson('/api/geo/municipalities')
            ->assertOk()
            ->assertJsonPath('municipalities.0.slug', 'modrica')
            ->assertJsonPath('municipalities.1.slug', 'samac')
            ->assertJsonPath('municipalities.0.settlement_count', 24)
            ->assertJsonPath('municipalities.1.settlement_count', 21)
            ->assertJsonPath('municipalities.0.population.year', 2013)
            ->assertJsonPath('municipalities.0.population.total', 25720)
            ->assertJsonPath('municipalities.1.population.total', 17273);

        $slugs = collect($response->json('municipalities'))->pluck('slug');
        $this->assertFalse($slugs->contains('brcko'));
        $this->assertArrayNotHasKey('authority_rank', $response->json('municipalities.0.source') ?? []);
    }

    public function test_municipality_detail_includes_settlements_and_provenance(): void
    {
        $this->getJson('/api/geo/municipalities/modrica')
            ->assertOk()
            ->assertJsonPath('municipality.slug', 'modrica')
            ->assertJsonPath('municipality.admin_type', 'municipality')
            ->assertJsonPath('municipality.entity.slug', 'republika-srpska')
            ->assertJsonPath('municipality.statistical_code', '20354')
            ->assertJsonPath('municipality.settlement_count', 24)
            ->assertJsonPath('municipality.source.source_class', 'A')
            ->assertJsonMissingPath('municipality.canton')
            ->assertJsonFragment(['slug' => 'milosevac', 'path' => '/mjesta/modrica/milosevac']);
    }

    public function test_municipality_settlement_list(): void
    {
        $this->getJson('/api/geo/municipalities/samac/settlements')
            ->assertOk()
            ->assertJsonPath('municipality.slug', 'samac')
            ->assertJsonCount(21, 'settlements')
            ->assertJsonFragment(['slug' => 'njegosevo']);
    }

    public function test_modrica_milosevac_settlement_detail(): void
    {
        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->assertJsonPath('settlement.name', 'Miloševac')
            ->assertJsonPath('settlement.slug', 'milosevac')
            ->assertJsonPath('settlement.path', '/mjesta/modrica/milosevac')
            ->assertJsonPath('settlement.municipality.slug', 'modrica')
            ->assertJsonPath('settlement.entity.slug', 'republika-srpska')
            ->assertJsonPath('settlement.population.year', 2013)
            ->assertJsonPath('settlement.population.population', 1366)
            ->assertJsonPath('settlement.census.0.source.dataset_version', '2013')
            ->assertJsonPath('settlement.indexable', true)
            ->assertJsonMissingPath('settlement.points');
    }

    public function test_wrong_municipality_settlement_combination_is_404(): void
    {
        $this->getJson('/api/geo/municipalities/samac/settlements/milosevac')->assertNotFound();
        $this->getJson('/api/geo/municipalities/nepostoji')->assertNotFound();
        $this->getJson('/api/geo/municipalities/modrica/settlements/obudovac')->assertNotFound();
    }

    public function test_same_slug_does_not_cross_resolve(): void
    {
        $samacId = (int) $this->getJson('/api/geo/municipalities/samac')->json('municipality.id');
        GeoSettlement::factory()->create([
            'municipality_id' => $samacId,
            'name' => 'Miloševac (test)',
            'slug' => 'milosevac',
            'statistical_code' => '999001',
        ]);

        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->assertJsonPath('settlement.municipality.slug', 'modrica')
            ->assertJsonPath('settlement.statistical_code', '213942');

        $this->getJson('/api/geo/municipalities/samac/settlements/milosevac')
            ->assertOk()
            ->assertJsonPath('settlement.municipality.slug', 'samac')
            ->assertJsonPath('settlement.statistical_code', '999001');
    }

    public function test_population_provenance_is_included(): void
    {
        $source = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->json('settlement.source');

        $this->assertSame('A', $source['source_class']);
        $this->assertNotEmpty($source['provider']);
        $this->assertNotEmpty($source['methodology_note']);
        $this->assertStringContainsString('statistika.ba', $source['methodology_note']);
        $this->assertStringContainsString('a ne direktan bhas', mb_strtolower($source['methodology_note']));
        $this->assertArrayNotHasKey('authority_rank', $source);
    }

    public function test_njegosevo_population_zero_is_preserved(): void
    {
        $this->getJson('/api/geo/municipalities/samac/settlements/njegosevo')
            ->assertOk()
            ->assertJsonPath('settlement.population.population', 0)
            ->assertJsonPath('settlement.census.0.population', 0)
            ->assertJsonPath('settlement.census.0.households', 0);
    }

    public function test_settlement_by_numeric_id(): void
    {
        $id = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')->json('settlement.id');

        $this->getJson('/api/geo/settlements/'.$id)
            ->assertOk()
            ->assertJsonPath('settlement.slug', 'milosevac');

        $this->getJson('/api/geo/settlements/milosevac')->assertNotFound();
    }

    public function test_municipality_index_avoids_n_plus_one(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/geo/municipalities')->assertOk();

        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
    }
}
