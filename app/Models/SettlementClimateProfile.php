<?php

namespace App\Models;

use Database\Factories\SettlementClimateProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementClimateProfile extends Model
{
    /** @use HasFactory<SettlementClimateProfileFactory> */
    use HasFactory;

    public const QUALITY_OK = 'ok';

    public const QUALITY_COARSE = 'coarse_spatial_resolution';

    public const QUALITY_REPRESENTATIVE_CELL = 'representative_cell_only';

    public const QUALITY_NO_BOUNDARY = 'no_boundary';

    public const QUALITY_INSUFFICIENT = 'insufficient_source';

    public const QUALITY_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'settlement_id',
        'source_id',
        'boundary_id',
        'normal_period_start',
        'normal_period_end',
        'historical_period_start',
        'historical_period_end',
        'spatial_method',
        'source_resolution_km',
        'grid_cell_count',
        'temperature_mean_annual_c',
        'precipitation_annual_mm',
        'coldest_month',
        'warmest_month',
        'wettest_month',
        'driest_month',
        'temperature_range_annual_c',
        'temperature_mean_1961_1990_c',
        'temperature_mean_change_c',
        'indices',
        'algorithm_version',
        'quality_flag',
        'calculated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'normal_period_start' => 'date',
            'normal_period_end' => 'date',
            'historical_period_start' => 'date',
            'historical_period_end' => 'date',
            'source_resolution_km' => 'decimal:1',
            'grid_cell_count' => 'integer',
            'temperature_mean_annual_c' => 'decimal:2',
            'precipitation_annual_mm' => 'decimal:1',
            'coldest_month' => 'integer',
            'warmest_month' => 'integer',
            'wettest_month' => 'integer',
            'driest_month' => 'integer',
            'temperature_range_annual_c' => 'decimal:2',
            'temperature_mean_1961_1990_c' => 'decimal:2',
            'temperature_mean_change_c' => 'decimal:2',
            'indices' => 'array',
            'calculated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isPubliclyUsable(): bool
    {
        return ! in_array($this->quality_flag, [self::QUALITY_UNAVAILABLE, self::QUALITY_INSUFFICIENT, self::QUALITY_NO_BOUNDARY], true)
            && $this->temperature_mean_annual_c !== null
            && $this->precipitation_annual_mm !== null
            && $this->grid_cell_count > 0;
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GeoSettlement::class, 'settlement_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }

    public function boundary(): BelongsTo
    {
        return $this->belongsTo(SettlementBoundary::class, 'boundary_id');
    }

    public function monthly(): HasMany
    {
        return $this->hasMany(SettlementClimateMonth::class, 'climate_profile_id');
    }

    public function extremes(): HasMany
    {
        return $this->hasMany(SettlementClimateExtreme::class, 'climate_profile_id');
    }
}
