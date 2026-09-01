<?php

namespace App\Console\Commands;

use App\Support\Atlas\Import\SettlementImportService;
use Illuminate\Console\Command;

class ImportAtlasSettlements extends Command
{
    protected $signature = 'atlas:import-settlements
        {--pilot : Import Modriča and Šamac pilot municipalities}
        {--municipality= : Municipality slug (modrica, samac)}
        {--file= : Path to a normalized census JSON fixture}
        {--dry-run : Parse and match without writing settlements}';

    protected $description = 'Import sourced atlas settlements for the Modriča/Šamac pilot';

    public function handle(SettlementImportService $service): int
    {
        $file = $this->option('file') ?: base_path('database/imports/atlas/census-2013-pilot-settlements.json');
        $slugs = $this->municipalitySlugs();

        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $result = $service->import($file, $slugs, (bool) $this->option('dry-run'));
        $scope = implode(', ', $slugs);

        $this->line('Municipality: '.$scope);
        $this->line('Source: Popis 2013 — naseljena mjesta');
        $this->line('File: '.basename((string) $file));
        $this->line('Read: '.$result->recordsRead);
        $this->line('Inserted: '.$result->recordsInserted);
        $this->line('Updated: '.$result->recordsUpdated);
        $this->line('Skipped: '.$result->recordsSkipped);
        $this->line(sprintf(
            'Outage links: linked %d | not found %d | ambiguous %d',
            $result->outageLinked,
            $result->outageNotFound,
            $result->outageAmbiguous,
        ));

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
        if ($municipality === '') {
            return [];
        }

        return [$municipality];
    }
}
