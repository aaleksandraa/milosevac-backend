<?php

namespace App\Models;

use App\Enums\GeoEntityKind;
use Database\Factories\GeoEntityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoEntity extends Model
{
    /** @use HasFactory<GeoEntityFactory> */
    use HasFactory;

    protected $fillable = [
        'country_id',
        'name',
        'slug',
        'kind',
    ];

    protected function casts(): array
    {
        return [
            'kind' => GeoEntityKind::class,
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(GeoCountry::class, 'country_id');
    }

    public function cantons(): HasMany
    {
        return $this->hasMany(GeoCanton::class, 'entity_id');
    }

    public function municipalities(): HasMany
    {
        return $this->hasMany(GeoMunicipality::class, 'entity_id');
    }
}
