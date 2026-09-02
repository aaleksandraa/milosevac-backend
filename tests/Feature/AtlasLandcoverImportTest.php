<?php

namespace Tests\Feature;

use App\Enums\DataImportStatus;
use App\Models\DataImport;
use App\Models\GeoSettlement;
use App\Models\SettlementLandCoverClass;
use App\Models\SettlementSurfaceProfile;
use App\Support\Atlas\Import\LandcoverImportService;
use App\Support\Atlas\LandCover\ClcNomenclature;
use Database\Seeders\GeoAtlasSeeder;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class AtlasLandcoverImportTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
        $this->fixture = $this->preparedFixture();
    }

    public function test_clc_profiles_and_classes_are_imported_with_provenance(): void
    {
        $this->artisan('atlas:import-landcover', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $profile = $this->clcProfile();
        $this->assertSame(ClcNomenclature::ALGORITHM_VERSION, $profile->algorithm_version);
        $this->assertSame('copernicus_clc_2018', $profile->source->source_type);
        $this->assertSame(2018, $profile->reference_year);
        $this->assertSame('clc_vector_intersection_epsg3035', $profile->processing_method);
        $this->assertTrue($profile->isPubliclyUsable());
        $this->assertEqualsWithDelta(70.0, $profile->summaryValue('landcover_agricultural_pct'), 0.05);
        $this->assertEqualsWithDelta(10.0, (float) $profile->valid_area_km2, 0.001);
        $this->assertCount(5, $profile->classes);
        $this->assertEqualsWithDelta(100.0, $profile->classes->sum(fn ($row) => (float) $row->share_pct), 0.05);
        $this->assertSame('agricultural', $profile->classes->firstWhere('class_code', '211')?->atlas_group);
    }

    public function test_class_unique_constraint(): void
    {
        $this->artisan('atlas:import-landcover', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $this->expectException(QueryException::class);
        SettlementLandCoverClass::query()->create([
            'surface_profile_id' => $this->clcProfile()->id,
            'class_code' => '211',
            'source_class_code' => '211',
            'source_class_label' => 'duplikat',
            'atlas_group' => 'agricultural',
            'area_km2' => 1,
            'share_pct' => 10,
        ]);
    }

    public function test_zero_share_is_valid_and_nodata_is_not_a_class(): void
    {
        $this->artisan('atlas:import-landcover', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $codes = $this->clcProfile()->classes->pluck('class_code');
        $this->assertFalse($codes->contains('0'));
        $this->assertEqualsWithDelta(0.0, (float) ($this->clcProfile()->summary['landcover_wetland_pct'] ?? -1), 0.001);
    }

    public function test_import_is_idempotent(): void
    {
        $this->artisan('atlas:import-landcover', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $first = $this->clcProfile();
        $updatedAt = $first->updated_at->toDateTimeString();

        $this->artisan('atlas:import-landcover', [
            '--municipality' => 'modrica',
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $log = DataImport::query()->where('import_type', LandcoverImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertSame(DataImportStatus::Completed, $log->status);
        $this->assertSame(0, $log->records_inserted);
        $this->assertSame(0, $log->records_updated);
        $this->assertSame(3, $log->metadata['unchanged']);
        $this->assertSame('m10.1', $log->metadata['algorithm_version']);
        $this->assertSame($updatedAt, $this->clcProfile()->updated_at->toDateTimeString());
    }

    public function test_invalid_share_sum_is_rejected(): void
    {
        $raw = json_decode((string) file_get_contents($this->fixture), true);
        $raw['records'][0]['profiles'][0]['classes'][0]['share_pct'] = 1.0;
        $path = storage_path('app/private/atlas/landcover/landcover-bad.json');
        file_put_contents($path, json_encode($raw));

        $this->artisan('atlas:import-landcover', [
            '--municipality' => 'modrica',
            '--from-file' => $path,
        ])->assertSuccessful();

        $this->assertSame(0, SettlementSurfaceProfile::query()->where('product_code', 'clc2018')->count());
        $log = DataImport::query()->where('import_type', LandcoverImportService::IMPORT_TYPE)->latest('id')->firstOrFail();
        $this->assertGreaterThan(0, $log->metadata['invalid']);
    }

    public function test_unavailable_record_does_not_insert_zero(): void
    {
        $this->artisan('atlas:import-landcover', [
            '--pilot' => true,
            '--from-file' => $this->fixture,
        ])->assertSuccessful();

        $njegosevo = GeoSettlement::query()
            ->where('slug', 'njegosevo')
            ->whereHas('municipality', fn ($query) => $query->where('slug', 'samac'))
            ->firstOrFail();
        $this->assertSame(0, SettlementSurfaceProfile::query()->where('settlement_id', $njegosevo->id)->count());
    }

    public function test_wrong_atlas_group_is_rejected(): void
    {
        $raw = json_decode((string) file_get_contents($this->fixture), true);
        $raw['records'][0]['profiles'][0]['classes'][0]['atlas_group'] = 'forest';
        $path = storage_path('app/private/atlas/landcover/landcover-wrong-group.json');
        file_put_contents($path, json_encode($raw));

        $this->artisan('atlas:import-landcover', [
            '--municipality' => 'modrica',
            '--from-file' => $path,
        ])->assertSuccessful();

        $this->assertSame(0, SettlementSurfaceProfile::query()->where('product_code', 'clc2018')->count());
    }

    private function preparedFixture(): string
    {
        $raw = json_decode((string) file_get_contents(base_path('tests/fixtures/atlas/landcover-mini.json')), true);
        $raw['records'][0]['settlement_id'] = $this->settlement('modrica', 'milosevac')->id;
        $raw['records'][1]['settlement_id'] = $this->settlement('samac', 'njegosevo')->id;
        $path = storage_path('app/private/atlas/landcover/landcover-mini.json');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function clcProfile(): SettlementSurfaceProfile
    {
        return SettlementSurfaceProfile::query()
            ->where('settlement_id', $this->settlement('modrica', 'milosevac')->id)
            ->where('product_code', ClcNomenclature::PRODUCT_CLC)
            ->with(['classes', 'source', 'settlement'])
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
