<?php

namespace App\Models;

use App\Enums\DataImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImport extends Model
{
    protected $fillable = [
        'data_source_id',
        'import_type',
        'status',
        'started_at',
        'finished_at',
        'records_read',
        'records_inserted',
        'records_updated',
        'records_skipped',
        'errors',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => DataImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'records_read' => 'integer',
            'records_inserted' => 'integer',
            'records_updated' => 'integer',
            'records_skipped' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
