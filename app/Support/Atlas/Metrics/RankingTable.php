<?php

namespace App\Support\Atlas\Metrics;

use App\Enums\RankingDirection;
use App\Enums\RankingScope;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use Illuminate\Support\Collection;

final class RankingTable
{
    /**
     * @param  Collection<int, RankedEntry>  $entries
     * @param  Collection<int, GeoSettlement>  $universe
     */
    public function __construct(
        public MetricDefinition $metric,
        public RankingScope $scope,
        public RankingDirection $direction,
        public Collection $entries,
        public Collection $universe,
        public ?GeoMunicipality $municipality,
        public int $eligible,
        public int $missing,
        public int|float|null $min,
        public int|float|null $max,
        public int $tieGroups,
        public string $datasetVersion,
    ) {}

    public function forSettlement(int $settlementId): ?RankedEntry
    {
        return $this->entries->first(fn (RankedEntry $entry) => $entry->settlement->id === $settlementId);
    }
}
