<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementClimateMonth extends Model
{
    protected $table = 'settlement_climate_monthly';

    protected $fillable = [
        'climate_profile_id',
        'month',
        'temperature_mean_c',
        'temperature_mean_min_c',
        'temperature_mean_max_c',
        'precipitation_mean_mm',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'temperature_mean_c' => 'decimal:2',
            'temperature_mean_min_c' => 'decimal:2',
            'temperature_mean_max_c' => 'decimal:2',
            'precipitation_mean_mm' => 'decimal:1',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SettlementClimateProfile::class, 'climate_profile_id');
    }
}
