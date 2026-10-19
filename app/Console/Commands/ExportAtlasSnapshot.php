<?php

namespace App\Console\Commands;

use App\Support\Atlas\Snapshot\AtlasSnapshotService;
use Illuminate\Console\Command;

class ExportAtlasSnapshot extends Command
{
    protected $signature = 'atlas:export-snapshot {--output= : Target .ndjson.gz file}';

    protected $description = 'Export only atlas tables (IDs preserved) for transfer to another database';

    public function handle(AtlasSnapshotService $snapshots): int
    {
        $path = (string) ($this->option('output') ?: storage_path('app/private/atlas/atlas-snapshot.ndjson.gz'));
        try {
            $result = $snapshots->export($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(['Table', 'Rows'], collect($result['counts'])->map(fn ($n, $t) => [$t, $n])->values());
        $this->info("Written {$path} (".number_format($result['bytes']).' bytes)');

        return self::SUCCESS;
    }
}
