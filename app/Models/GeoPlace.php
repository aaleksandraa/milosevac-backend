<?php

namespace App\Models;

use App\Enums\GeoPlaceAssignmentStatus;
use App\Enums\GeoPlaceClassificationStatus;
use App\Enums\VerificationStatus;
use Database\Factories\GeoPlaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoPlace extends Model
{
    /** @use HasFactory<GeoPlaceFactory> */
    use HasFactory;

    protected $fillable = [
        'place_type_id',
        'settlement_id',
        'municipality_id',
        'source_id',
        'name',
        'slug',
        'name_cyrillic',
        'lat',
        'lng',
        'osm_type',
        'osm_id',
        'osm_version',
        'osm_timestamp',
        'last_synced_at',
        'operator',
        'address',
        'phone',
        'website',
        'opening_hours',
        'verification_status',
        'classification_status',
        'assignment_status',
        'point_algorithm',
        'source_properties',
        'metadata',
        'is_active',
        'source_deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'osm_id' => 'integer',
            'osm_version' => 'integer',
            'osm_timestamp' => 'datetime',
            'last_synced_at' => 'datetime',
            'verification_status' => VerificationStatus::class,
            'classification_status' => GeoPlaceClassificationStatus::class,
            'assignment_status' => GeoPlaceAssignmentStatus::class,
            'source_properties' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'source_deleted_at' => 'datetime',
        ];
    }

    public function placeType(): BelongsTo
    {
        return $this->belongsTo(GeoPlaceType::class, 'place_type_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GeoSettlement::class, 'settlement_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(GeoMunicipality::class, 'municipality_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }

    public function displayLabel(): string
    {
        if (is_string($this->name) && $this->name !== '') {
            return $this->name;
        }

        return $this->placeType?->singular_label ?: $this->placeType?->name ?: 'Objekat';
    }
}
