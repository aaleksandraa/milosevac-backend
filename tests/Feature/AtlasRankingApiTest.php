<?php

namespace Tests\Feature;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingMethod;
use App\Enums\CensusMappingStatus;
use App\Enums\GeoPlaceAssignmentStatus;
use App\Enums\GeoPlaceFamily;
use App\Enums\RankingDirection;
use App\Enums\RankingScope;
use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Models\GeoMunicipality;
use App\Models\GeoPlace;
use App\Models\GeoPlaceType;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementClimateProfile;
use App\Models\SettlementPopulation;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\Metrics\SettlementRankingService;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AtlasRankingApiTest extends TestCase
{
    private GeoMunicipality $modrica;

    private GeoMunicipality $samac;

    private DataSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->modrica = GeoMunicipality::query()->where('slug', 'modrica')->firstOrFail();
        $this->samac = GeoMunicipality::query()->where('slug', 'samac')->firstOrFail();
        $this->source = DataSource::query()->firstOrFail();
    }

    public function test_population_ranking_ties_percentiles_and_zero(): void
    {
        $high = $this->settlement($this->modrica, 'Alpha', 'alpha', 300);
        $tiedA = $this->settlement($this->modrica, 'Beta', 'beta', 100);
        $tiedB = $this->settlement($this->samac, 'Gama', 'gama', 100);
        $zero = $this->settlement($this->samac, 'Delta', 'delta', 0);

        $response = $this->getJson('/api/geo/rankings?metric=population_2013&scope=pilot&direction=desc&limit=all')
            ->assertOk()
            ->assertJsonPath('ranking_method', 'competition')
            ->assertJsonPath('eligible', 4)
            ->assertJsonPath('universe', 4)
            ->assertJsonPath('scope', 'pilot')
            ->assertJsonPath('entries.0.settlement.slug', 'alpha')
            ->assertJsonPath('entries.0.rank', 1)
            ->assertJsonPath('entries.1.rank', 2)
            ->assertJsonPath('entries.2.rank', 2)
            ->assertJsonPath('entries.3.rank', 4)
            ->assertJsonPath('entries.3.value', 0)
            ->assertJsonPath('entries.3.settlement.slug', 'delta');

        $this->assertSame('beta', $response->json('entries.1.settlement.slug'));
        $this->assertSame('gama', $response->json('entries.2.settlement.slug'));
        $this->assertSame(2, $response->json('entries.1.tie_count'));
        $this->assertSame(75, $response->json('entries.3.percentile_strictly_above'));
        $this->assertSame(0, $response->json('entries.3.percentile_strictly_below'));
        $this->assertStringContainsString('trenutno obrađenim naseljima', $response->json('scope_note'));
        $this->assertStringNotContainsString('Bosne i Hercegovine', $response->json('scope_note'));

        $this->assertSame($high->id, $response->json('entries.0.settlement.id'));
        $this->assertSame($tiedA->id, $response->json('entries.1.settlement.id'));
        $this->assertSame($tiedB->id, $response->json('entries.2.settlement.id'));
        $this->assertSame($zero->id, $response->json('entries.3.settlement.id'));
    }

    public function test_missing_terrain_is_excluded_not_ranked_last(): void
    {
        $withTerrain = $this->settlement($this->modrica, 'Teren A', 'teren-a', 10);
        $this->terrain($withTerrain, 90);
        $without = $this->settlement($this->modrica, 'Bez terena', 'bez-terena', 10);

        $this->getJson('/api/geo/rankings?metric=elevation_mean_m&scope=pilot&limit=all')
            ->assertOk()
            ->assertJsonPath('eligible', 1)
            ->assertJsonPath('missing', 1)
            ->assertJsonPath('universe', 2)
            ->assertJsonPath('entries.0.settlement.slug', 'teren-a')
            ->assertJsonPath('entries.0.rank', 1);

        $this->assertNull(
            collect($this->getJson('/api/geo/rankings?metric=elevation_mean_m&scope=pilot&limit=all')->json('entries'))
                ->firstWhere('settlement.slug', 'bez-terena')
        );
        $this->assertNotNull($without->id);
    }

    public function test_ranking_uses_metric_display_precision_for_ties(): void
    {
        $first = $this->settlement($this->modrica, 'Alfa klima', 'alfa-klima', 10);
        $second = $this->settlement($this->modrica, 'Beta klima', 'beta-klima', 10);
        $this->climate($first, 12.31);
        $this->climate($second, 12.34);

        $this->getJson('/api/geo/rankings?metric=temperature_mean_annual_c&scope=pilot&limit=all')
            ->assertOk()
            ->assertJsonPath('eligible', 2)
            ->assertJsonPath('entries.0.rank', 1)
            ->assertJsonPath('entries.1.rank', 1)
            ->assertJsonPath('entries.0.value', 12.3)
            ->assertJsonPath('entries.1.value', 12.3)
            ->assertJsonPath('entries.0.tie_count', 2);
    }

    public function test_climate_eligibility_and_municipality_scope(): void
    {
        $warm = $this->settlement($this->modrica, 'Toplo', 'toplo', 10);
        $cold = $this->settlement($this->modrica, 'Hladno', 'hladno', 10);
        $other = $this->settlement($this->samac, 'Drugo', 'drugo', 10);
        $this->climate($warm, 12.4);
        $this->climate($cold, 10.1);
        $this->climate($other, 11.0);

        $this->getJson('/api/geo/rankings?metric=temperature_mean_annual_c&scope=municipality&municipality=modrica&limit=all')
            ->assertOk()
            ->assertJsonPath('eligible', 2)
            ->assertJsonPath('universe', 2)
            ->assertJsonPath('entries.0.settlement.slug', 'toplo')
            ->assertJsonPath('entries.1.settlement.slug', 'hladno');
    }

    public function test_poi_ranking_counts_assigned_only(): void
    {
        $settlement = $this->settlement($this->modrica, 'Objekti', 'objekti', 10);
        $type = GeoPlaceType::factory()->create(['family' => GeoPlaceFamily::Sport, 'code' => 'ranking_test_pitch']);
        GeoPlace::factory()->create([
            'place_type_id' => $type->id,
            'municipality_id' => $this->modrica->id,
            'settlement_id' => $settlement->id,
            'assignment_status' => GeoPlaceAssignmentStatus::BoundaryMatch,
            'source_id' => $this->source->id,
            'is_active' => true,
        ]);
        GeoPlace::factory()->create([
            'place_type_id' => $type->id,
            'municipality_id' => $this->modrica->id,
            'settlement_id' => null,
            'assignment_status' => GeoPlaceAssignmentStatus::MunicipalityOnly,
            'source_id' => $this->source->id,
            'is_active' => true,
        ]);

        $this->getJson('/api/geo/rankings?metric=poi_count_sport&scope=pilot&limit=all')
            ->assertOk()
            ->assertJsonPath('entries.0.settlement.slug', 'objekti')
            ->assertJsonPath('entries.0.value', 1);
    }

    public function test_population_change_requires_direct_comparability_and_nonzero_1991(): void
    {
        $ok = $this->settlement($this->modrica, 'Uporedivo', 'uporedivo', 80);
        $this->historical($ok, 1991, 100, CensusComparability::Comparable);
        $caveat = $this->settlement($this->modrica, 'Napomena', 'napomena', 80);
        $this->historical($caveat, 1991, 100, CensusComparability::ComparableWithCaveat);
        $zeroBase = $this->settlement($this->modrica, 'Nula', 'nula-baza', 10);
        $this->historical($zeroBase, 1991, 0, CensusComparability::Comparable);

        $this->getJson('/api/geo/rankings?metric=population_change_1991_2013_pct&scope=pilot&limit=all')
            ->assertOk()
            ->assertJsonPath('eligible', 1)
            ->assertJsonPath('entries.0.settlement.slug', 'uporedivo')
            ->assertJsonPath('entries.0.value', -20);
    }

    public function test_unknown_metric_and_unimplemented_scope_rejected(): void
    {
        $this->getJson('/api/geo/rankings?metric=livability_score')->assertStatus(422);
        $this->getJson('/api/geo/rankings?metric=population_2013&scope=country')->assertStatus(422);
        $this->getJson('/api/geo/rankings?metric=population_1991')->assertStatus(422);
        $this->getJson('/api/geo/rankings?metric=population_2013&scope=municipality')->assertStatus(422);
    }

    public function test_ranking_is_deterministic_and_cache_invalidates_on_population_change(): void
    {
        $this->settlement($this->modrica, 'Prvo', 'prvo', 50);
        $second = $this->settlement($this->modrica, 'Drugo', 'drugo', 40);

        $first = $this->getJson('/api/geo/rankings?metric=population_2013&scope=pilot&limit=all')->assertOk()->json();
        $again = $this->getJson('/api/geo/rankings?metric=population_2013&scope=pilot&limit=all')->assertOk()->json();
        $this->assertSame($first['entries'], $again['entries']);

        $second->populationRecords()->update(['population' => 90]);
        Cache::flush();

        $this->getJson('/api/geo/rankings?metric=population_2013&scope=pilot&limit=all')
            ->assertOk()
            ->assertJsonPath('entries.0.settlement.slug', 'drugo')
            ->assertJsonPath('entries.0.value', 90);
    }

    public function test_settlement_relative_facts_use_eligible_denominator(): void
    {
        $low = $this->settlement($this->modrica, 'Nisko', 'nisko', 10);
        $high = $this->settlement($this->modrica, 'Visoko', 'visoko', 20);
        $this->terrain($low, 80);
        $this->terrain($high, 140);
        $this->settlement($this->samac, 'Bez', 'bez', 5);

        $facts = $this->getJson('/api/geo/municipalities/modrica/settlements/nisko')
            ->assertOk()
            ->json('settlement.relative_facts');

        $elevation = collect($facts)->firstWhere('metric', 'elevation_mean_m');
        $this->assertNotNull($elevation);
        $this->assertSame(2, $elevation['eligible']);
        $this->assertSame(2, $elevation['rank']);
        $this->assertSame(50, $elevation['percentile_strictly_above']);
        $this->assertStringContainsString('terenski profil', $elevation['eligibility_note']);
    }

    public function test_metrics_catalog_endpoint(): void
    {
        $this->getJson('/api/geo/metrics')
            ->assertOk()
            ->assertJsonPath('ranking_method', 'competition')
            ->assertJsonFragment(['code' => 'population_2013']);
    }

    public function test_service_competition_ranking_matches_api(): void
    {
        $this->settlement($this->modrica, 'A', 'a-nas', 3);
        $this->settlement($this->modrica, 'B', 'b-nas', 3);
        $this->settlement($this->modrica, 'C', 'c-nas', 1);

        $table = app(SettlementRankingService::class)->table('population_2013', RankingScope::Pilot, RankingDirection::Desc);
        $this->assertSame([1, 1, 3], $table->entries->pluck('rank')->all());
    }

    private function settlement(GeoMunicipality $municipality, string $name, string $slug, int $population2013): GeoSettlement
    {
        $settlement = GeoSettlement::factory()->create([
            'municipality_id' => $municipality->id,
            'name' => $name,
            'slug' => $slug,
        ]);
        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => 2013,
            'population' => $population2013,
            'source_id' => $this->source->id,
        ]);

        return $settlement;
    }

    private function historical(GeoSettlement $settlement, int $year, int $population, CensusComparability $comparability): void
    {
        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => $year,
            'population' => $population,
            'source_id' => $this->source->id,
        ]);
        SettlementCensusMapping::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => $this->source->id,
            'census_year' => $year,
            'historical_settlement_name' => $settlement->name,
            'historical_municipality_name' => $settlement->municipality->name,
            'mapping_status' => CensusMappingStatus::ExactIdentity,
            'mapping_method' => CensusMappingMethod::CheckedInMapping,
            'comparability' => $comparability,
            'verification_status' => VerificationStatus::OfficialSourceVerified,
        ]);
    }

    private function terrain(GeoSettlement $settlement, float $mean): void
    {
        $boundary = SettlementBoundary::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => $this->source->id,
        ]);
        SettlementTerrainStat::factory()->create([
            'settlement_id' => $settlement->id,
            'boundary_id' => $boundary->id,
            'source_id' => $this->source->id,
            'elevation_mean_m' => $mean,
            'elevation_min_m' => $mean - 5,
            'elevation_max_m' => $mean + 5,
            'elevation_range_m' => 10,
            'slope_mean_deg' => 2,
        ]);
    }

    private function climate(GeoSettlement $settlement, float $temperature): void
    {
        SettlementClimateProfile::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => $this->source->id,
            'boundary_id' => null,
            'temperature_mean_annual_c' => $temperature,
            'precipitation_annual_mm' => 700,
            'indices' => [
                ['code' => 'hot_days_30c', 'value' => 10],
                ['code' => 'frost_days', 'value' => 70],
            ],
        ]);
    }
}
