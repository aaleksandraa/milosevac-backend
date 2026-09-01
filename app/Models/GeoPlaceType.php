<?php

namespace App\Models;

use App\Enums\GeoPlaceFamily;
use Database\Factories\GeoPlaceTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoPlaceType extends Model
{
    /** @use HasFactory<GeoPlaceTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'family',
        'code',
        'name',
        'singular_label',
        'plural_label',
        'icon_key',
        'default_visible',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'family' => GeoPlaceFamily::class,
            'default_visible' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function places(): HasMany
    {
        return $this->hasMany(GeoPlace::class, 'place_type_id');
    }
}
