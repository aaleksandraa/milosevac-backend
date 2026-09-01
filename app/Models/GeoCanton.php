<?php

namespace App\Models;

use Database\Factories\GeoCantonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoCanton extends Model
{
    /** @use HasFactory<GeoCantonFactory> */
    use HasFactory;

    protected $fillable = [
        'entity_id',
        'name',
        'slug',
        'official_code',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(GeoEntity::class, 'entity_id');
    }

    public function municipalities(): HasMany
    {
        return $this->hasMany(GeoMunicipality::class, 'canton_id');
    }
}
