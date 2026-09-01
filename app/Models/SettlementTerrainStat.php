<?php

namespace App\Models;

use Database\Factories\SettlementTerrainStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementTerrainStat extends Model
{
    /** @use HasFactory<SettlementTerrainStatFactory> */
    use HasFactory;

    public const QUALITY_OK = 'ok';

    public const QUALITY_LOW_SAMPLE = 'low_sample';

    public const QUALITY_HIGH_NODATA = 'high_nodata';

    public const QUALITY_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'settlement_id',
        'boundary_id',
        'source_id',
        'elevation_min_m',
        'elevation_max_m',
        'elevation_mean_m',
        'elevation_median_m',
        'elevation_range_m',
        'slope_mean_deg',
        'slope_median_deg',
        'slope_max_deg',
        'elevation_min_lat',
        'elevation_min_lng',
        'elevation_max_lat',
        'elevation_max_lng',
        'dem_source',
        'dem_version',
        'dem_resolution_m',
        'sample_count',
        'valid_cell_count',
        'nodata_cell_count',
        'quality_flag',
        'algorithm_version',
        'calculated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'elevation_min_m' => 'decimal:1',
            'elevation_max_m' => 'decimal:1',
            'elevation_mean_m' => 'decimal:1',
            'elevation_median_m' => 'decimal:1',
            'elevation_range_m' => 'decimal:1',
            'slope_mean_deg' => 'decimal:2',
            'slope_median_deg' => 'decimal:2',
            'slope_max_deg' => 'decimal:2',
            'elevation_min_lat' => 'decimal:7',
            'elevation_min_lng' => 'decimal:7',
            'elevation_max_lat' => 'decimal:7',
            'elevation_max_lng' => 'decimal:7',
            'dem_resolution_m' => 'integer',
            'sample_count' => 'integer',
            'valid_cell_count' => 'integer',
            'nodata_cell_count' => 'integer',
            'calculated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isPubliclyUsable(): bool
    {
        return $this->quality_flag !== self::QUALITY_UNAVAILABLE
            && $this->valid_cell_count > 0
            && $this->elevation_min_m !== null
            && $this->elevation_max_m !== null;
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GeoSettlement::class, 'settlement_id');
    }

    public function boundary(): BelongsTo
    {
        return $this->belongsTo(SettlementBoundary::class, 'boundary_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}
