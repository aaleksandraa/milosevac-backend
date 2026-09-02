<?php

namespace App\Support\Atlas;

use App\Enums\CensusComparability;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementPopulation;
use Illuminate\Support\Collection;

final class PopulationChangeCalculator
{
    /** @var list<array{0: int, 1: int}> */
    public const PAIRS = [
        [1971, 1981],
        [1981, 1991],
        [1991, 2013],
        [1971, 2013],
    ];

    /**
     * @param  Collection<int, SettlementPopulation>  $history
     * @param  Collection<int, SettlementCensusMapping>  $mappings
     * @return list<array<string, mixed>>
     */
    public function series(Collection $history, Collection $mappings): array
    {
        $byYear = $history->keyBy(fn (SettlementPopulation $row) => (int) $row->census_year);
        $changes = [];

        foreach (self::PAIRS as [$fromYear, $toYear]) {
            $earlier = $byYear->get($fromYear);
            $later = $byYear->get($toYear);
            if (! $earlier instanceof SettlementPopulation || ! $later instanceof SettlementPopulation) {
                continue;
            }

            $comparability = $this->pairComparability($fromYear, $toYear, $mappings);
            if ($comparability === null || ! $comparability->mayComputeChange()) {
                continue;
            }

            $absolute = (int) $later->population - (int) $earlier->population;
            $percentage = $this->percentageChange((int) $earlier->population, (int) $later->population);

            $changes[] = [
                'from_year' => $fromYear,
                'to_year' => $toYear,
                'from_population' => (int) $earlier->population,
                'to_population' => (int) $later->population,
                'absolute_change' => $absolute,
                'percentage_change' => $percentage,
                'trend' => $this->trend($absolute),
                'comparability' => $comparability->value,
            ];
        }

        return $changes;
    }

    public function percentageChange(int $earlier, int $later): ?float
    {
        if ($earlier === 0) {
            return null;
        }

        return round((($later - $earlier) / $earlier) * 100, 1);
    }

    /**
     * @param  Collection<int, SettlementCensusMapping>  $mappings
     */
    public function comparabilityForYear(int $year, Collection $mappings): CensusComparability
    {
        if ($year === 2013) {
            return CensusComparability::Comparable;
        }

        $mapping = $mappings->first(fn (SettlementCensusMapping $row) => (int) $row->census_year === $year);

        return $mapping?->comparability ?? CensusComparability::Unresolved;
    }

    /**
     * @param  Collection<int, SettlementCensusMapping>  $mappings
     */
    private function pairComparability(int $fromYear, int $toYear, Collection $mappings): ?CensusComparability
    {
        $from = $this->comparabilityForYear($fromYear, $mappings);
        $to = $this->comparabilityForYear($toYear, $mappings);

        if (! $from->mayComputeChange() || ! $to->mayComputeChange()) {
            return null;
        }

        if ($from === CensusComparability::ComparableWithCaveat || $to === CensusComparability::ComparableWithCaveat) {
            return CensusComparability::ComparableWithCaveat;
        }

        return CensusComparability::Comparable;
    }

    private function trend(int $absolute): string
    {
        if ($absolute > 0) {
            return 'growth';
        }
        if ($absolute < 0) {
            return 'decline';
        }

        return 'unchanged';
    }
}
