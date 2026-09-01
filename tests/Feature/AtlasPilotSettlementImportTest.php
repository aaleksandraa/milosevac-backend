<?php

namespace Tests\Feature;

use App\Enums\DataImportStatus;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Models\Place;
use App\Models\SettlementPopulation;
use Database\Seeders\GeoAtlasSeeder;
use Database\Seeders\PlaceSeeder;
use Tests\TestCase;

class AtlasPilotSettlementImportTest extends TestCase
{
    private function fixture(string $name = 'census-2013-pilot-settlements.json'): string
    {
        return $name === 'census-2013-pilot-settlements.json'
            ? base_path('database/imports/atlas/census-2013-pilot-settlements.json')
            : base_path('tests/fixtures/atlas/'.$name);
    }

    public function test_pilot_import_assigns_modrica_and_samac_settlements(): void
    {
        $this->seed(GeoAtlasSeeder::class);
        $this->seed(PlaceSeeder::class);

        $this->artisan('atlas:import-settlements', ['--pilot' => true])
            ->expectsOutputToContain('Municipality: modrica, samac')
            ->expectsOutputToContain('Inserted:')
            ->assertSuccessful();

        $modrica = GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail();
        $samac = GeoMunicipality::query()->where('slug', 'samac')->firstOrFail();

        $this->assertSame('20354', $modrica->statistical_code);
        $this->assertSame('20656', $samac->statistical_code);
        $this->assertNull($modrica->canton_id);
        $this->assertNull($samac->canton_id);
        $this->assertSame(24, $modrica->settlements()->count());
        $this->assertSame(21, $samac->settlements()->count());
        $this->assertTrue($modrica->settlements()->where('slug', 'milosevac')->exists());
        $this->assertTrue($samac->settlements()->where('slug', 'obudovac')->exists());
        $this->assertFalse($samac->settlements()->where('slug', 'milosevac')->exists());
    }

    public function test_settlement_slug_is_not_matched_across_municipalities(): void
    {
        $this->seed(GeoAtlasSeeder::class);

        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();

        $modricaId = GeoMunicipality::query()->where('slug', 'modrica')->value('id');
        $samacId = GeoMunicipality::query()->where('slug', 'samac')->value('id');

        $this->assertNotEquals(
            GeoSettlement::query()->where('municipality_id', $modricaId)->where('slug', 'modrica')->value('id'),
            GeoSettlement::query()->where('municipality_id', $samacId)->where('slug', 'samac')->value('id'),
        );
        $this->assertSame(1, GeoSettlement::query()->where('municipality_id', $modricaId)->where('slug', 'modrica')->count());
        $this->assertSame(0, GeoSettlement::query()->where('municipality_id', $samacId)->where('slug', 'modrica')->count());
    }

    public function test_second_import_is_idempotent(): void
    {
        $this->seed(GeoAtlasSeeder::class);
        $this->seed(PlaceSeeder::class);

        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $this->artisan('atlas:import-settlements', ['--pilot' => true])
            ->expectsOutputToContain('Inserted: 0')
            ->expectsOutputToContain('Updated: 0')
            ->assertSuccessful();

        $this->assertSame(45, GeoSettlement::query()->count());
        $latest = DataImport::query()->latest('id')->firstOrFail();
        $this->assertSame(DataImportStatus::Completed, $latest->status);
        $this->assertSame(45, $latest->records_read);
        $this->assertSame(0, $latest->records_inserted);
        $this->assertSame(0, $latest->records_updated);
        $this->assertSame(45, $latest->records_skipped);
    }

    public function test_stable_statistical_code_matches_existing_row(): void
    {
        $this->seed(GeoAtlasSeeder::class);
        $modrica = GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail();
        $settlement = GeoSettlement::factory()->create([
            'municipality_id' => $modrica->id,
            'name' => 'Stari naziv',
            'slug' => 'stari-naziv',
            'statistical_code' => '213942',
        ]);

        $this->artisan('atlas:import-settlements', [
            '--municipality' => 'modrica',
            '--file' => $this->fixture(),
        ])
            ->expectsOutputToContain('Inserted: 23')
            ->expectsOutputToContain('Updated: 1')
            ->assertSuccessful();

        $this->assertSame('Miloševac', $settlement->fresh()->name);
        $this->assertSame('milosevac', $settlement->fresh()->slug);
        $this->assertSame(1, GeoSettlement::query()->where('statistical_code', '213942')->count());
        $this->assertSame(24, GeoSettlement::query()->where('municipality_id', $modrica->id)->count());
    }

    public function test_missing_municipality_marks_import_failed(): void
    {
        $this->seed(GeoAtlasSeeder::class);

        $this->artisan('atlas:import-settlements', ['--municipality' => 'nepostoji'])
            ->assertFailed();

        $import = DataImport::query()->latest('id')->firstOrFail();
        $this->assertSame(DataImportStatus::Failed, $import->status);
        $this->assertStringContainsString('nije pronađena', (string) $import->errors);
    }

    public function test_duplicate_statistical_codes_in_fixture_are_rejected(): void
    {
        $this->seed(GeoAtlasSeeder::class);

        $this->artisan('atlas:import-settlements', [
            '--municipality' => 'modrica',
            '--file' => $this->fixture('duplicate-statistical-code.json'),
        ])->assertFailed();

        $this->assertSame(0, GeoSettlement::query()->count());
    }

    public function test_outage_place_is_linked_only_on_unique_exact_name(): void
    {
        $this->seed(GeoAtlasSeeder::class);
        $this->seed(PlaceSeeder::class);

        $this->artisan('atlas:import-settlements', ['--municipality' => 'modrica'])->assertSuccessful();

        $milosevac = GeoSettlement::query()->where('slug', 'milosevac')->firstOrFail();
        $skugricGornji = GeoSettlement::query()->where('slug', 'skugric-gornji')->firstOrFail();

        $this->assertNotNull($milosevac->outage_place_id);
        $this->assertSame('milosevac', $milosevac->outagePlace->slug);
        $this->assertNull($skugricGornji->outage_place_id);
    }

    public function test_ambiguous_outage_place_remains_unlinked(): void
    {
        $this->seed(GeoAtlasSeeder::class);
        Place::query()->create([
            'name' => 'Dobrinja',
            'slug' => 'dobrinja-a',
            'is_primary' => false,
            'sort_order' => 1,
        ]);
        Place::query()->create([
            'name' => 'Dobrinja',
            'slug' => 'dobrinja-b',
            'is_primary' => false,
            'sort_order' => 2,
        ]);

        $this->artisan('atlas:import-settlements', [
            '--municipality' => 'modrica',
            '--file' => $this->fixture('modrica-dobrinja.json'),
        ])->assertSuccessful();

        $this->assertNull(GeoSettlement::query()->where('slug', 'dobrinja')->value('outage_place_id'));
    }

    public function test_weaker_source_does_not_overwrite_official_identity(): void
    {
        $this->seed(GeoAtlasSeeder::class);

        $this->artisan('atlas:import-settlements', [
            '--municipality' => 'modrica',
            '--file' => $this->fixture(),
        ])->assertSuccessful();

        $this->artisan('atlas:import-settlements', [
            '--municipality' => 'modrica',
            '--file' => $this->fixture('weaker-source-milosevac.json'),
        ])->assertSuccessful();

        $settlement = GeoSettlement::query()->where('statistical_code', '213942')->firstOrFail();
        $this->assertSame('Miloševac', $settlement->name);
        $this->assertSame(1366, SettlementPopulation::query()
            ->where('settlement_id', $settlement->id)
            ->where('census_year', 2013)
            ->where('source_id', DataSource::query()->where('source_type', 'census_settlements')->value('id'))
            ->value('population'));
        $this->assertTrue(SettlementPopulation::query()
            ->where('settlement_id', $settlement->id)
            ->where('census_year', 2013)
            ->whereHas('source', fn ($query) => $query->where('name', 'Niski autoritet test izvor'))
            ->exists());
    }

    public function test_population_rows_carry_census_provenance(): void
    {
        $this->seed(GeoAtlasSeeder::class);

        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();

        $row = SettlementPopulation::query()
            ->where('statistical_settlement_code', '213942')
            ->firstOrFail();

        $this->assertSame(2013, $row->census_year);
        $this->assertSame(1366, $row->population);
        $this->assertSame(469, $row->households);
        $this->assertNotNull($row->source_id);
        $this->assertSame(0, SettlementPopulation::query()->where('census_year', 2013)->whereNull('source_id')->count());
        $this->assertSame(0, GeoSettlement::query()->where('geographic_centroid_lat', '!=', null)->count());
    }
}
