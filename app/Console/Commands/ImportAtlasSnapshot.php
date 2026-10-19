<?php

namespace App\Console\Commands;

use App\Support\Atlas\Snapshot\AtlasSnapshotService;
use Illuminate\Console\Command;

class ImportAtlasSnapshot extends Command
{
    protected $signature = 'atlas:import-snapshot
        {--from-file= : Snapshot written by atlas:export-snapshot}
        {--dry-run : Validate and report without writing}
        {--replace : Replace existing rows in atlas tables only}';

    protected $description = 'Import an atlas snapshot in one transaction; non-atlas tables are never touched';

    public function handle(AtlasSnapshotService $snapshots): int
    {
        $path = (string) ($this->option('from-file') ?: storage_path('app/private/atlas/atlas-snapshot.ndjson.gz'));
        if (! is_file($path)) {
            $this->error("Snapshot not found: {$path}");

            return self::FAILURE;
        }
        try {
            $result = $snapshots->import($path, (bool) $this->option('dry-run'), (bool) $this->option('replace'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Table', 'Rows'], collect($result['counts'])->map(fn ($n, $t) => [$t, $n])->values());
        if ($result['unmatched_outage_places']) {
            $this->warn('Outage places not found by slug (link left empty): '.implode(', ', $result['unmatched_outage_places']));
        }
        if ($result['dry_run']) {
            $this->info('Dry run: nothing written.'.($result['replaced'] ? ' Existing atlas rows would be replaced.' : ''));
        } else {
            $this->info('Imported.'.($result['network_hash_matches'] ? ' Network dependency hash verified.' : ''));
        }

        return self::SUCCESS;
    }
}
