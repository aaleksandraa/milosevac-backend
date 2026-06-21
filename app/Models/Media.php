<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $fillable = [
        'user_id', 'post_id', 'disk', 'path', 'source_url', 'filename', 'mime_type',
        'media_type', 'size', 'alt_text', 'caption', 'sort_order', 'responsive_paths',
    ];

    protected $casts = ['responsive_paths' => 'array'];
}
