<?php

namespace App\Enums;

enum GeoEntityKind: string
{
    case Federation = 'federation';
    case Republic = 'republic';
    case District = 'district';
}
