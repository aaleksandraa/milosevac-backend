<?php

namespace App\Support\PowerOutage;

use App\Models\Category;
use App\Models\Place;
use App\Models\Post;
use App\Models\User;
use App\Support\PostTaxonomyManager;
use Illuminate\Support\Str;

class OutagePublisher
{
    public function __construct(private ArticleWriter $writer = new ArticleWriter()) {}

    /** @param list<MatchedPlace> $places */
    public function publish(PowerOutageNotice $notice, array $places, ?User $author = null): Post
    {
        $author ??= User::query()->where('email', 'redakcija@milosevac.test')->first()
            ?? User::query()->firstOrFail();

        $title = $this->writer->title($notice, $places);
        $content = $this->writer->contentHtml($notice, $places);
        $excerpt = $this->writer->excerpt($notice, $places);
        $isMilosevac = collect($places)->contains(fn (MatchedPlace $place) => $place->isPrimary);

        $category = Category::query()->where('slug', 'vijesti')->first()
            ?? Category::query()->firstOrFail();

        $featuredImage = app(PowerOutageFeaturedImage::class)->bundle();
        $defaultImage = $featuredImage['path'];
        $defaultAlt = $featuredImage['alt'];

        $post = Post::query()->create([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'title' => $title,
            'slug' => $this->uniqueSlug($title, $notice->externalId),
            'excerpt' => $excerpt,
            'content' => $content,
            'featured_image' => $defaultImage,
            'featured_image_alt' => $defaultAlt,
            'featured_image_responsive' => $featuredImage['responsive'],
            'og_image' => $featuredImage['og_image'],
            'og_image_responsive' => $featuredImage['og_image_responsive'],
            'status' => 'published',
            'published_at' => now(),
            'label' => 'obavijest',
            'service_type' => 'power_outage',
            'notice_starts_at' => $notice->noticeStartsAt(),
            'notice_ends_at' => $notice->noticeEndsAt(),
            'notice_schedule' => $notice->startsAt.'–'.$notice->endsAt,
            'canonical_url' => $notice->sourceUrl,
            'is_breaking' => $isMilosevac,
            'seo_metadata' => [
                'outage' => [
                    'externalId' => $notice->externalId,
                    'locationsRaw' => $notice->locationsRaw,
                    'transformerArea' => $notice->transformerArea,
                    'fieldUnit' => $notice->fieldUnit,
                    'sourceUrl' => $notice->sourceUrl,
                ],
            ],
        ]);

        $subcategory = Category::query()->where('slug', 'vijesti-komunalno')->first();
        $categoryIds = array_values(array_filter([
            $category->id,
            $subcategory?->id,
        ]));

        app(PostTaxonomyManager::class)->syncCategories($post, $categoryIds);

        $tagNames = array_values(array_unique(array_filter([
            'struja',
            'obavjestenje',
            ...collect($places)->pluck('slug')->all(),
        ])));

        app(PostTaxonomyManager::class)->syncTagsFromText($post, implode(', ', $tagNames));

        if ($places !== []) {
            $placeIds = Place::query()
                ->whereIn('slug', collect($places)->pluck('slug')->all())
                ->pluck('id')
                ->all();
            $post->places()->sync($placeIds);
        }

        return $post->fresh(['places', 'tags', 'category']);
    }

    private function uniqueSlug(string $title, string $externalId): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'prekid-struje';
        }

        $slug = $base;
        $suffix = 0;
        while (Post::query()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        $externalSlug = $base.'-elektrodoboj-'.$externalId;
        if (! Post::query()->where('slug', $externalSlug)->exists()) {
            return $externalSlug;
        }

        return $slug;
    }
}
