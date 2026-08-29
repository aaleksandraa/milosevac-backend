<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\Post;
use App\Support\PowerOutage\ArticleWriter;
use App\Support\PowerOutage\PlaceMatcher;
use App\Support\PowerOutage\PowerOutageNotice;
use App\Support\PostTaxonomyManager;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncOutagePlaces extends Command
{
    protected $signature = 'outages:sync-places
        {--dry-run : Preview changes without saving}
        {--update-titles : Regenerate article titles from matched places}';

    protected $description = 'Re-match places on published power_outage posts from stored outage metadata';

    public function handle(PlaceMatcher $matcher, ArticleWriter $writer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updateTitles = (bool) $this->option('update-titles');
        $updated = 0;

        Post::query()
            ->where('service_type', 'power_outage')
            ->whereNotNull('seo_metadata')
            ->orderBy('id')
            ->chunkById(50, function ($posts) use ($matcher, $writer, $dryRun, $updateTitles, &$updated) {
                foreach ($posts as $post) {
                    $notice = $this->noticeFromPost($post);
                    if ($notice === null) {
                        continue;
                    }

                    $places = $matcher->match($notice);
                    $currentSlugs = $post->places()->pluck('slug')->sort()->values()->all();
                    $newSlugs = collect($places)->pluck('slug')->sort()->values()->all();
                    $newTitle = $updateTitles ? $writer->title($notice, $places) : null;
                    $titleChanged = $updateTitles && $newTitle !== $post->title;

                    if ($currentSlugs === $newSlugs && ! $titleChanged) {
                        continue;
                    }

                    $this->line(sprintf(
                        '[%s] #%d %s',
                        $dryRun ? 'DRY' : 'SYNC',
                        $post->id,
                        $post->title,
                    ));
                    $this->line('  mjesta: '.implode(', ', $currentSlugs ?: ['—']).' → '.implode(', ', $newSlugs ?: ['—']));

                    if ($titleChanged) {
                        $this->line('  naslov: '.$newTitle);
                    }

                    if ($dryRun) {
                        continue;
                    }

                    if ($places !== []) {
                        $placeIds = Place::query()
                            ->whereIn('slug', collect($places)->pluck('slug')->all())
                            ->pluck('id')
                            ->all();
                        $post->places()->sync($placeIds);
                    } else {
                        $post->places()->sync([]);
                    }

                    $tagNames = array_values(array_unique(array_filter([
                        'struja',
                        'obavjestenje',
                        ...collect($places)->pluck('slug')->all(),
                    ])));
                    app(PostTaxonomyManager::class)->syncTagsFromText($post, implode(', ', $tagNames));

                    if ($titleChanged) {
                        $post->update(['title' => $newTitle]);
                    }

                    $updated++;
                }
            });

        $this->info($dryRun
            ? 'Dry run complete.'
            : "Updated {$updated} power outage post(s).");

        return self::SUCCESS;
    }

    private function noticeFromPost(Post $post): ?PowerOutageNotice
    {
        $locationsRaw = data_get($post->seo_metadata, 'outage.locationsRaw');
        if (! filled($locationsRaw)) {
            return null;
        }

        $startsAt = $post->notice_starts_at;
        $endsAt = $post->notice_ends_at;
        if ($startsAt === null || $endsAt === null) {
            return null;
        }

        $schedule = $post->notice_schedule;
        if (filled($schedule) && preg_match('/(\d{2}:\d{2}).*?(\d{2}:\d{2})/', $schedule, $timeMatch)) {
            $startsAtLabel = $timeMatch[1];
            $endsAtLabel = $timeMatch[2];
        } else {
            $startsAtLabel = $startsAt->timezone('Europe/Sarajevo')->format('H:i');
            $endsAtLabel = $endsAt->timezone('Europe/Sarajevo')->format('H:i');
        }

        return new PowerOutageNotice(
            externalId: (string) data_get($post->seo_metadata, 'outage.externalId', $post->id),
            sourceUrl: data_get($post->seo_metadata, 'outage.sourceUrl'),
            date: Carbon::parse($startsAt)->timezone('Europe/Sarajevo')->startOfDay(),
            startsAt: $startsAtLabel,
            endsAt: $endsAtLabel,
            fieldUnit: (string) data_get($post->seo_metadata, 'outage.fieldUnit', ''),
            locationsRaw: (string) $locationsRaw,
            transformerArea: (string) data_get($post->seo_metadata, 'outage.transformerArea', ''),
        );
    }
}
