<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoMetadata extends Model
{
    protected $fillable = ['title', 'description', 'canonical_url', 'og_image', 'schema'];

    protected $casts = ['schema' => 'array'];
}
