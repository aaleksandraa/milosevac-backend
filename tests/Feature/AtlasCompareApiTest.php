<?php

namespace Tests\Feature;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingMethod;
use App\Enums\CensusMappingStatus;
use App\Enums\VerificationStatus;
use App\Models\DataSource;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementClimateProfile;
use App\Models\SettlementPopulation;
use Database\Seeders\GeoAtlasSeeder;
use Tests\TestCase;

class AtlasCompareApiTest extends TestCase
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

    public function test_compare_resolves_same_slug_through_municipality(): void
    {
        $this->settlement($this->modrica, 'Miloševac', 'milosevac', 1366);
        $this->settlement($this->samac, 'Miloševac', 'milosevac', 50);

        $this->getJson('/api/geo/compare/modrica/milosevac/samac/milosevac')
            ->assertOk()
            ->assertJsonPath('a.municipality.slug', 'modrica')
            ->assertJsonPath('b.municipality.slug', 'samac')
            ->assertJsonPath('a.name', 'Miloševac')
            ->assertJsonPath('b.name', 'Miloševac');

        $population = $this->row('population_2013');
        $this->assertSame(1366, $population['a']['value']);
        $this->assertSame(50, $population['b']['value']);
        $this->assertSame(1316, $population['difference']['absolute']);
    }

    public function test_invalid_municipality_settlement_pair_is_404(): void
    {
        $this->settlement($this->modrica, 'Miloševac', 'milosevac', 1366);
        $this->getJson('/api/geo/compare/samac/milosevac/modrica/milosevac')->assertNotFound();
        $this->getJson('/api/geo/compare/modrica/milosevac/samac/nepostoji')->assertNotFound();
    }

    public function test_unavailable_metric_is_not_zero(): void
    {
        $left = $this->settlement($this->modrica, 'Sa klimom', 'sa-klimom', 100);
        $this->settlement($this->samac, 'Bez klime', 'bez-klime', 80);
        SettlementClimateProfile::factory()->create([
            'settlement_id' => $left->id,
            'source_id' => $this->source->id,
            'boundary_id' => null,
            'temperature_mean_annual_c' => 11.6,
            'precipitation_annual_mm' => 780,
        ]);

        $row = $this->row('temperature_mean_annual_c', 'modrica/sa-klimom/samac/bez-klime');
        $this->assertSame(11.6, $row['a']['value']);
        $this->assertNull($row['b']['value']);
        $this->assertSame('Nema dostupnog podatka', $row['b']['unavailable_reason']);
        $this->assertFalse($row['comparable']);
        $this->assertNull($row['difference']);
    }

    public function test_historical_caveat_is_surfaced_and_strict_change_not_comparable(): void
    {
        $left = $this->settlement($this->modrica, 'Caveat', 'caveat', 80);
        $right = $this->settlement($this->samac, 'Cisto', 'cisto', 90);
        $this->historical($left, 100, CensusComparability::ComparableWithCaveat);
        $this->historical($right, 120, CensusComparability::Comparable);

        $change = $this->row('population_change_1991_2013_pct', 'modrica/caveat/samac/cisto');
        $this->assertNotNull($change['a']['value']);
        $this->assertNotNull($change['b']['value']);
        $this->assertFalse($change['comparable']);
        $this->assertNotNull($change['caveat']);

        $census = $this->row('population_1991', 'modrica/caveat/samac/cisto');
        $this->assertSame(100, $census['a']['value']);
        $this->assertNotNull($census['caveat']);
    }

    public function test_unknown_compare_metric_is_422(): void
    {
        $this->settlement($this->modrica, 'A', 'a', 1);
        $this->settlement($this->samac, 'B', 'b', 2);
        $this->getJson('/api/geo/compare/modrica/a/samac/b?metrics=livability_score')->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $metric, string $path = 'modrica/milosevac/samac/milosevac'): array
    {
        $payload = $this->getJson('/api/geo/compare/'.$path)->assertOk()->json();
        foreach ($payload['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                if ($row['metric'] === $metric) {
                    return $row;
                }
            }
        }

        $this->fail('Missing comparison metric '.$metric);
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

    private function historical(GeoSettlement $settlement, int $population1991, CensusComparability $comparability): void
    {
        SettlementPopulation::factory()->create([
            'settlement_id' => $settlement->id,
            'census_year' => 1991,
            'population' => $population1991,
            'source_id' => $this->source->id,
        ]);
        SettlementCensusMapping::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => $this->source->id,
            'census_year' => 1991,
            'historical_settlement_name' => $settlement->name,
            'historical_municipality_name' => $settlement->municipality->name,
            'mapping_status' => CensusMappingStatus::ExactIdentity,
            'mapping_method' => CensusMappingMethod::CheckedInMapping,
            'comparability' => $comparability,
            'verification_status' => VerificationStatus::OfficialSourceVerified,
        ]);
    }
}
