<?php

namespace App\Console\Commands;

use App\Support\Atlas\Import\TerrainImportService;
use Illuminate\Console\Command;

class ImportAtlasTerrain extends Command
{
    protected $signature = 'atlas:import-terrain
        {--pilot : Import Modriča and Šamac terrain stats}
        {--municipality= : Municipality slug}
        {--file= : Path to processed terrain JSON}
        {--dry-run : Validate without writing}
        {--force : Recompute even when values match}';

    protected $description = 'Import offline DEM-derived settlement terrain statistics';

    public function handle(TerrainImportService $service): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $file = $this->option('file') ?: storage_path('app/private/atlas/terrain/stats-pilot.json');
        $result = $service->import($file, $slugs, (bool) $this->option('dry-run'), (bool) $this->option('force'));

        $this->line('Municipality: '.implode(', ', $slugs));
        $this->line('Read: '.$result->recordsRead);
        $this->line('Inserted: '.$result->inserted);
        $this->line('Updated: '.$result->updated);
        $this->line('Unchanged: '.$result->unchanged);
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
