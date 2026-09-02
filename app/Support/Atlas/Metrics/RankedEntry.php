<?php

namespace App\Support\Atlas\Metrics;

use App\Models\GeoSettlement;

final class RankedEntry
{
    public function __construct(
        public GeoSettlement $settlement,
        public int|float $value,
        public int $rank,
        public int $eligible,
        public int $percentileStrictlyAbove,
        public int $percentileStrictlyBelow,
        public int $tieCount,
    ) {}
}
