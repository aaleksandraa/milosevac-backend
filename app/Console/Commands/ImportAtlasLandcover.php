<?php

namespace App\Console\Commands;

use App\Support\Atlas\Import\LandcoverImportService;
use Illuminate\Console\Command;

class ImportAtlasLandcover extends Command
{
    protected $signature = 'atlas:import-landcover
        {--pilot : Import Modriča and Šamac land-cover profiles}
        {--municipality= : Municipality slug}
        {--from-file= : Path to processed land-cover JSON}
        {--dry-run : Validate without writing}';

    protected $description = 'Import offline Copernicus CLC/HRL settlement land-cover profiles';

    public function handle(LandcoverImportService $service): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $file = $this->option('from-file') ?: storage_path('app/private/atlas/landcover/landcover-pilot.json');
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
