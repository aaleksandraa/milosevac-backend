<?php

namespace App\Support;

use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Str;

class PostTaxonomyManager
{
    /** @param array<int, int|string> $categoryIds */
    public function syncCategories(Post $post, array $categoryIds): void
    {
        $ids = collect($categoryIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $post->categories()->sync($ids->all());
    }

    public function syncTagsFromText(Post $post, ?string $tagsText): void
    {
        $tagIds = collect(preg_split('/[,;\n]+/', (string) $tagsText))
            ->map(fn ($tag) => trim($tag))
            ->filter()
            ->unique(fn ($tag) => Str::lower($tag))
            ->map(function (string $name) {
                $slug = Str::slug($name);
                if ($slug === '') {
                    $slug = 'tag-'.substr(sha1(Str::lower($name)), 0, 10);
                }

                return Tag::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $name]
                )->id;
            })
            ->values()
            ->all();

        $post->tags()->sync($tagIds);
    }
}
