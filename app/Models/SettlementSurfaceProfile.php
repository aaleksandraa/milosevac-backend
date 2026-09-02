<?php

namespace App\Models;

use Database\Factories\SettlementSurfaceProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementSurfaceProfile extends Model
{
    /** @use HasFactory<SettlementSurfaceProfileFactory> */
    use HasFactory;

    public const QUALITY_OK = 'ok';

    public const QUALITY_HIGH_NODATA = 'high_nodata';

    public const QUALITY_INSUFFICIENT = 'insufficient_coverage';

    public const QUALITY_INCONSISTENT = 'inconsistent_totals';

    public const QUALITY_UNSUPPORTED = 'unsupported_source';

    public const QUALITY_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'settlement_id',
        'source_id',
        'boundary_id',
        'product_code',
        'reference_year',
        'algorithm_version',
        'processing_method',
        'source_resolution_m',
        'total_analysis_area_km2',
        'valid_area_km2',
        'nodata_area_km2',
        'quality_flag',
        'summary',
        'metadata',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_year' => 'integer',
            'source_resolution_m' => 'integer',
            'total_analysis_area_km2' => 'decimal:4',
            'valid_area_km2' => 'decimal:4',
            'nodata_area_km2' => 'decimal:4',
            'summary' => 'array',
            'metadata' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    public function isPubliclyUsable(): bool
    {
        return in_array($this->quality_flag, [self::QUALITY_OK, self::QUALITY_HIGH_NODATA], true)
            && is_array($this->summary)
            && $this->summary !== [];
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

    public function classes(): HasMany
    {
        return $this->hasMany(SettlementLandCoverClass::class, 'surface_profile_id');
    }

    public function summaryValue(string $key): ?float
    {
        $summary = $this->summary ?? [];
        if (! array_key_exists($key, $summary) || $summary[$key] === null || $summary[$key] === '') {
            return null;
        }

        return (float) $summary[$key];
    }
}
