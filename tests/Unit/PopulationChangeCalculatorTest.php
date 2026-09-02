<?php

namespace Tests\Unit;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingStatus;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementPopulation;
use App\Support\Atlas\PopulationChangeCalculator;
use PHPUnit\Framework\TestCase;

class PopulationChangeCalculatorTest extends TestCase
{
    public function test_percentage_change_and_zero_baseline(): void
    {
        $calculator = new PopulationChangeCalculator;

        $this->assertSame(-21.3, $calculator->percentageChange(1735, 1366));
        $this->assertSame(0.0, $calculator->percentageChange(100, 100));
        $this->assertNull($calculator->percentageChange(0, 12));
        $this->assertSame(100.0, $calculator->percentageChange(50, 100));
    }

    public function test_series_skips_missing_years_and_non_comparable_pairs(): void
    {
        $history = collect([
            $this->population(2013, 1366),
            $this->population(1991, 1735),
            $this->population(1971, 1880),
        ]);
        $mappings = collect([
            $this->mapping(1971, CensusComparability::Comparable),
            $this->mapping(1991, CensusComparability::Comparable),
        ]);

        $changes = (new PopulationChangeCalculator)->series($history, $mappings);
        $pairs = array_map(fn (array $row) => [$row['from_year'], $row['to_year']], $changes);

        $this->assertSame([[1991, 2013], [1971, 2013]], $pairs);
        $this->assertSame(-369, $changes[0]['absolute_change']);
        $this->assertSame('decline', $changes[0]['trend']);
        $this->assertSame(CensusComparability::Comparable->value, $changes[0]['comparability']);
    }

    public function test_caveat_is_preserved_and_unresolved_is_skipped(): void
    {
        $history = collect([
            $this->population(1991, 100),
            $this->population(2013, 80),
            $this->population(1971, 90),
            $this->population(1981, 95),
        ]);
        $mappings = collect([
            $this->mapping(1971, CensusComparability::Unresolved),
            $this->mapping(1981, CensusComparability::Comparable),
            $this->mapping(1991, CensusComparability::ComparableWithCaveat),
        ]);

        $changes = (new PopulationChangeCalculator)->series($history, $mappings);
        $pairs = array_map(fn (array $row) => [$row['from_year'], $row['to_year']], $changes);
        $this->assertNotContains([1971, 1981], $pairs);
        $this->assertNotContains([1971, 2013], $pairs);
        $change = collect($changes)->first(fn (array $row) => $row['from_year'] === 1991 && $row['to_year'] === 2013);
        $this->assertNotNull($change);
        $this->assertSame(CensusComparability::ComparableWithCaveat->value, $change['comparability']);
    }

    private function population(int $year, int $value): SettlementPopulation
    {
        $row = new SettlementPopulation;
        $row->census_year = $year;
        $row->population = $value;

        return $row;
    }

    private function mapping(int $year, CensusComparability $comparability): SettlementCensusMapping
    {
        $row = new SettlementCensusMapping;
        $row->census_year = $year;
        $row->comparability = $comparability;
        $row->mapping_status = CensusMappingStatus::ExactIdentity;

        return $row;
    }
}
