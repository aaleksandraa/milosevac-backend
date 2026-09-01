<?php

namespace App\Support\Atlas;

/**
 * Seed defaults for data_sources.authority_rank.
 * Persist the rank on each source row; do not substitute these constants
 * for the stored value in queries or controllers.
 */
final class SourceAuthority
{
    public const OFFICIAL_STATE = 100;

    public const OFFICIAL_INTERNATIONAL = 90;

    public const SCIENTIFIC_PUBLIC = 80;

    public const LOCAL_INSTITUTION = 60;

    public const OPENSTREETMAP = 50;

    public const SECONDARY = 40;

    public const UNVERIFIED_MANUAL = 20;
}
