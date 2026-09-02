<?php

namespace App\Console\Commands;

use App\Support\Atlas\Import\HistoricalPopulationImportService;
use Illuminate\Console\Command;

class ImportAtlasHistoricalPopulation extends Command
{
    protected $signature = 'atlas:import-historical-population
        {--pilot : Import historical census population for Modriča and Šamac}
        {--municipality= : Municipality slug}
        {--from-file= : Path to normalized historical census JSON}
        {--mapping-file= : Path to checked-in mapping JSON}
        {--dry-run : Validate without writing}';

    protected $description = 'Import 1971/1981/1991 settlement population via explicit historical identity mappings';

    public function handle(HistoricalPopulationImportService $service): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $file = $this->option('from-file') ?: database_path('imports/atlas/census-historical-pilot.json');
        $mapping = $this->option('mapping-file') ?: null;
        $result = $service->import($file, is_string($mapping) && $mapping !== '' ? $mapping : null, $slugs, (bool) $this->option('dry-run'));

        $this->line('Municipality: '.implode(', ', $slugs));
        $this->line('Read: '.$result->recordsRead);
        $this->line('Mapped exact: '.$result->mappedExact);
        $this->line('Mapped with caveat: '.$result->mappedWithCaveat);
        $this->line('Ambiguous: '.$result->ambiguous);
        $this->line('Unmatched: '.$result->unmatched);
        $this->line('Inserted: '.$result->inserted);
        $this->line('Updated: '.$result->updated);
        $this->line('Unchanged: '.$result->unchanged);
        $this->line('Rejected: '.$result->rejected);
        $this->line('Conflicts: '.$result->conflicts);
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
