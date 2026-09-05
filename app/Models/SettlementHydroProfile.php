<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettlementHydroProfile extends Model
{
    protected $table = 'settlement_hydro_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'metadata' => 'array', 'calculated_at' => 'datetime'];
    }
}
