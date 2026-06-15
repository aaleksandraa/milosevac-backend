<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostView extends Model
{
    public $timestamps = false;

    protected $fillable = ['post_id', 'ip_hash', 'user_agent_hash', 'viewed_at'];

    protected $casts = ['viewed_at' => 'datetime'];
}
