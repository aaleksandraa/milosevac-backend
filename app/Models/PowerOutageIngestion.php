<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PowerOutageIngestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';

    public const STATUS_SKIPPED_FILTER = 'skipped_filter';

    public const STATUS_FAILED_PARSE = 'failed_parse';

    public const STATUS_FAILED_PUBLISH = 'failed_publish';

    protected $fillable = [
        'message_id',
        'elektrodoboj_id',
        'post_id',
        'status',
        'failure_reason',
        'processed_at',
        'raw_subject',
        'parsed',
        'body_hash',
        'body_excerpt',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'parsed' => 'array',
        'elektrodoboj_id' => 'integer',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
