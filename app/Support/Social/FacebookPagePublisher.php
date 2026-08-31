<?php

namespace App\Support\Social;

use App\Models\Post;
use App\Support\Seo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookPagePublisher
{
    public function isEnabled(): bool
    {
        return (bool) config('outages.facebook_auto_share', false)
            && filled(config('services.facebook.page_id'))
            && filled(config('services.facebook.page_access_token'));
    }

    public function shouldShare(Post $post): bool
    {
        if ($post->service_type !== 'power_outage' || ! $this->isEnabled()) {
            return false;
        }

        if (filled(data_get($post->seo_metadata, 'facebook.postId'))) {
            return false;
        }

        if (config('outages.facebook_only_milosevac', false) && ! $post->isMilosevacOutage()) {
            return false;
        }

        return true;
    }

    public function sharePost(Post $post): ?string
    {
        if (! $this->shouldShare($post)) {
            return null;
        }

        $url = Seo::frontendUrl('clanak/'.$post->slug);
        $message = trim($post->title."\n\n".$post->excerpt);
        $endpoint = sprintf(
            'https://graph.facebook.com/%s/%s/feed',
            config('services.facebook.graph_version', 'v21.0'),
            config('services.facebook.page_id'),
        );

        $response = Http::timeout(20)
            ->asForm()
            ->post($endpoint, [
                'message' => $message,
                'link' => $url,
                'access_token' => config('services.facebook.page_access_token'),
            ]);

        if (! $response->successful()) {
            Log::warning('Facebook page share failed', [
                'post_id' => $post->id,
                'slug' => $post->slug,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return null;
        }

        $facebookPostId = $response->json('id');
        if (! filled($facebookPostId)) {
            Log::warning('Facebook page share returned no post id', [
                'post_id' => $post->id,
                'slug' => $post->slug,
                'body' => $response->json(),
            ]);

            return null;
        }

        Log::info('Facebook page share published', [
            'post_id' => $post->id,
            'slug' => $post->slug,
            'facebook_post_id' => $facebookPostId,
        ]);

        return (string) $facebookPostId;
    }

    public function markShared(Post $post, string $facebookPostId): Post
    {
        $metadata = $post->seo_metadata ?? [];
        $metadata['facebook'] = [
            'postId' => $facebookPostId,
            'sharedAt' => now()->toIso8601String(),
        ];

        $post->update(['seo_metadata' => $metadata]);

        return $post->fresh();
    }
}
