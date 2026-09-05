<?php

namespace App\Console\Commands;

use App\Support\Atlas\Network\NetworkSnapshot;
use Illuminate\Console\Command;

class ExportAtlasNetworkJobs extends Command
{
    protected $signature = 'atlas:export-network-jobs {--pilot} {--output=}';

    protected $description = 'Export M4 origins/boundaries and existing M6 targets for offline M11 processing';

    public function handle(): int
    {
        if (! $this->option('pilot')) {
            return self::FAILURE;
        }
        $payload = (new NetworkSnapshot)->payload();
        $path = $this->option('output') ?: storage_path('app/private/atlas/network/jobs-pilot.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info(count($payload['jobs']).' settlements; '.count($payload['pois']).' M6 targets; '.$path);

        return self::SUCCESS;
    }
}
