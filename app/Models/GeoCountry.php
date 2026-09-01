<?php

namespace App\Models;

use Database\Factories\GeoCountryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoCountry extends Model
{
    /** @use HasFactory<GeoCountryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'iso2',
        'iso3',
    ];

    public function entities(): HasMany
    {
        return $this->hasMany(GeoEntity::class, 'country_id');
    }
}
