<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementProximity extends Model
{
    protected $table = 'settlement_proximities';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['origin_lat' => 'decimal:7', 'origin_lng' => 'decimal:7', 'target_id' => 'string', 'metadata' => 'array', 'calculated_at' => 'datetime', 'distance_straight_m' => 'float', 'distance_route_m' => 'float', 'travel_time_s' => 'float'];
    }
}
