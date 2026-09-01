<?php

namespace App\Enums;

enum DataSourceClass: string
{
    case Official = 'A';
    case ReputablePublic = 'B';
    case CommunityGeographic = 'C';
    case ManualResearch = 'D';
    case Derived = 'E';

    public function publicLabel(): string
    {
        return match ($this) {
            self::Official => 'Službeni izvor',
            self::ReputablePublic => 'Javni naučni ili institucionalni skup',
            self::CommunityGeographic => 'Zajednički geografski izvor',
            self::ManualResearch => 'Ručno istraženo',
            self::Derived => 'Izvedeno',
        };
    }
}
