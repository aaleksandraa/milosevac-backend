<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Support\Social\FacebookPagePublisher;
use Illuminate\Console\Command;

class ShareOutageOnFacebook extends Command
{
    protected $signature = 'outages:share-facebook
        {slug? : Share one outage post by slug}
        {--all : Share all unpublished-to-Facebook outage posts}';

    protected $description = 'Share published power outage posts on the configured Facebook page';

    public function handle(FacebookPagePublisher $facebook): int
    {
        if (! $facebook->isEnabled()) {
            $this->error('Facebook auto share nije uključen. Postavite OUTAGE_FACEBOOK_AUTO_SHARE=true, FACEBOOK_PAGE_ID i FACEBOOK_PAGE_ACCESS_TOKEN.');

            return self::FAILURE;
        }

        $slug = $this->argument('slug');
        if ($slug) {
            $post = Post::query()->where('slug', $slug)->where('service_type', 'power_outage')->first();
            if ($post === null) {
                $this->error("Nije pronađen power_outage članak: {$slug}");

                return self::FAILURE;
            }

            return $this->shareOne($facebook, $post) ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('all')) {
            $this->error('Navedite slug ili koristite --all.');

            return self::FAILURE;
        }

        $shared = 0;
        Post::query()
            ->where('service_type', 'power_outage')
            ->orderBy('id')
            ->chunkById(50, function ($posts) use ($facebook, &$shared) {
                foreach ($posts as $post) {
                    if ($this->shareOne($facebook, $post, quiet: true)) {
                        $shared++;
                    }
                }
            });

        $this->info("Podijeljeno na Facebook: {$shared}");

        return self::SUCCESS;
    }

    private function shareOne(FacebookPagePublisher $facebook, Post $post, bool $quiet = false): bool
    {
        if (! $facebook->shouldShare($post)) {
            if (! $quiet) {
                $this->warn("Preskačem {$post->slug} (već podijeljeno ili nije uključeno).");
            }

            return false;
        }

        $facebookPostId = $facebook->sharePost($post);
        if ($facebookPostId === null) {
            if (! $quiet) {
                $this->error("Facebook share nije uspio za {$post->slug}.");
            }

            return false;
        }

        $facebook->markShared($post, $facebookPostId);

        if (! $quiet) {
            $this->info("Podijeljeno: {$post->slug} (Facebook ID {$facebookPostId})");
        }

        return true;
    }
}
