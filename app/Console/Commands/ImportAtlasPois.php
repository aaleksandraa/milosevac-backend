<?php

namespace App\Console\Commands;

use App\Support\Atlas\Import\PoiImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportAtlasPois extends Command
{
    protected $signature = 'atlas:import-pois
        {--pilot : Import Modriča and Šamac cached OSM POIs}
        {--municipality= : Municipality slug}
        {--from-file= : Cached Overpass JSON file or directory}
        {--dry-run : Classify and assign without writing}';

    protected $description = 'Import classified OSM POIs into geo_places from a cached Overpass download';

    public function handle(PoiImportService $service): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $paths = $this->sourcePaths($slugs);
        if ($paths === []) {
            $this->error('Nema keširanog Overpass JSON-a. Pokrenite atlas:fetch-osm-pois ili navedite --from-file.');

            return self::FAILURE;
        }

        $result = $service->import($paths, $slugs, (bool) $this->option('dry-run'));

        $this->line('Municipality: '.implode(', ', $slugs));
        $this->line('Files: '.implode(', ', array_map(basename(...), $paths)));
        $this->line('Read: '.$result->recordsRead);
        $this->line('Classified: '.$result->classified);
        $this->line('Unclassified: '.$result->unclassified);
        $this->line('Inserted: '.$result->inserted);
        $this->line('Updated: '.$result->updated);
        $this->line('Unchanged: '.$result->unchanged);
        $this->line('Deactivated: '.$result->deactivated);
        $this->line('Unnamed: '.$result->unnamed);
        $this->line('Municipality assigned: '.$result->municipalityAssigned);
        $this->line('Settlement assigned: '.$result->settlementAssigned);
        $this->line('Ambiguous: '.$result->ambiguous);
        $this->line('Unassigned/skipped: '.$result->unassigned);
        $this->line('Failed: '.$result->failed);
        $this->line('By family: '.json_encode($result->byFamily, JSON_UNESCAPED_UNICODE));
        $this->line('By type: '.json_encode($result->byType, JSON_UNESCAPED_UNICODE));

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }
        foreach ($result->errors as $error) {
            $this->error($error);
        }
        if ($this->option('dry-run')) {
            $this->comment('DRY-RUN: izmjene nisu upisane.');
        }

        $this->writeQaReport($result, $slugs);

        return $result->ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function sourcePaths(array $slugs): array
    {
        $fromFile = trim((string) $this->option('from-file'));
        if ($fromFile !== '') {
            if (is_dir($fromFile)) {
                $files = glob(rtrim($fromFile, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [];
                sort($files);

                return array_values(array_filter($files, 'is_readable'));
            }

            return is_readable($fromFile) ? [$fromFile] : [];
        }

        $paths = [];
        foreach ($slugs as $slug) {
            $candidate = storage_path('app/private/atlas/osm/pilot-'.$slug.'.json');
            if (is_readable($candidate)) {
                $paths[] = $candidate;
            }
        }

        return $paths;
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

    /**
     * @param  list<string>  $slugs
     */
    private function writeQaReport($result, array $slugs): void
    {
        $relative = 'atlas/osm/qa-pilot.json';
        Storage::disk('local')->put($relative, json_encode([
            'generated_at' => now()->toIso8601String(),
            'municipality_scope' => $slugs,
            'importer_version' => PoiImportService::IMPORTER_VERSION,
            'counts' => [
                'read' => $result->recordsRead,
                'classified' => $result->classified,
                'unclassified' => $result->unclassified,
                'inserted' => $result->inserted,
                'updated' => $result->updated,
                'unchanged' => $result->unchanged,
                'unnamed' => $result->unnamed,
                'municipality_assigned' => $result->municipalityAssigned,
                'settlement_assigned' => $result->settlementAssigned,
                'ambiguous' => $result->ambiguous,
                'unassigned' => $result->unassigned,
                'failed' => $result->failed,
                'by_family' => $result->byFamily,
                'by_type' => $result->byType,
            ],
            'unclassified_samples' => $result->unclassifiedSamples,
            'warnings' => $result->warnings,
            'rows' => $result->qaRows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->line('QA report: '.Storage::disk('local')->path($relative));
    }
}
