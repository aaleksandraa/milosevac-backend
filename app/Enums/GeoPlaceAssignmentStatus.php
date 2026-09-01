<?php

namespace App\Enums;

enum GeoPlaceAssignmentStatus: string
{
    case BoundaryMatch = 'boundary_match';
    case ExplicitVerified = 'explicit_verified';
    case MunicipalityOnly = 'municipality_only';
    case Ambiguous = 'ambiguous';
    case Unassigned = 'unassigned';
}
