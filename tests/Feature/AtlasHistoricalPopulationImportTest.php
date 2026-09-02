<?php

namespace Tests\Feature;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingStatus;
use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementPopulation;
use App\Support\Atlas\Import\HistoricalPopulationImportService;
use App\Support\Atlas\SourceAuthority;
use Database\Seeders\GeoAtlasSeeder;
use Tests\TestCase;

class AtlasHistoricalPopulationImportTest extends TestCase
{
    private string $dataFile;

    private string $mappingFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $this->dataFile = base_path('tests/fixtures/atlas/census-historical-mini.json');
        $this->mappingFile = base_path('tests/fixtures/atlas/census-historical-mini-mapping.json');
    }

    public function test_exact_identity_and_historical_alias_are_imported(): void
    {
        $this->importMini();

        $milosevac = $this->settlement('modrica', 'milosevac');
        $this->assertSame(1880, $this->population($milosevac, 1971));
        $this->assertSame(1825, $this->population($milosevac, 1981));
        $this->assertSame(1735, $this->population($milosevac, 1991));

        $mapping = SettlementCensusMapping::query()
            ->where('settlement_id', $milosevac->id)
            ->where('census_year', 1971)
            ->firstOrFail();
        $this->assertSame(CensusMappingStatus::ExactIdentity, $mapping->mapping_status);
        $this->assertSame('Miloševac', $mapping->historical_settlement_name);
        $this->assertSame('Modriča', $mapping->historical_municipality_name);

        $samac = $this->settlement('samac', 'samac');
        $this->assertSame(4877, $this->population($samac, 1971));
        $this->assertSame('Šamac', $samac->name);
        $rename = SettlementCensusMapping::query()
            ->where('settlement_id', $samac->id)
            ->where('census_year', 1991)
            ->firstOrFail();
        $this->assertSame(CensusMappingStatus::Renamed, $rename->mapping_status);
        $this->assertSame('Bosanski Šamac', $rename->historical_settlement_name);
    }

    public function test_ambiguous_and_unmapped_split_are_not_persisted_as_population(): void
    {
        $this->importMini();

        $this->assertFalse(
            SettlementPopulation::query()->where('population', 99)->orWhere('population', 88)->orWhere('population', 50)->exists()
        );
        $this->assertSame(2, SettlementCensusMapping::query()->where('mapping_status', CensusMappingStatus::Ambiguous)->count());
        $this->assertTrue(
            SettlementCensusMapping::query()
                ->where('historical_settlement_name', 'Gajevi dio')
                ->whereNull('settlement_id')
                ->where('mapping_status', CensusMappingStatus::Split)
                ->exists()
        );
    }

    public function test_current_municipality_does_not_override_historical_municipality(): void
    {
        $this->importMini();

        $skugric = $this->settlement('modrica', 'donji-skugric');
        $this->assertSame('modrica', $skugric->municipality->slug);
        $mapping = SettlementCensusMapping::query()
            ->where('settlement_id', $skugric->id)
            ->where('census_year', 1971)
            ->firstOrFail();
        $this->assertSame('Gradačac', $mapping->historical_municipality_name);
        $this->assertSame(1481, $this->population($skugric, 1971));
        $this->assertNull($this->population($skugric, 1981));
    }

    public function test_existing_2013_is_preserved_and_zero_is_not_missing(): void
    {
        $milosevac = $this->settlement('modrica', 'milosevac');
        $source2013 = DataSource::query()->where('source_type', 'census_settlements')->firstOrFail();
        $this->assertSame(1366, $this->population($milosevac, 2013, $source2013->id));

        $this->importMini();

        $this->assertSame(1366, $this->population($milosevac, 2013, $source2013->id));
        $this->assertSame(1, SettlementPopulation::query()
            ->where('settlement_id', $milosevac->id)
            ->where('census_year', 2013)
            ->count());

        $njegosevo = $this->settlement('samac', 'njegosevo');
        $this->assertSame(0, $this->population($njegosevo, 1971));
        $this->assertNull($this->population($this->settlement('modrica', 'borovo-polje'), 1971));
        $this->assertSame(0, $this->population($njegosevo, 2013));
    }

    public function test_import_is_idempotent(): void
    {
        $this->importMini();
        $first = $this->populationRow($this->settlement('modrica', 'milosevac'), 1971);
        $updatedAt = $first->updated_at->toDateTimeString();

        $this->importMini();

        $log = DataImport::query()->where('import_type', HistoricalPopulationImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertSame(DataImportStatus::Completed, $log->status);
        $this->assertSame(0, $log->records_inserted);
        $this->assertSame(0, $log->records_updated);
        $this->assertGreaterThan(0, $log->metadata['unchanged']);
        $this->assertSame('m8.1', $log->metadata['importer_version']);
        $this->assertNotEmpty($log->metadata['mapping_sha256']);
        $this->assertSame($updatedAt, $this->populationRow($this->settlement('modrica', 'milosevac'), 1971)->updated_at->toDateTimeString());
    }

    public function test_secondary_source_cannot_overwrite_official_historical_value(): void
    {
        $this->importMini();
        $milosevac = $this->settlement('modrica', 'milosevac');
        $wiki = DataSource::factory()->create([
            'name' => 'Encyclopedia census scrape',
            'source_class' => DataSourceClass::ManualResearch,
            'authority_rank' => SourceAuthority::SECONDARY,
            'source_type' => 'encyclopedia',
        ]);
        $payload = json_decode((string) file_get_contents($this->dataFile), true);
        $payload['source'] = [
            'name' => $wiki->name,
            'provider' => 'Wikipedia',
            'source_class' => 'D',
            'authority_rank' => SourceAuthority::SECONDARY,
            'source_type' => 'encyclopedia',
            'dataset_version' => 'wiki',
        ];
        $payload['records'] = [[
            'census_year' => 1971,
            'historical_municipality_name' => 'Modriča',
            'historical_settlement_name' => 'Miloševac',
            'population' => 9999,
        ]];
        $tmp = storage_path('app/private/atlas/census/test-wiki.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($payload));

        $this->artisan('atlas:import-historical-population', [
            '--municipality' => 'modrica',
            '--from-file' => $tmp,
            '--mapping-file' => $this->mappingFile,
        ])->assertSuccessful();

        $this->assertSame(1880, $this->population($milosevac, 1971));
        $this->assertFalse(
            SettlementPopulation::query()
                ->where('settlement_id', $milosevac->id)
                ->where('census_year', 1971)
                ->where('population', 9999)
                ->exists()
        );
        $log = DataImport::query()->where('import_type', HistoricalPopulationImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertGreaterThan(0, $log->metadata['rejected']);
        $this->assertGreaterThan(0, $log->metadata['conflicts']);
    }

    public function test_equal_rank_conflict_preserves_both_rows(): void
    {
        $this->importMini();
        $milosevac = $this->settlement('modrica', 'milosevac');
        $other = DataSource::factory()->create([
            'name' => 'Other official bulletin',
            'source_class' => DataSourceClass::Official,
            'authority_rank' => SourceAuthority::OFFICIAL_STATE,
            'source_type' => 'census_settlements_historical',
        ]);
        SettlementPopulation::query()->create([
            'settlement_id' => $milosevac->id,
            'census_year' => 1971,
            'population' => 1800,
            'source_id' => $other->id,
        ]);

        $payload = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->json('settlement');
        $row1971 = collect($payload['census'])->firstWhere('census_year', 1971);
        $this->assertSame(1880, $row1971['population']);
        $this->assertSame(2, SettlementPopulation::query()->where('settlement_id', $milosevac->id)->where('census_year', 1971)->count());
    }

    public function test_api_series_comparability_and_percentage_change(): void
    {
        $this->importMini();

        $payload = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->json('settlement');

        $this->assertSame(2013, $payload['population']['year']);
        $this->assertSame(1366, $payload['population']['population']);
        $years = collect($payload['census'])->pluck('census_year')->all();
        $this->assertSame([2013, 1991, 1981, 1971], $years);
        $row1991 = collect($payload['census'])->firstWhere('census_year', 1991);
        $this->assertSame(1735, $row1991['population']);
        $this->assertSame(CensusComparability::Comparable->value, $row1991['comparability']);
        $this->assertArrayNotHasKey('households', $row1991);

        $change = collect($payload['population_changes'])->firstWhere('from_year', 1991);
        $this->assertSame(2013, $change['to_year']);
        $this->assertSame(-369, $change['absolute_change']);
        $this->assertEqualsWithDelta(-21.3, $change['percentage_change'], 0.05);
        $this->assertSame('decline', $change['trend']);

        $babesnica = $this->getJson('/api/geo/municipalities/modrica/settlements/babesnica')
            ->assertOk()
            ->json('settlement');
        $row = collect($babesnica['census'])->firstWhere('census_year', 1991);
        $this->assertSame(CensusComparability::ComparableWithCaveat->value, $row['comparability']);
        $this->assertStringContainsString('nije nužno ista', (string) $row['reconciliation_note']);
    }

    public function test_map_change_mode_excludes_non_comparable_and_missing_1991(): void
    {
        $this->importMini();
        $milosevac = $this->settlement('modrica', 'milosevac');
        $babesnica = $this->settlement('modrica', 'babesnica');
        $njegosevo = $this->settlement('samac', 'njegosevo');

        foreach ([$milosevac, $babesnica, $njegosevo] as $settlement) {
            SettlementBoundary::factory()->create([
                'settlement_id' => $settlement->id,
                'geometry_valid' => true,
                'source_id' => DataSource::query()->where('source_type', 'osm_settlement_boundaries')->value('id')
                    ?? DataSource::query()->value('id'),
                'source_feature_id' => 'relation/test-'.$settlement->slug,
                'area_km2' => 1.5,
            ]);
        }

        $features = collect($this->getJson('/api/geo/map/settlements')->assertOk()->json('features'));
        $milo = $features->firstWhere('properties.settlement_slug', 'milosevac');
        $babe = $features->firstWhere('properties.settlement_slug', 'babesnica');
        $njego = $features->firstWhere('properties.settlement_slug', 'njegosevo');

        $this->assertEqualsWithDelta(-21.3, $milo['properties']['population_change_1991_2013_pct'], 0.05);
        $this->assertArrayNotHasKey('population_change_1991_2013_pct', $babe['properties']);
        $this->assertArrayNotHasKey('population_change_1991_2013_pct', $njego['properties']);
        $this->assertSame(0, $njego['properties']['population_2013']);
    }

    public function test_municipality_filter_does_not_import_the_other_current_municipality(): void
    {
        $this->artisan('atlas:import-historical-population', [
            '--municipality' => 'modrica',
            '--from-file' => $this->dataFile,
            '--mapping-file' => $this->mappingFile,
        ])->assertSuccessful();

        $this->assertNotNull($this->population($this->settlement('modrica', 'milosevac'), 1971));
        $this->assertNull($this->population($this->settlement('samac', 'samac'), 1971));
    }

    public function test_climate_block_still_omitted_without_profile(): void
    {
        $this->importMini();
        $payload = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->json('settlement');
        $this->assertArrayNotHasKey('climate', $payload);
        $this->assertSame(1366, $payload['population']['population']);
    }

    private function importMini(): void
    {
        $this->artisan('atlas:import-historical-population', [
            '--pilot' => true,
            '--from-file' => $this->dataFile,
            '--mapping-file' => $this->mappingFile,
        ])->assertSuccessful();
    }

    private function settlement(string $municipalitySlug, string $settlementSlug): GeoSettlement
    {
        return GeoSettlement::query()
            ->with('municipality')
            ->where('slug', $settlementSlug)
            ->whereHas('municipality', fn ($query) => $query->where('slug', $municipalitySlug))
            ->firstOrFail();
    }

    private function population(GeoSettlement $settlement, int $year, ?int $sourceId = null): ?int
    {
        $query = SettlementPopulation::query()
            ->where('settlement_id', $settlement->id)
            ->where('census_year', $year);
        if ($sourceId !== null) {
            $query->where('source_id', $sourceId);
        }
        $row = $query->first();

        return $row?->population;
    }

    private function populationRow(GeoSettlement $settlement, int $year): SettlementPopulation
    {
        return SettlementPopulation::query()
            ->where('settlement_id', $settlement->id)
            ->where('census_year', $year)
            ->firstOrFail();
    }
}
