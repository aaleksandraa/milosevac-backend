<?php

namespace App\Enums;

enum GeoMunicipalityAdminType: string
{
    case Municipality = 'municipality';
    case City = 'city';
    case District = 'district';
}
