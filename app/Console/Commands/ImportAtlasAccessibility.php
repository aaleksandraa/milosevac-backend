<?php

namespace App\Console\Commands;

use App\Support\Atlas\Network\NetworkImportService;
use Illuminate\Console\Command;

class ImportAtlasAccessibility extends Command
{
    protected $signature = 'atlas:import-accessibility {--pilot} {--from-file=}';

    protected $description = 'Import offline normalized OSM network and accessibility artifact transactionally';

    public function handle(NetworkImportService $importer): int
    {
        if (! $this->option('pilot') || ! is_file((string) $this->option('from-file'))) {
            return self::FAILURE;
        }
        try {
            $data = json_decode(file_get_contents($this->option('from-file')), true, 512, JSON_THROW_ON_ERROR);
            $this->info(json_encode($importer->import($data)));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
