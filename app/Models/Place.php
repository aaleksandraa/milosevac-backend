<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Place extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'locative',
        'aliases',
        'is_primary',
        'sort_order',
    ];

    protected $casts = [
        'aliases' => 'array',
        'is_primary' => 'boolean',
    ];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
