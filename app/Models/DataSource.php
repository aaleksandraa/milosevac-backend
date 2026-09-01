<?php

namespace App\Models;

use App\Enums\DataSourceClass;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'provider',
        'source_class',
        'authority_rank',
        'source_type',
        'url',
        'license',
        'attribution',
        'dataset_version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source_class' => DataSourceClass::class,
            'authority_rank' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function imports(): HasMany
    {
        return $this->hasMany(DataImport::class);
    }

    public function populationRecords(): HasMany
    {
        return $this->hasMany(SettlementPopulation::class, 'source_id');
    }
}
