<?php

namespace Tests\Feature;

use App\Enums\DataImportStatus;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementClimateExtreme;
use App\Models\SettlementClimateMonth;
use App\Models\SettlementClimateProfile;
use App\Support\Atlas\ClimatePresenter;
use App\Support\Atlas\Import\ClimateImportService;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class AtlasClimateImportTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $this->fixture = $this->preparedFixture();
    }

    public function test_climate_profile_relations_and_normal_period(): void
    {
        $this->artisan('atlas:import-climate', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $profile = $this->milosevacProfile();
        $this->assertTrue($profile->settlement->is($this->settlement('modrica', 'milosevac')));
        $this->assertSame('copernicus_era5_land', $profile->source->source_type);
        $this->assertSame('1991-01-01', $profile->normal_period_start->toDateString());
        $this->assertSame('2020-12-31', $profile->normal_period_end->toDateString());
        $this->assertSame('1950-01-01', $profile->historical_period_start->toDateString());
        $this->assertSame('2025-12-31', $profile->historical_period_end->toDateString());
        $this->assertSame('m7.1', $profile->algorithm_version);
        $this->assertSame(SettlementClimateProfile::QUALITY_COARSE, $profile->quality_flag);
        $this->assertCount(12, $profile->monthly);
        $this->assertCount(3, $profile->extremes);
        $this->assertTrue($profile->isPubliclyUsable());
    }

    public function test_monthly_unique_constraint(): void
    {
        $this->artisan('atlas:import-climate', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $this->expectException(QueryException::class);
        SettlementClimateMonth::query()->create([
            'climate_profile_id' => $this->milosevacProfile()->id,
            'month' => 1,
            'temperature_mean_c' => 1.0,
            'precipitation_mean_mm' => 10,
        ]);
    }

    public function test_temperature_is_celsius_and_precipitation_is_mm(): void
    {
        $this->artisan('atlas:import-climate', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $profile = $this->milosevacProfile();
        $this->assertEqualsWithDelta(11.6, (float) $profile->temperature_mean_annual_c, 0.05);
        $this->assertLessThan(50, (float) $profile->temperature_mean_annual_c);
        $this->assertEqualsWithDelta(780.0, (float) $profile->precipitation_annual_mm, 0.05);
        $this->assertGreaterThan(100, (float) $profile->precipitation_annual_mm);

        $january = $profile->monthly->firstWhere('month', 1);
        $this->assertEqualsWithDelta(0.0, (float) $january->temperature_mean_c, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $january->precipitation_mean_mm, 0.001);
    }

    public function test_precipitation_accumulation_is_not_treated_as_instantaneous_rate(): void
    {
        $hourlyMetres = array_fill(0, 24, 0.001);
        $dailyMm = array_sum($hourlyMetres) * 1000;

        $this->assertEqualsWithDelta(24.0, $dailyMm, 0.0001);
        $this->assertNotEqualsWithDelta(1.0, array_sum($hourlyMetres) / count($hourlyMetres), 0.1);
    }

    public function test_import_is_idempotent_and_algorithm_version_creates_a_new_profile(): void
    {
        $this->artisan('atlas:import-climate', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $first = $this->milosevacProfile();
        $updatedAt = $first->updated_at->toDateTimeString();

        $this->artisan('atlas:import-climate', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $secondLog = DataImport::query()->where('import_type', ClimateImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertSame(DataImportStatus::Completed, $secondLog->status);
        $this->assertSame(0, $secondLog->records_inserted);
        $this->assertSame(0, $secondLog->records_updated);
        $this->assertSame(1, $secondLog->metadata['unchanged']);
        $this->assertSame('m7.1', $secondLog->metadata['algorithm_version']);
        $this->assertSame($updatedAt, $this->milosevacProfile()->updated_at->toDateTimeString());
        $this->assertSame(1, SettlementClimateProfile::query()->where('settlement_id', $first->settlement_id)->count());

        $changed = json_decode((string) file_get_contents($this->fixture), true);
        $changed['records'][0]['algorithm_version'] = 'm7.2';
        $changed['records'][0]['annual']['temperature_mean_c'] = 12.1;
        $tmp = storage_path('app/private/atlas/climate/test-m72.json');
        @mkdir(dirname($tmp), 0777, true);
        file_put_contents($tmp, json_encode($changed));

        $this->artisan('atlas:import-climate', [
            '--municipality' => 'modrica',
            '--from-file' => $tmp,
        ])->assertSuccessful();

        $this->assertSame(2, SettlementClimateProfile::query()->where('settlement_id', $first->settlement_id)->count());
        $this->assertTrue(SettlementClimateProfile::query()->where('algorithm_version', 'm7.2')->exists());
        $this->assertTrue(SettlementClimateProfile::query()->where('algorithm_version', 'm7.1')->exists());
    }

    public function test_unavailable_and_missing_boundary_rows_are_not_persisted(): void
    {
        $this->artisan('atlas:import-climate', [
            '--pilot' => true,
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $njegosevo = $this->settlement('samac', 'njegosevo');
        $this->assertSame(0, SettlementClimateProfile::query()->where('settlement_id', $njegosevo->id)->count());

        $log = DataImport::query()->where('import_type', ClimateImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertSame(1, $log->metadata['unavailable']);
        $this->assertSame('copernicus_era5_land', DataSource::query()->find($log->data_source_id)?->source_type);
    }

    public function test_climate_api_block_and_extremes_are_reanalysis_wording(): void
    {
        $this->artisan('atlas:import-climate', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $payload = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->json('settlement');

        $this->assertArrayHasKey('climate', $payload);
        $this->assertSame('1991–2020', $payload['climate']['normal_period']);
        $this->assertEqualsWithDelta(11.6, $payload['climate']['annual']['temperature_mean_c'], 0.001);
        $this->assertEqualsWithDelta(780.0, $payload['climate']['annual']['precipitation_mm'], 0.001);
        $this->assertEqualsWithDelta(0.0, $payload['climate']['monthly'][0]['temperature_mean_c'], 0.001);
        $this->assertEqualsWithDelta(0.0, $payload['climate']['monthly'][0]['precipitation_mean_mm'], 0.001);
        $this->assertSame(12, count($payload['climate']['monthly']));
        $this->assertSame('coarse_spatial_resolution', $payload['climate']['methodology']['quality_flag']);
        $this->assertSame('Europe/Sarajevo', $payload['climate']['methodology']['timezone']);
        $this->assertSame('ERA5-Land', $payload['climate']['methodology']['temperature_dataset']);
        $this->assertSame('ERA5', $payload['climate']['methodology']['precipitation_dataset']);
        $this->assertStringContainsString('reanaliz', mb_strtolower($payload['climate']['methodology']['text']));
        $this->assertStringNotContainsString('izmjeren', mb_strtolower(json_encode($payload['climate'], JSON_UNESCAPED_UNICODE)));
        $this->assertStringContainsString('niti prognoza', mb_strtolower($payload['climate']['methodology']['text']));

        $tmax = collect($payload['climate']['extremes'])->firstWhere('metric_code', 'daily_tmax_max');
        $this->assertSame('gridded_reanalysis_extreme', $tmax['kind']);
        $this->assertStringContainsString('analiziranom ERA5-Land nizu', $tmax['wording']);
        $this->assertSame('2007-07-24', $tmax['occurred_on']);
        $this->assertSame('Copernicus Climate Change Service — ERA5-Land', $payload['climate']['source']['name']);
        $this->assertStringContainsString('reanaliza', mb_strtolower((string) $payload['climate']['source']['methodology_note']));

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('authority_rank', $encoded);
        $this->assertSame(1366, $payload['population']['population']);
    }

    public function test_missing_climate_is_omitted_and_does_not_break_existing_blocks(): void
    {
        $payload = $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->json('settlement');

        $this->assertArrayNotHasKey('climate', $payload);
        $this->assertSame('Miloševac', $payload['name']);
        $this->assertSame(1366, $payload['population']['population']);
        $this->assertSame(2013, $payload['census'][0]['census_year']);
    }

    public function test_presenter_keeps_zero_and_reanalysis_semantics(): void
    {
        $profile = SettlementClimateProfile::factory()->create([
            'settlement_id' => $this->settlement('modrica', 'milosevac')->id,
            'source_id' => DataSource::query()->where('source_type', 'copernicus_era5_land')->value('id'),
            'boundary_id' => null,
            'temperature_mean_annual_c' => 0,
            'precipitation_annual_mm' => 0,
        ]);
        SettlementClimateMonth::query()->create([
            'climate_profile_id' => $profile->id,
            'month' => 1,
            'temperature_mean_c' => 0,
            'temperature_mean_min_c' => -5,
            'temperature_mean_max_c' => 4,
            'precipitation_mean_mm' => 0,
        ]);
        SettlementClimateExtreme::query()->create([
            'climate_profile_id' => $profile->id,
            'metric_code' => 'daily_tmax_max',
            'value' => 38.1,
            'unit' => '°C',
            'occurred_on' => '2012-08-07',
            'period_start' => '1950-01-01',
            'period_end' => '2025-12-31',
            'metadata' => [
                'kind' => 'gridded_reanalysis_extreme',
                'wording' => 'Najviša dnevna maksimalna temperatura u analiziranom ERA5-Land nizu',
            ],
        ]);
        $profile->load(['monthly', 'extremes', 'source']);

        $presented = (new ClimatePresenter)->present($profile);
        $this->assertEqualsWithDelta(0.0, $presented['annual']['temperature_mean_c'], 0.001);
        $this->assertEqualsWithDelta(0.0, $presented['annual']['precipitation_mm'], 0.001);
        $this->assertSame('gridded_reanalysis_extreme', $presented['extremes'][0]['kind']);
        $this->assertStringNotContainsString('izmjeren', mb_strtolower($presented['extremes'][0]['wording']));
    }

    private function preparedFixture(): string
    {
        $raw = json_decode((string) file_get_contents(base_path('tests/fixtures/atlas/climate-mini.json')), true);
        $raw['records'][0]['settlement_id'] = $this->settlement('modrica', 'milosevac')->id;
        $raw['records'][1]['settlement_id'] = $this->settlement('samac', 'njegosevo')->id;
        $path = storage_path('app/private/atlas/climate/climate-mini.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function milosevacProfile(): SettlementClimateProfile
    {
        return SettlementClimateProfile::query()
            ->where('settlement_id', $this->settlement('modrica', 'milosevac')->id)
            ->where('algorithm_version', 'm7.1')
            ->with(['monthly', 'extremes', 'source', 'settlement'])
            ->firstOrFail();
    }

    private function settlement(string $municipalitySlug, string $settlementSlug): GeoSettlement
    {
        return GeoSettlement::query()
            ->where('slug', $settlementSlug)
            ->whereHas('municipality', fn ($query) => $query->where('slug', $municipalitySlug))
            ->firstOrFail();
    }
}
