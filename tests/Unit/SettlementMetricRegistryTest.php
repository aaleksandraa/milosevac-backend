<?php

namespace Tests\Unit;

use App\Support\Atlas\Metrics\SettlementMetricRegistry;
use Tests\TestCase;

class SettlementMetricRegistryTest extends TestCase
{
    public function test_known_metric_lookup_and_unknown_rejected(): void
    {
        $registry = new SettlementMetricRegistry;
        $population = $registry->get('population_2013');

        $this->assertSame('population_2013', $population->code);
        $this->assertTrue($population->rankable);
        $this->assertTrue($population->zeroIsValid);
        $this->assertSame('competition', $population->rankingMethod());
        $this->assertNull($registry->find('livability_score'));
        $this->expectException(\InvalidArgumentException::class);
        $registry->get('quality_of_life');
    }

    public function test_population_change_is_rankable_and_1991_is_comparison_only(): void
    {
        $registry = new SettlementMetricRegistry;

        $this->assertTrue($registry->get('population_change_1991_2013_pct')->rankable);
        $this->assertFalse($registry->get('population_1991')->rankable);
        $this->assertFalse($registry->get('population_change_1991_2013_abs')->rankable);
        $this->assertContains('poi_count_sport', array_column($registry->catalog(), 'code'));
    }
}
