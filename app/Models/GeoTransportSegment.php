<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoTransportSegment extends Model
{
    protected $table = 'geo_transport_segments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_properties' => 'array', 'geometry' => 'array', 'osm_timestamp' => 'datetime'];
    }
}
