<?php

namespace App\Enums;

enum GeoMunicipalityAdminType: string
{
    case Municipality = 'municipality';
    case City = 'city';
    case District = 'district';

    public function publicLabel(): string
    {
        return match ($this) {
            self::Municipality => 'Opština',
            self::City => 'Grad',
            self::District => 'Distrikt',
        };
    }
}
