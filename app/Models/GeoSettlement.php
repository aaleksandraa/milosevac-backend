<?php

namespace App\Models;

use App\Enums\VerificationStatus;
use Database\Factories\GeoSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoSettlement extends Model
{
    /** @use HasFactory<GeoSettlementFactory> */
    use HasFactory;

    protected $fillable = [
        'municipality_id',
        'name',
        'slug',
        'name_cyrillic',
        'official_code',
        'statistical_code',
        'osm_relation_id',
        'wikidata_id',
        'geonames_id',
        'outage_place_id',
        'geographic_centroid_lat',
        'geographic_centroid_lng',
        'label_point_lat',
        'label_point_lng',
        'label_point_source_id',
        'label_point_verification_status',
    ];

    protected function casts(): array
    {
        return [
            'osm_relation_id' => 'integer',
            'geonames_id' => 'integer',
            'geographic_centroid_lat' => 'decimal:7',
            'geographic_centroid_lng' => 'decimal:7',
            'label_point_lat' => 'decimal:7',
            'label_point_lng' => 'decimal:7',
            'label_point_verification_status' => VerificationStatus::class,
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(GeoMunicipality::class, 'municipality_id');
    }

    public function populationRecords(): HasMany
    {
        return $this->hasMany(SettlementPopulation::class, 'settlement_id');
    }

    public function outagePlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'outage_place_id');
    }

    public function labelPointSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'label_point_source_id');
    }

    public function boundaries(): HasMany
    {
        return $this->hasMany(SettlementBoundary::class, 'settlement_id');
    }

    public function terrainStats(): HasMany
    {
        return $this->hasMany(SettlementTerrainStat::class, 'settlement_id');
    }

    public function places(): HasMany
    {
        return $this->hasMany(GeoPlace::class, 'settlement_id');
    }

    public function climateProfiles(): HasMany
    {
        return $this->hasMany(SettlementClimateProfile::class, 'settlement_id');
    }

    public function surfaceProfiles(): HasMany
    {
        return $this->hasMany(SettlementSurfaceProfile::class, 'settlement_id');
    }

    public function censusMappings(): HasMany
    {
        return $this->hasMany(SettlementCensusMapping::class, 'settlement_id');
    }

    public function transportProfiles(): HasMany
    {
        return $this->hasMany(SettlementTransportProfile::class, 'settlement_id');
    }

    public function hydroProfiles(): HasMany
    {
        return $this->hasMany(SettlementHydroProfile::class, 'settlement_id');
    }

    public function proximities(): HasMany
    {
        return $this->hasMany(SettlementProximity::class, 'settlement_id');
    }
}
