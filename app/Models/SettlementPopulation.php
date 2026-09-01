<?php

namespace App\Models;

use Database\Factories\SettlementPopulationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementPopulation extends Model
{
    /** @use HasFactory<SettlementPopulationFactory> */
    use HasFactory;

    protected $table = 'settlement_population';

    protected $fillable = [
        'settlement_id',
        'census_year',
        'population',
        'households',
        'statistical_settlement_code',
        'source_id',
        'boundary_version',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'census_year' => 'integer',
            'population' => 'integer',
            'households' => 'integer',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GeoSettlement::class, 'settlement_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'source_id');
    }
}
