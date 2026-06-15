<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class FootballMatch extends Model
{
    protected $table = 'matches';

    protected $fillable = [
        'author_id', 'title', 'slug', 'home_team', 'away_team', 'home_score',
        'away_score', 'played_at', 'venue', 'excerpt', 'content', 'status',
        'published_at', 'scheduled_at', 'meta_title', 'meta_description',
        'cover_image', 'cover_image_responsive',
    ];

    protected $casts = [
        'played_at' => 'datetime',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'cover_image_responsive' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (FootballMatch $match) {
            $match->slug = $match->slug ?: Str::slug($match->title);
            $match->excerpt = $match->excerpt ?: Str::limit(trim(strip_tags((string) $match->content)), 150);
        });
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'match_media', 'match_id', 'media_id')
            ->withPivot(['caption', 'sort_order'])
            ->withTimestamps()
            ->orderBy('match_media.sort_order');
    }

    public function score(): string
    {
        if ($this->home_score === null || $this->away_score === null) {
            return '-';
        }

        return "{$this->home_score}:{$this->away_score}";
    }
}
