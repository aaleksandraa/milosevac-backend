<?php

namespace App\Console\Commands;

use App\Models\PowerOutageIngestion;
use App\Support\PowerOutage\OutageIngestService;
use Illuminate\Console\Command;

class IngestPowerOutages extends Command
{
    protected $signature = 'outages:ingest
        {--dry-run : Parse and preview without publishing}
        {--fixture= : Process a local email body fixture instead of IMAP}
        {--message-id= : Optional Message-ID when using --fixture}
        {--retry-pending : Publish previously parsed outages waiting in pending status}';

    protected $description = 'Ingest Elektro Doboj planned outage emails and publish power_outage posts';

    public function handle(OutageIngestService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $retryPending = (bool) $this->option('retry-pending');
        $fixture = $this->option('fixture');

        if ($fixture) {
            if (! is_readable($fixture)) {
                $this->error("Fixture nije čitljiv: {$fixture}");

                return self::FAILURE;
            }

            $result = $service->processMessage(
                messageId: (string) ($this->option('message-id') ?: 'fixture-'.basename($fixture)),
                subject: 'Обавјештење о планираним прекидима',
                from: (string) (config('outages.from') ?: 'noreply@elektrodoboj.net'),
                body: (string) file_get_contents($fixture),
                dryRun: $dryRun,
                retryPending: $retryPending,
            );

            $this->renderResult($result, $dryRun);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        if (! config('outages.imap.host') || ! config('outages.imap.username')) {
            if ($retryPending && ! $dryRun) {
                $results = $service->publishPendingIngestions();
                $this->renderPendingRetrySummary($results);

                return collect($results)->every(fn (array $result) => $result['ok']) ? self::SUCCESS : self::FAILURE;
            }

            $this->error('IMAP nije konfigurisan. Postavite OUTAGE_IMAP_* u .env ili koristite --fixture.');

            return self::FAILURE;
        }

        if ($retryPending && ! $dryRun && ! config('outages.auto_publish', true)) {
            $this->warn('OUTAGE_AUTO_PUBLISH=false — pending zapisi neće biti objavljeni dok ne uključite auto publish.');
        }

        $payload = $service->ingestFromMailbox($dryRun, $retryPending);
        $results = $payload['results'];

        $this->line(sprintf(
            'Skenirano poruka: %d | Prošlo filter: %d | Obrađeno: %d | Auto publish: %s',
            $payload['scanned'],
            $payload['matched'],
            count($results),
            config('outages.auto_publish', true) ? 'DA' : 'NE',
        ));
        $this->newLine();

        if ($results === []) {
            $this->info('Nema novih relevantnih poruka za obradu.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $this->renderResult($result, $dryRun);
            $this->newLine();
        }

        return collect($results)->every(fn (array $result) => $result['ok']) ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<array<string, mixed>> $results */
    private function renderPendingRetrySummary(array $results): void
    {
        if ($results === []) {
            $this->info('Nema pending prekida za objavu.');

            return;
        }

        foreach ($results as $result) {
            $this->renderResult($result, false);
            $this->newLine();
        }
    }

    /** @param array<string, mixed> $result */
    private function renderResult(array $result, bool $dryRun): void
    {
        if (! ($result['ok'] ?? false)) {
            $this->error('[FAIL] '.($result['message_id'] ?? 'unknown'));
            if (! empty($result['failure_reason'])) {
                $this->line('Razlog: '.$result['failure_reason']);
            }

            return;
        }

        if (($result['status'] ?? '') === PowerOutageIngestion::STATUS_SKIPPED_DUPLICATE) {
            $this->warn('[SKIP] Duplikat #'.($result['external_id'] ?? '?').' ('.($result['message_id'] ?? '').')');

            return;
        }

        if (($result['status'] ?? '') === PowerOutageIngestion::STATUS_SKIPPED_FILTER) {
            $this->warn('[SKIP] Filter ('.($result['message_id'] ?? '').')');

            return;
        }

        if (($result['status'] ?? '') === PowerOutageIngestion::STATUS_PENDING) {
            $this->warn('[PENDING] Elektro Doboj #'.($result['external_id'] ?? '?').' — parsirano, ali nije objavljeno.');
            $this->line('Razlog: OUTAGE_AUTO_PUBLISH=false');
            $this->line('Naslov: '.($result['title'] ?? ''));

            return;
        }

        $this->info('[OK] Elektro Doboj #'.($result['external_id'] ?? '?'));
        $this->line('');
        $this->line('Datum:        '.($result['date'] ?? ''));
        $this->line('Vrijeme:      '.($result['time'] ?? ''));
        $this->line('TJ:           '.($result['field_unit'] ?? ''));
        $this->line('Lokacija:     '.($result['locations_raw'] ?? ''));
        $this->line('Trafo:        '.($result['transformer_area'] ?? ''));
        $this->line('Mjesta:       '.($result['places'] ?? ''));
        $this->line('Miloševac:    '.($result['milosevac'] ?? 'NE'));
        $this->line('');
        $this->line('Naslov:');
        $this->line($result['title'] ?? '');
        $this->line('');

        if ($dryRun || ($result['status'] ?? '') === 'dry_run') {
            $this->comment('POST NIJE OBJAVLJEN (--dry-run)');
        } else {
            $this->comment('Objavljeno: /clanak/'.($result['slug'] ?? ''));
        }
    }
}
