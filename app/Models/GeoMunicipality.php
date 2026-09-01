<?php

namespace App\Models;

use App\Enums\GeoMunicipalityAdminType;
use Database\Factories\GeoMunicipalityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoMunicipality extends Model
{
    /** @use HasFactory<GeoMunicipalityFactory> */
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'canton_id',
        'name',
        'slug',
        'admin_type',
        'official_code',
        'statistical_code',
    ];

    protected function casts(): array
    {
        return [
            'admin_type' => GeoMunicipalityAdminType::class,
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(GeoEntity::class, 'entity_id');
    }

    public function canton(): BelongsTo
    {
        return $this->belongsTo(GeoCanton::class, 'canton_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(GeoSettlement::class, 'municipality_id');
    }

    public function places(): HasMany
    {
        return $this->hasMany(GeoPlace::class, 'municipality_id');
    }
}
