<?php

namespace App\Console\Commands;

use App\Support\Atlas\Import\ClimateImportService;
use Illuminate\Console\Command;

class ImportAtlasClimate extends Command
{
    protected $signature = 'atlas:import-climate
        {--pilot : Import Modriča and Šamac climate profiles}
        {--municipality= : Municipality slug}
        {--from-file= : Path to processed climate JSON}
        {--dry-run : Validate without writing}';

    protected $description = 'Import offline ERA5-Land/ERA5 settlement climate profiles';

    public function handle(ClimateImportService $service): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $file = $this->option('from-file') ?: storage_path('app/private/atlas/climate/climate-pilot.json');
        $result = $service->import($file, $slugs, (bool) $this->option('dry-run'));

        $this->line('Municipality: '.implode(', ', $slugs));
        $this->line('Read: '.$result->recordsRead);
        $this->line('Inserted: '.$result->inserted);
        $this->line('Updated: '.$result->updated);
        $this->line('Unchanged: '.$result->unchanged);
        $this->line('Unavailable: '.$result->unavailable);
        $this->line('Invalid: '.$result->invalid);
        $this->line('Skipped: '.$result->skipped);
        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($result->errors as $error) {
            $this->error($error);
        }
        if ($this->option('dry-run')) {
            $this->comment('DRY-RUN: izmjene nisu upisane.');
        }

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function municipalitySlugs(): array
    {
        if ($this->option('pilot')) {
            return ['modrica', 'samac'];
        }
        $municipality = trim((string) $this->option('municipality'));

        return $municipality === '' ? [] : [$municipality];
    }
}
