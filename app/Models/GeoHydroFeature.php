<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoHydroFeature extends Model
{
    protected $table = 'geo_hydro_features';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_properties' => 'array', 'geometry' => 'array', 'osm_timestamp' => 'datetime'];
    }
}
