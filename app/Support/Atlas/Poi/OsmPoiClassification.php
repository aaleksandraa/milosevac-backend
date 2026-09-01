<?php

namespace App\Support\Atlas\Poi;

use App\Enums\GeoPlaceClassificationStatus;

final class OsmPoiClassification
{
    public function __construct(
        public string $typeCode,
        public GeoPlaceClassificationStatus $status,
        public int $ruleIndex,
    ) {}
}
