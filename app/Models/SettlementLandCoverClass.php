<?php

namespace App\Models;

use Database\Factories\SettlementLandCoverClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementLandCoverClass extends Model
{
    /** @use HasFactory<SettlementLandCoverClassFactory> */
    use HasFactory;

    protected $fillable = [
        'surface_profile_id',
        'class_code',
        'source_class_code',
        'source_class_label',
        'atlas_group',
        'area_km2',
        'share_pct',
        'pixel_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'area_km2' => 'decimal:6',
            'share_pct' => 'decimal:4',
            'pixel_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SettlementSurfaceProfile::class, 'surface_profile_id');
    }
}
