<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Post extends Model
{
    protected $fillable = [
        'author_id', 'category_id', 'title', 'slug', 'excerpt', 'content',
        'featured_image', 'featured_image_alt', 'status', 'published_at',
        'featured_image_responsive', 'scheduled_at', 'meta_title', 'meta_description', 'canonical_url',
        'og_image', 'og_image_responsive', 'reading_time', 'views_count', 'is_featured', 'is_breaking',
        'label', 'service_type', 'notice_starts_at', 'notice_ends_at', 'notice_schedule', 'seo_metadata',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'notice_starts_at' => 'datetime',
        'notice_ends_at' => 'datetime',
        'is_featured' => 'boolean',
        'is_breaking' => 'boolean',
        'featured_image_responsive' => 'array',
        'og_image_responsive' => 'array',
        'seo_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Post $post) {
            $post->slug = $post->slug ?: Str::slug($post->title);
            $words = str_word_count(strip_tags($post->content));
            $post->reading_time = max(1, (int) ceil($words / 220));
            $post->excerpt = $post->excerpt ?: Str::limit(trim(strip_tags($post->content)), 170);
        });

        static::saved(fn () => self::invalidatePortalContentCache());
        static::deleted(fn () => self::invalidatePortalContentCache());
    }

    private static function invalidatePortalContentCache(): void
    {
        cache()->add('api.content.version', 1);
        cache()->increment('api.content.version');
    }

    public static function invalidatePortalContentCacheForImages(): void
    {
        self::invalidatePortalContentCache();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopeActiveNotices(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            $inner->where('label', 'obavijest')
                ->orWhere('service_type', 'power_outage');
        })
            ->where(function (Builder $inner) {
                $inner->whereNull('notice_starts_at')->orWhere('notice_starts_at', '<=', now());
            })
            ->where(function (Builder $inner) {
                $inner->whereNull('notice_ends_at')->orWhere('notice_ends_at', '>=', now());
            });
    }

    public function scopePortalOrder(Builder $query): Builder
    {
        $now = now('Europe/Sarajevo')->toDateTimeString();

        return $query
            ->orderByRaw(
                'CASE
                    WHEN service_type = ? AND notice_starts_at <= ? AND notice_ends_at >= ? AND EXISTS (
                        SELECT 1 FROM place_post
                        INNER JOIN places ON places.id = place_post.place_id
                        WHERE place_post.post_id = posts.id AND places.is_primary = 1
                    ) THEN 4
                    WHEN service_type = ? AND notice_starts_at > ? AND EXISTS (
                        SELECT 1 FROM place_post
                        INNER JOIN places ON places.id = place_post.place_id
                        WHERE place_post.post_id = posts.id AND places.is_primary = 1
                    ) THEN 3
                    WHEN service_type = ? AND notice_starts_at <= ? AND notice_ends_at >= ? THEN 2
                    WHEN service_type = ? AND notice_starts_at > ? THEN 1
                    WHEN (label = ? OR service_type = ?) AND (notice_starts_at IS NULL OR notice_starts_at <= ?) AND (notice_ends_at IS NULL OR notice_ends_at >= ?) THEN 1
                    ELSE 0
                END DESC',
                [
                    'power_outage', $now, $now,
                    'power_outage', $now,
                    'power_outage', $now, $now,
                    'power_outage', $now,
                    'obavijest', 'power_outage', $now, $now,
                ]
            )
            ->orderBy('notice_starts_at')
            ->latest('published_at');
    }

    public function labelText(): ?string
    {
        return match ($this->service_type ?: $this->label) {
            'power_outage' => 'Obavijest',
            'hitno' => 'Hitno',
            'obavijest' => 'Obavijest',
            'info' => 'Info',
            'najava' => 'Najava',
            default => null,
        };
    }

    public function labelClass(): string
    {
        return match ($this->service_type ?: $this->label) {
            'power_outage' => 'post-label-power',
            'hitno' => 'post-label-urgent',
            'obavijest' => 'post-label-notice',
            'info' => 'post-label-info',
            'najava' => 'post-label-event',
            default => '',
        };
    }

    public function hasActiveNoticePriority(): bool
    {
        $starts = $this->notice_starts_at === null || $this->notice_starts_at->lte(now());
        $ends = $this->notice_ends_at === null || $this->notice_ends_at->gte(now());

        $isNotice = in_array($this->label, ['obavijest'], true) || $this->service_type === 'power_outage';

        return $isNotice && $starts && $ends;
    }

    public function defaultImageUrl(): ?string
    {
        if ($this->service_type !== 'power_outage') {
            return null;
        }

        $path = self::powerOutageDefaultImagePath();

        return $path ? asset('storage/'.$path) : asset('default-power-notice.svg');
    }

    public static function powerOutageDefaultImagePath(): ?string
    {
        $path = trim((string) config('outages.default_image', ''), '/');

        return $path !== '' ? $path : null;
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class);
    }

    public function isMilosevacOutage(): bool
    {
        if ($this->relationLoaded('places')) {
            return $this->places->contains(fn (Place $place) => $place->is_primary);
        }

        return $this->places()->where('is_primary', true)->exists();
    }

    public function outageStatus(?\Carbon\Carbon $now = null): ?string
    {
        if ($this->service_type !== 'power_outage') {
            return null;
        }

        $now ??= now('Europe/Sarajevo');
        $starts = $this->notice_starts_at;
        $ends = $this->notice_ends_at;

        if (! $starts || ! $ends) {
            return null;
        }

        if ($now->lt($starts)) {
            return 'upcoming';
        }

        if ($now->lt($ends)) {
            return 'active';
        }

        return 'past';
    }

    public function views(): HasMany
    {
        return $this->hasMany(PostView::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('sort_order')->orderBy('id');
    }

    public function galleryMedia(): HasMany
    {
        return $this->hasMany(Media::class)
            ->where('media_type', 'post_gallery')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
