<?php

namespace App\Enums;

enum GeoPlaceClassificationStatus: string
{
    case ExactOsmMapping = 'exact_osm_mapping';
    case InferredOsmMapping = 'inferred_osm_mapping';
    case ManuallyVerified = 'manually_verified';
    case Unresolved = 'unresolved';
}
