<?php

namespace App\Support\Atlas;

use App\Models\SettlementPopulation;
use Illuminate\Support\Collection;

final class PopulationSelector
{
    public function __construct(private SourcePriorityComparator $comparator = new SourcePriorityComparator) {}

    /**
     * @param  Collection<int, SettlementPopulation>  $records
     */
    public function preferredForYear(Collection $records, int $year): ?SettlementPopulation
    {
        $yearRecords = $records->where('census_year', $year)->values();
        if ($yearRecords->isEmpty()) {
            return null;
        }

        return $yearRecords->sort(function (SettlementPopulation $left, SettlementPopulation $right) {
            $leftSource = $left->source;
            $rightSource = $right->source;
            if ($leftSource === null || $rightSource === null) {
                return ($rightSource === null) <=> ($leftSource === null);
            }

            $rank = $this->comparator->compare($leftSource, $rightSource);
            if ($rank !== 0) {
                return $rank;
            }

            return $left->id <=> $right->id;
        })->first();
    }

    /**
     * One winning row per census year, newest year first.
     *
     * @param  Collection<int, SettlementPopulation>  $records
     * @return Collection<int, SettlementPopulation>
     */
    public function history(Collection $records): Collection
    {
        return $records
            ->pluck('census_year')
            ->unique()
            ->sortDesc()
            ->map(fn ($year) => $this->preferredForYear($records, (int) $year))
            ->filter()
            ->values();
    }
}
