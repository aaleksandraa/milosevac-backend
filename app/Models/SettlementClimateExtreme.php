<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementClimateExtreme extends Model
{
    protected $fillable = [
        'climate_profile_id',
        'metric_code',
        'value',
        'unit',
        'occurred_on',
        'period_start',
        'period_end',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'occurred_on' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'metadata' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SettlementClimateProfile::class, 'climate_profile_id');
    }
}
