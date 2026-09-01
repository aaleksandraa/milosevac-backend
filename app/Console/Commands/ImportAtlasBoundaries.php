<?php

namespace App\Console\Commands;

use App\Support\Atlas\Import\BoundaryImportService;
use Illuminate\Console\Command;

class ImportAtlasBoundaries extends Command
{
    protected $signature = 'atlas:import-boundaries
        {--pilot : Import Modriča and Šamac pilot boundaries}
        {--municipality= : Municipality slug (modrica, samac)}
        {--file= : Path to boundary GeoJSON}
        {--mapping= : Path to reviewable identity mapping JSON}
        {--dry-run : Match and validate without writing}';

    protected $description = 'Import sourced settlement boundary polygons for the Modriča/Šamac pilot';

    public function handle(BoundaryImportService $service): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $file = $this->option('file') ?: base_path(BoundaryImportService::DEFAULT_GEOJSON);
        $mapping = $this->option('mapping') ?: base_path(BoundaryImportService::DEFAULT_MAPPING);
        $result = $service->import($file, $mapping, $slugs, (bool) $this->option('dry-run'));

        $this->line('Municipality: '.implode(', ', $slugs));
        $this->line('Source: OpenStreetMap admin_level=9 (ODbL), not cadastral');
        $this->line('Features read: '.$result->featuresRead);
        $this->line('Matched settlements: '.$result->matchedSettlements);
        $this->line('Inserted boundaries: '.$result->inserted);
        $this->line('Updated boundaries: '.$result->updated);
        $this->line('Unchanged boundaries: '.$result->unchanged);
        $this->line('Unmatched features: '.$result->unmatchedFeatures);
        $this->line('Settlements without boundaries: '.$result->settlementsWithoutBoundaries);
        $this->line('Invalid geometries: '.$result->invalidGeometries);
        $this->line('Ambiguous matches: '.$result->ambiguousMatches);

        foreach ($result->missingSettlements as $missing) {
            $this->warn('Missing boundary: '.$missing);
        }
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
