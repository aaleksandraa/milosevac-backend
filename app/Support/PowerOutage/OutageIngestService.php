<?php

namespace App\Support\PowerOutage;

use App\Models\Place;
use App\Models\PowerOutageIngestion;
use App\Models\Post;
use App\Support\Social\FacebookPagePublisher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

class OutageIngestService
{
    public function __construct(
        private NoticeParser $parser = new NoticeParser(),
        private PlaceMatcher $matcher = new PlaceMatcher(),
        private ArticleWriter $writer = new ArticleWriter(),
        private OutagePublisher $publisher = new OutagePublisher(),
        private FacebookPagePublisher $facebook = new FacebookPagePublisher(),
    ) {}

    /** @return array{results: list<array<string, mixed>>, scanned: int, matched: int} */
    public function ingestFromMailbox(bool $dryRun = false, bool $retryPending = false): array
    {
        $client = (new ClientManager())->make([
            'host' => config('outages.imap.host'),
            'port' => config('outages.imap.port'),
            'encryption' => config('outages.imap.encryption'),
            'validate_cert' => true,
            'username' => config('outages.imap.username'),
            'password' => config('outages.imap.password'),
            'protocol' => 'imap',
        ]);

        $client->connect();
        $folder = $client->getFolder(config('outages.imap.folder', 'INBOX'));
        $since = now()->subDays(config('outages.lookback_days', 30))->format('d-M-Y');
        $messages = $folder->messages()->since($since)->get();
        $results = [];
        $matched = 0;

        foreach ($messages as $message) {
            /** @var Message $message */
            $subject = (string) $message->getSubject();
            $from = $this->extractFrom($message);
            $messageId = (string) ($message->getMessageId() ?: $message->getUid());
            $body = $this->extractBody($message);

            if (! $this->passesFilter($subject, $from)) {
                continue;
            }

            $matched++;
            $results[] = $this->processMessage($messageId, $subject, $from, $body, $dryRun, $retryPending);
        }

        if ($retryPending && ! $dryRun) {
            foreach ($this->pendingIngestions()->get() as $ingestion) {
                $results[] = $this->publishFromIngestion($ingestion);
            }
        }

        Log::info('Outage mailbox ingest finished', [
            'scanned' => $messages->count(),
            'matched_filter' => $matched,
            'processed' => count($results),
            'dry_run' => $dryRun,
            'retry_pending' => $retryPending,
            'auto_publish' => (bool) config('outages.auto_publish', true),
        ]);

        return [
            'results' => $results,
            'scanned' => $messages->count(),
            'matched' => $matched,
        ];
    }

    /** @return array<string, mixed> */
    public function processMessage(
        string $messageId,
        string $subject,
        string $from,
        string $body,
        bool $dryRun = false,
        bool $retryPending = false,
    ): array {
        $existing = PowerOutageIngestion::query()->where('message_id', $messageId)->first();
        if ($existing !== null) {
            if ($existing->status === PowerOutageIngestion::STATUS_PENDING && ($retryPending || config('outages.auto_publish', true)) && ! $dryRun) {
                return $this->publishFromIngestion($existing);
            }

            return [
                'ok' => true,
                'status' => $existing->status,
                'message_id' => $messageId,
                'failure_reason' => $existing->failure_reason ?: 'Message-ID već obrađen.',
                'external_id' => $existing->elektrodoboj_id,
            ];
        }

        if (! $this->passesFilter($subject, $from)) {
            $ingestion = PowerOutageIngestion::query()->create([
                'message_id' => $messageId,
                'status' => PowerOutageIngestion::STATUS_SKIPPED_FILTER,
                'failure_reason' => 'Subject ili pošiljalac ne odgovaraju filteru.',
                'processed_at' => now(),
                'raw_subject' => $subject,
                'body_hash' => hash('sha256', $body),
            ]);

            return [
                'ok' => true,
                'status' => $ingestion->status,
                'message_id' => $messageId,
            ];
        }

        try {
            $notice = $this->parser->parse($body);
        } catch (\Throwable $exception) {
            PowerOutageIngestion::query()->create([
                'message_id' => $messageId,
                'status' => PowerOutageIngestion::STATUS_FAILED_PARSE,
                'failure_reason' => $exception->getMessage(),
                'processed_at' => now(),
                'raw_subject' => $subject,
                'body_hash' => hash('sha256', $body),
                'body_excerpt' => mb_substr($body, 0, 500),
            ]);

            Log::warning('Outage parse failed', [
                'message_id' => $messageId,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'message_id' => $messageId,
                'failure_reason' => $exception->getMessage(),
            ];
        }

        if (PowerOutageIngestion::query()->where('elektrodoboj_id', (int) $notice->externalId)->exists()
            || Post::query()->where('slug', 'like', '%-elektrodoboj-'.$notice->externalId)->exists()) {
            PowerOutageIngestion::query()->create([
                'message_id' => $messageId,
                'elektrodoboj_id' => (int) $notice->externalId,
                'status' => PowerOutageIngestion::STATUS_SKIPPED_DUPLICATE,
                'failure_reason' => 'Elektro Doboj ID već objavljen.',
                'processed_at' => now(),
                'raw_subject' => $subject,
                'parsed' => ['notice' => $notice->toArray()],
            ]);

            return [
                'ok' => true,
                'status' => PowerOutageIngestion::STATUS_SKIPPED_DUPLICATE,
                'message_id' => $messageId,
                'external_id' => $notice->externalId,
            ];
        }

        Place::query()->count() === 0
            ? (new \Database\Seeders\PlaceSeeder())->run()
            : null;

        $places = $this->matcher->match($notice);
        $title = $this->writer->title($notice, $places);
        $preview = $this->buildPreview($notice, $places, $title, $messageId);
        $parsedPayload = [
            'notice' => $notice->toArray(),
            'places' => array_map(fn (MatchedPlace $place) => $place->toArray(), $places),
        ];

        if ($dryRun) {
            return $preview + ['ok' => true, 'status' => 'dry_run'];
        }

        if (! config('outages.auto_publish', true)) {
            PowerOutageIngestion::query()->create([
                'message_id' => $messageId,
                'elektrodoboj_id' => (int) $notice->externalId,
                'status' => PowerOutageIngestion::STATUS_PENDING,
                'failure_reason' => 'OUTAGE_AUTO_PUBLISH=false — obavijest je parsirana ali nije objavljena.',
                'processed_at' => now(),
                'raw_subject' => $subject,
                'parsed' => $parsedPayload,
                'body_hash' => hash('sha256', $body),
            ]);

            Log::info('Outage parsed but not published (auto_publish disabled)', [
                'message_id' => $messageId,
                'external_id' => $notice->externalId,
            ]);

            return $preview + [
                'ok' => true,
                'status' => PowerOutageIngestion::STATUS_PENDING,
            ];
        }

        return $this->publishNotice(
            messageId: $messageId,
            subject: $subject,
            body: $body,
            notice: $notice,
            places: $places,
            preview: $preview,
            parsedPayload: $parsedPayload,
        );
    }

    /** @return list<array<string, mixed>> */
    public function publishPendingIngestions(): array
    {
        $results = [];

        foreach ($this->pendingIngestions()->get() as $ingestion) {
            $results[] = $this->publishFromIngestion($ingestion);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function publishFromIngestion(PowerOutageIngestion $ingestion): array
    {
        $noticeData = data_get($ingestion->parsed, 'notice');
        if (! is_array($noticeData)) {
            return [
                'ok' => false,
                'message_id' => $ingestion->message_id,
                'failure_reason' => 'Pending zapis nema parsirane podatke.',
            ];
        }

        try {
            $notice = PowerOutageNotice::fromArray($noticeData);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message_id' => $ingestion->message_id,
                'failure_reason' => $exception->getMessage(),
            ];
        }

        $places = $this->placesFromParsed(data_get($ingestion->parsed, 'places', []));
        if ($places === []) {
            $places = $this->matcher->match($notice);
        }

        $preview = $this->buildPreview($notice, $places, $this->writer->title($notice, $places), $ingestion->message_id);

        if (Post::query()->where('slug', 'like', '%-elektrodoboj-'.$notice->externalId)->exists()) {
            $ingestion->update([
                'status' => PowerOutageIngestion::STATUS_SKIPPED_DUPLICATE,
                'failure_reason' => 'Elektro Doboj ID već objavljen.',
                'processed_at' => now(),
            ]);

            return $preview + [
                'ok' => true,
                'status' => PowerOutageIngestion::STATUS_SKIPPED_DUPLICATE,
                'message_id' => $ingestion->message_id,
                'external_id' => $notice->externalId,
            ];
        }

        return $this->publishNotice(
            messageId: $ingestion->message_id,
            subject: (string) $ingestion->raw_subject,
            body: '',
            notice: $notice,
            places: $places,
            preview: $preview,
            parsedPayload: (array) $ingestion->parsed,
            existingIngestion: $ingestion,
        );
    }

    /**
     * @param list<MatchedPlace> $places
     * @param array<string, mixed> $preview
     * @param array<string, mixed> $parsedPayload
     * @return array<string, mixed>
     */
    private function publishNotice(
        string $messageId,
        string $subject,
        string $body,
        PowerOutageNotice $notice,
        array $places,
        array $preview,
        array $parsedPayload,
        ?PowerOutageIngestion $existingIngestion = null,
    ): array {
        try {
            $post = $this->publisher->publish($notice, $places);
        } catch (\Throwable $exception) {
            Log::error('Outage publish failed', ['message_id' => $messageId, 'error' => $exception->getMessage()]);

            if ($existingIngestion !== null) {
                $existingIngestion->update([
                    'status' => PowerOutageIngestion::STATUS_FAILED_PUBLISH,
                    'failure_reason' => $exception->getMessage(),
                    'processed_at' => now(),
                ]);
            } else {
                PowerOutageIngestion::query()->create([
                    'message_id' => $messageId,
                    'elektrodoboj_id' => (int) $notice->externalId,
                    'status' => PowerOutageIngestion::STATUS_FAILED_PUBLISH,
                    'failure_reason' => $exception->getMessage(),
                    'processed_at' => now(),
                    'raw_subject' => $subject,
                    'parsed' => $parsedPayload,
                    'body_hash' => $body !== '' ? hash('sha256', $body) : null,
                    'body_excerpt' => $body !== '' ? mb_substr($body, 0, 500) : null,
                ]);
            }

            return [
                'ok' => false,
                'message_id' => $messageId,
                'failure_reason' => $exception->getMessage(),
            ];
        }

        if ($existingIngestion !== null) {
            $existingIngestion->update([
                'elektrodoboj_id' => (int) $notice->externalId,
                'post_id' => $post->id,
                'status' => PowerOutageIngestion::STATUS_PUBLISHED,
                'failure_reason' => null,
                'processed_at' => now(),
                'parsed' => $parsedPayload,
            ]);
        } else {
            PowerOutageIngestion::query()->create([
                'message_id' => $messageId,
                'elektrodoboj_id' => (int) $notice->externalId,
                'post_id' => $post->id,
                'status' => PowerOutageIngestion::STATUS_PUBLISHED,
                'processed_at' => now(),
                'raw_subject' => $subject,
                'parsed' => $parsedPayload,
                'body_hash' => $body !== '' ? hash('sha256', $body) : null,
            ]);
        }

        Log::info('Outage published', [
            'message_id' => $messageId,
            'external_id' => $notice->externalId,
            'slug' => $post->slug,
        ]);

        $facebookPostId = $this->shareToFacebook($post);

        return $preview + [
            'ok' => true,
            'status' => PowerOutageIngestion::STATUS_PUBLISHED,
            'slug' => $post->slug,
            'facebook_post_id' => $facebookPostId,
        ];
    }

    private function shareToFacebook(Post $post): ?string
    {
        if (! $this->facebook->shouldShare($post)) {
            return data_get($post->seo_metadata, 'facebook.postId');
        }

        $facebookPostId = $this->facebook->sharePost($post);
        if ($facebookPostId === null) {
            return null;
        }

        $this->facebook->markShared($post, $facebookPostId);

        return $facebookPostId;
    }

    /** @param mixed $placesData @return list<MatchedPlace> */
    private function placesFromParsed(mixed $placesData): array
    {
        if (! is_array($placesData)) {
            return [];
        }

        $places = [];
        foreach ($placesData as $place) {
            if (! is_array($place) || empty($place['slug'])) {
                continue;
            }

            $places[] = new MatchedPlace(
                slug: (string) $place['slug'],
                name: (string) ($place['name'] ?? $place['slug']),
                locative: filled($place['locative'] ?? null) ? (string) $place['locative'] : null,
                isPrimary: (bool) ($place['isPrimary'] ?? false),
            );
        }

        return $places;
    }

    private function pendingIngestions()
    {
        return PowerOutageIngestion::query()
            ->where('status', PowerOutageIngestion::STATUS_PENDING)
            ->orderBy('id');
    }

    /** @param list<MatchedPlace> $places */
    private function buildPreview(PowerOutageNotice $notice, array $places, string $title, string $messageId): array
    {
        $isMilosevac = collect($places)->contains(fn (MatchedPlace $place) => $place->isPrimary);

        return [
            'message_id' => $messageId,
            'external_id' => $notice->externalId,
            'date' => $notice->date->format('d.m.Y'),
            'time' => $notice->startsAt.'–'.$notice->endsAt,
            'field_unit' => $notice->fieldUnit,
            'locations_raw' => $notice->locationsRaw,
            'transformer_area' => $notice->transformerArea,
            'places' => collect($places)->pluck('name')->implode(', ') ?: '—',
            'milosevac' => $isMilosevac ? 'DA' : 'NE',
            'title' => $title,
        ];
    }

    private function passesFilter(string $subject, string $from): bool
    {
        $expectedFrom = strtolower((string) config('outages.from'));
        if ($expectedFrom !== '' && ! str_contains(strtolower($from), $expectedFrom)) {
            return false;
        }

        $needle = mb_strtolower((string) config('outages.subject_contains', 'planiranim prekidima'));
        $normalizedSubject = mb_strtolower((new CyrillicLatin())->transliterate($subject));

        return str_contains($normalizedSubject, $needle)
            || str_contains(mb_strtolower($subject), $needle);
    }

    private function extractFrom(Message $message): string
    {
        $from = $message->getFrom()?->first();

        return strtolower(trim((string) ($from?->mail ?? $from?->mailbox ?? '')));
    }

    private function extractBody(Message $message): string
    {
        $text = (string) $message->getTextBody();
        if ($text !== '') {
            return $text;
        }

        return strip_tags((string) $message->getHTMLBody());
    }
}
