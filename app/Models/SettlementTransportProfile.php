<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementTransportProfile extends Model
{
    protected $table = 'settlement_transport_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'metadata' => 'array', 'calculated_at' => 'datetime'];
    }
}
