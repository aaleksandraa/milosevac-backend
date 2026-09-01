<?php

namespace App\Support\Atlas;

use App\Models\GeoSettlement;

final class SettlementIndexability
{
    /**
     * M3 readiness: sourced identity, municipality, and at least one census (or other factual) population row.
     * Population 0 is valid sourced data and counts as ready.
     */
    public static function isReady(GeoSettlement $settlement): bool
    {
        if ($settlement->municipality_id === null || trim($settlement->name) === '' || trim($settlement->slug) === '') {
            return false;
        }

        if (! filled($settlement->statistical_code) && ! filled($settlement->official_code)) {
            return false;
        }

        $records = $settlement->relationLoaded('populationRecords')
            ? $settlement->populationRecords
            : $settlement->populationRecords()->get();

        return $records->contains(fn ($row) => $row->population !== null);
    }
}
