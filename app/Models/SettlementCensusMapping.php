<?php

namespace App\Models;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingMethod;
use App\Enums\CensusMappingStatus;
use App\Enums\VerificationStatus;
use Database\Factories\SettlementCensusMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementCensusMapping extends Model
{
    /** @use HasFactory<SettlementCensusMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'source_id',
        'census_year',
        'historical_settlement_name',
        'historical_settlement_code',
        'historical_municipality_name',
        'historical_municipality_code',
        'mapping_status',
        'mapping_method',
        'comparability',
        'verification_status',
        'notes',
        'source_properties',
    ];

    protected function casts(): array
    {
        return [
            'census_year' => 'integer',
            'mapping_status' => CensusMappingStatus::class,
            'mapping_method' => CensusMappingMethod::class,
            'comparability' => CensusComparability::class,
            'verification_status' => VerificationStatus::class,
            'source_properties' => 'array',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GeoSettlement::class, 'settlement_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}
