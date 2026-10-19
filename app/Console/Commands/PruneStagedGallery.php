<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\StagedGallery;
use Illuminate\Console\Command;

class PruneStagedGallery extends Command
{
    protected $signature = 'gallery:prune-staged {--hours=48 : Delete staged photos older than this many hours}';

    protected $description = 'Delete gallery photos that were uploaded but never attached to a match or article.';

    public function handle(StagedGallery $stagedGallery): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = 0;

        Media::query()
            ->whereIn('media_type', [StagedGallery::MATCH, StagedGallery::POST])
            ->whereNull('post_id')
            ->where('created_at', '<', now()->subHours($hours))
            ->eachById(function (Media $media) use ($stagedGallery, &$deleted) {
                $stagedGallery->deleteFiles($media);
                $deleted++;
            });

        $this->info("Deleted {$deleted} abandoned gallery photo(s).");

        return self::SUCCESS;
    }
}
