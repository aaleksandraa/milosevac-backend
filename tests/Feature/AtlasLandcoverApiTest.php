<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Models\SettlementLandCoverClass;
use App\Models\SettlementSurfaceProfile;
use App\Support\Atlas\LandCover\ClcNomenclature;
use Database\Seeders\GeoAtlasSeeder;
use Tests\TestCase;

class AtlasLandcoverApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GeoAtlasSeeder::class);
        $this->artisan('atlas:import-settlements', ['--pilot' => true])->assertSuccessful();
    }

    public function test_settlement_land_cover_block_and_omission(): void
    {
        $this->profile($this->settlement('modrica', 'milosevac'));

        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->assertJsonPath('settlement.land_cover.reference_year', 2018)
            ->assertJsonPath('settlement.land_cover.summary.agricultural_pct', 70)
            ->assertJsonPath('settlement.land_cover.summary.forest_pct', 20)
            ->assertJsonPath('settlement.land_cover.forest.tree_cover_mean_pct', 18.4)
            ->assertJsonPath('settlement.land_cover.imperviousness.imperviousness_mean_pct', 2.1)
            ->assertJsonPath('settlement.land_cover.methodology.algorithm_version', 'm10.1');

        $this->getJson('/api/geo/municipalities/samac/settlements/njegosevo')
            ->assertOk()
            ->assertJsonMissingPath('settlement.land_cover');
    }

    public function test_zero_percent_is_not_treated_as_missing(): void
    {
        $settlement = $this->settlement('modrica', 'milosevac');
        $this->profile($settlement, agricultural: 0.0, forest: 90.0, artificial: 10.0);

        $this->getJson('/api/geo/municipalities/modrica/settlements/milosevac')
            ->assertOk()
            ->assertJsonPath('settlement.land_cover.summary.agricultural_pct', 0);
    }

    public function test_map_exposes_compact_metrics_not_class_lists(): void
    {
        $settlement = $this->settlement('modrica', 'milosevac');
        SettlementBoundary::factory()->create([
            'settlement_id' => $settlement->id,
            'geometry_valid' => true,
        ]);
        $this->profile($settlement);

        $feature = collect($this->getJson('/api/geo/map/settlements?municipality=modrica')->assertOk()->json('features'))
            ->firstWhere('properties.settlement_slug', 'milosevac');

        $this->assertNotNull($feature);
        $this->assertSame(70, $feature['properties']['landcover_agricultural_pct']);
        $this->assertSame(20, $feature['properties']['landcover_forest_pct']);
        $this->assertSame(18.4, $feature['properties']['tree_cover_mean_pct']);
        $this->assertArrayNotHasKey('classes', $feature['properties']);
        $this->assertStringContainsString('2018', $this->getJson('/api/geo/map/settlements?municipality=modrica')->json('meta.landcover_note'));
    }

    public function test_ranking_excludes_settlements_without_profile(): void
    {
        $this->profile($this->settlement('modrica', 'milosevac'), agricultural: 80.0, forest: 10.0, artificial: 10.0);
        $this->profile($this->settlement('modrica', 'garevac'), agricultural: 40.0, forest: 50.0, artificial: 10.0);

        $this->getJson('/api/geo/rankings?metric=landcover_agricultural_pct&scope=pilot&limit=all')
            ->assertOk()
            ->assertJsonPath('eligible', 2)
            ->assertJsonPath('entries.0.settlement.slug', 'milosevac')
            ->assertJsonPath('entries.0.value', 80)
            ->assertJsonPath('metric.percentage_point_difference', true);

        $slugs = collect($this->getJson('/api/geo/rankings?metric=landcover_agricultural_pct&scope=pilot&limit=all')->json('entries'))
            ->pluck('settlement.slug');
        $this->assertFalse($slugs->contains('njegosevo'));
    }

    public function test_compare_uses_percentage_points_not_relative_percent(): void
    {
        $this->profile($this->settlement('modrica', 'milosevac'), agricultural: 60.0, forest: 20.0, artificial: 20.0);
        $this->profile($this->settlement('samac', 'samac'), agricultural: 40.0, forest: 30.0, artificial: 30.0);

        $row = collect($this->getJson('/api/geo/compare/modrica/milosevac/samac/samac')->assertOk()->json('sections'))
            ->firstWhere('code', 'land_cover');
        $this->assertNotNull($row);
        $this->assertSame('Pokrov zemljišta', $row['label']);
        $agri = collect($row['rows'])->firstWhere('metric', 'landcover_agricultural_pct');
        $this->assertSame(60, $agri['a']['value']);
        $this->assertSame(40, $agri['b']['value']);
        $this->assertEqualsWithDelta(20.0, $agri['difference']['percentage_points'], 0.05);
        $this->assertNull($agri['difference']['percentage']);
    }

    public function test_metrics_catalog_includes_landcover(): void
    {
        $this->getJson('/api/geo/metrics')
            ->assertOk()
            ->assertJsonFragment(['code' => 'landcover_agricultural_pct', 'category' => 'land_cover'])
            ->assertJsonFragment(['code' => 'tree_cover_mean_pct']);
    }

    private function profile(
        GeoSettlement $settlement,
        float $agricultural = 70.0,
        float $forest = 20.0,
        float $artificial = 4.0,
        float $water = 3.0,
        float $seminatural = 3.0,
    ): void {
        $sourceId = DataSource::query()->value('id');
        $clc = SettlementSurfaceProfile::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => $sourceId,
            'boundary_id' => null,
            'product_code' => ClcNomenclature::PRODUCT_CLC,
            'summary' => [
                'landcover_artificial_pct' => $artificial,
                'landcover_agricultural_pct' => $agricultural,
                'landcover_forest_pct' => $forest,
                'landcover_seminatural_pct' => $seminatural,
                'landcover_open_or_sparse_pct' => 0.0,
                'landcover_wetland_pct' => 0.0,
                'landcover_water_pct' => $water,
                'dominant_group' => [
                    'code' => $agricultural >= $forest ? 'agricultural' : 'forest',
                    'label' => $agricultural >= $forest ? 'Poljoprivredne površine' : 'Šumske površine',
                    'share_pct' => max($agricultural, $forest),
                ],
            ],
        ]);
        SettlementLandCoverClass::factory()->create([
            'surface_profile_id' => $clc->id,
            'class_code' => '211',
            'atlas_group' => 'agricultural',
            'area_km2' => 7,
            'share_pct' => $agricultural,
        ]);
        SettlementSurfaceProfile::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => $sourceId,
            'boundary_id' => null,
            'product_code' => ClcNomenclature::PRODUCT_TCD,
            'processing_method' => 'hrl_fractional_pixel_area_epsg3035',
            'source_resolution_m' => 10,
            'summary' => ['tree_cover_mean_pct' => 18.4],
        ]);
        SettlementSurfaceProfile::factory()->create([
            'settlement_id' => $settlement->id,
            'source_id' => $sourceId,
            'boundary_id' => null,
            'product_code' => ClcNomenclature::PRODUCT_IMD,
            'processing_method' => 'hrl_fractional_pixel_area_epsg3035',
            'source_resolution_m' => 10,
            'summary' => ['imperviousness_mean_pct' => 2.1],
        ]);
    }

    private function settlement(string $municipalitySlug, string $settlementSlug): GeoSettlement
    {
        return GeoSettlement::query()
            ->where('slug', $settlementSlug)
            ->whereHas('municipality', fn ($query) => $query->where('slug', $municipalitySlug))
            ->firstOrFail();
    }
}
