<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case OsmImported = 'osm_imported';
    case ManuallyVerified = 'manually_verified';
    case OfficialSourceVerified = 'official_source_verified';
    case Disputed = 'disputed';

    public function strength(): int
    {
        return match ($this) {
            self::OfficialSourceVerified => 40,
            self::ManuallyVerified => 30,
            self::OsmImported => 20,
            self::Unverified => 10,
            self::Disputed => 0,
        };
    }
}
