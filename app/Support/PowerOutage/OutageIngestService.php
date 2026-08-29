<?php

namespace App\Support\PowerOutage;

use App\Models\Place;
use App\Models\PowerOutageIngestion;
use App\Models\Post;
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
    ) {}

    /** @return list<array<string, mixed>> */
    public function ingestFromMailbox(bool $dryRun = false): array
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

        foreach ($messages as $message) {
            /** @var Message $message */
            $subject = (string) $message->getSubject();
            $from = $this->extractFrom($message);
            $messageId = (string) ($message->getMessageId() ?: $message->getUid());
            $body = $this->extractBody($message);

            if (! $this->passesFilter($subject, $from)) {
                continue;
            }

            $results[] = $this->processMessage($messageId, $subject, $from, $body, $dryRun);
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function processMessage(
        string $messageId,
        string $subject,
        string $from,
        string $body,
        bool $dryRun = false,
    ): array {
        if (PowerOutageIngestion::query()->where('message_id', $messageId)->exists()) {
            return [
                'ok' => true,
                'status' => PowerOutageIngestion::STATUS_SKIPPED_DUPLICATE,
                'message_id' => $messageId,
                'failure_reason' => 'Message-ID već obrađen.',
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

        if ($dryRun || ! config('outages.auto_publish', true)) {
            return $preview + ['ok' => true, 'status' => 'dry_run'];
        }

        try {
            $post = $this->publisher->publish($notice, $places);
        } catch (\Throwable $exception) {
            Log::error('Outage publish failed', ['message_id' => $messageId, 'error' => $exception->getMessage()]);
            PowerOutageIngestion::query()->create([
                'message_id' => $messageId,
                'elektrodoboj_id' => (int) $notice->externalId,
                'status' => PowerOutageIngestion::STATUS_FAILED_PUBLISH,
                'failure_reason' => $exception->getMessage(),
                'processed_at' => now(),
                'raw_subject' => $subject,
                'parsed' => [
                    'notice' => $notice->toArray(),
                    'places' => array_map(fn (MatchedPlace $place) => $place->toArray(), $places),
                ],
                'body_hash' => hash('sha256', $body),
                'body_excerpt' => mb_substr($body, 0, 500),
            ]);

            return [
                'ok' => false,
                'message_id' => $messageId,
                'failure_reason' => $exception->getMessage(),
            ];
        }

        PowerOutageIngestion::query()->create([
            'message_id' => $messageId,
            'elektrodoboj_id' => (int) $notice->externalId,
            'post_id' => $post->id,
            'status' => PowerOutageIngestion::STATUS_PUBLISHED,
            'processed_at' => now(),
            'raw_subject' => $subject,
            'parsed' => [
                'notice' => $notice->toArray(),
                'places' => array_map(fn (MatchedPlace $place) => $place->toArray(), $places),
            ],
            'body_hash' => hash('sha256', $body),
        ]);

        return $preview + [
            'ok' => true,
            'status' => PowerOutageIngestion::STATUS_PUBLISHED,
            'slug' => $post->slug,
        ];
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
