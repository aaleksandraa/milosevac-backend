<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Database\Factories\SettlementBoundaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementBoundary extends Model
{
    /** @use HasFactory<SettlementBoundaryFactory> */
    use HasFactory;

    protected $fillable = [
        'settlement_id',
        'source_id',
        'geom_geojson',
        'source_feature_id',
        'boundary_version',
        'valid_from',
        'valid_to',
        'source_properties',
        'bbox_min_lng',
        'bbox_min_lat',
        'bbox_max_lng',
        'bbox_max_lat',
        'geometry_valid',
        'verification_status',
        'area_km2',
        'area_algorithm',
        'area_calculated_at',
        'geometry_fingerprint',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'source_properties' => 'array',
            'bbox_min_lng' => 'decimal:7',
            'bbox_min_lat' => 'decimal:7',
            'bbox_max_lng' => 'decimal:7',
            'bbox_max_lat' => 'decimal:7',
            'geometry_valid' => 'boolean',
            'verification_status' => VerificationStatus::class,
            'area_km2' => 'decimal:4',
            'area_calculated_at' => 'datetime',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function geometry(): ?array
    {
        $decoded = json_decode((string) $this->geom_geojson, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GeoSettlement::class, 'settlement_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }

    public function terrainStats(): HasMany
    {
        return $this->hasMany(SettlementTerrainStat::class, 'boundary_id');
    }
}
