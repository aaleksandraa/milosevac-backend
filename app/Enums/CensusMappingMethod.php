<?php

namespace App\Enums;

enum CensusMappingMethod: string
{
    case CheckedInMapping = 'checked_in_mapping';
    case OfficialIdentifier = 'official_identifier';
    case ExactNameContext = 'exact_name_context';
    case DeterministicAlias = 'deterministic_alias';
}
