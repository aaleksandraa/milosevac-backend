<?php

namespace Tests\Unit;

use App\Support\Atlas\LandCover\ClcNomenclature;
use App\Support\Atlas\Metrics\SettlementMetricRegistry;
use Tests\TestCase;

class ClcNomenclatureTest extends TestCase
{
    public function test_clc_codes_map_to_atlas_groups(): void
    {
        $this->assertSame('artificial_surfaces', ClcNomenclature::atlasGroup('112'));
        $this->assertSame('agricultural', ClcNomenclature::atlasGroup('211'));
        $this->assertSame('forest', ClcNomenclature::atlasGroup('311'));
        $this->assertSame('shrub_herbaceous_or_seminatural', ClcNomenclature::atlasGroup('324'));
        $this->assertSame('open_or_sparse', ClcNomenclature::atlasGroup('331'));
        $this->assertSame('wetlands', ClcNomenclature::atlasGroup('411'));
        $this->assertSame('water', ClcNomenclature::atlasGroup('511'));
        $this->assertNull(ClcNomenclature::atlasGroup('999'));
    }

    public function test_share_sum_tolerance_does_not_normalize(): void
    {
        $this->assertTrue(ClcNomenclature::sharesSumOk(100.0));
        $this->assertTrue(ClcNomenclature::sharesSumOk(99.2));
        $this->assertFalse(ClcNomenclature::sharesSumOk(96.0));
    }

    public function test_landcover_metrics_are_registered(): void
    {
        $registry = new SettlementMetricRegistry;
        $metric = $registry->get('landcover_agricultural_pct');
        $this->assertTrue($metric->rankable);
        $this->assertTrue($metric->zeroIsValid);
        $this->assertTrue($metric->percentagePointDifference);
        $this->assertFalse($metric->percentageDifferenceUseful);
        $this->assertSame('land_cover', $metric->category->value);
        $this->assertSame('Najveći udio poljoprivrednih površina', $metric->highRankingLabel);
        $this->assertStringNotContainsString('najzelenije', mb_strtolower($metric->highRankingLabel));
        $this->assertNotNull($registry->find('tree_cover_mean_pct'));
        $this->assertNotNull($registry->find('imperviousness_mean_pct'));
    }
}
