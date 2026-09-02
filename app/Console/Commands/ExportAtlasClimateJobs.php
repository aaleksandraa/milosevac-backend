<?php

namespace App\Console\Commands;

use App\Models\SettlementBoundary;
use App\Support\Atlas\Import\ClimateImportService;
use Illuminate\Console\Command;

class ExportAtlasClimateJobs extends Command
{
    protected $signature = 'atlas:export-climate-jobs
        {--pilot : Export jobs for Modriča and Šamac}
        {--municipality= : Municipality slug}
        {--output= : Output JSON path}';

    protected $description = 'Export settlement boundary GeoJSON jobs for offline climate processing';

    public function handle(): int
    {
        $slugs = $this->municipalitySlugs();
        if ($slugs === []) {
            $this->error('Navedite --pilot ili --municipality=modrica|samac.');

            return self::FAILURE;
        }

        $output = $this->option('output') ?: storage_path('app/private/atlas/climate/jobs-pilot.json');
        $dir = dirname($output);
        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->error("Ne mogu napraviti direktorij: {$dir}");

            return self::FAILURE;
        }

        $boundaries = SettlementBoundary::query()
            ->where('geometry_valid', true)
            ->whereHas('settlement.municipality', fn ($query) => $query->whereIn('slug', $slugs))
            ->with('settlement.municipality:id,slug,name')
            ->orderBy('id')
            ->get();

        $jobs = $boundaries->map(fn (SettlementBoundary $boundary) => [
            'settlement_id' => $boundary->settlement_id,
            'boundary_id' => $boundary->id,
            'municipality_slug' => $boundary->settlement?->municipality?->slug,
            'settlement_slug' => $boundary->settlement?->slug,
            'settlement_name' => $boundary->settlement?->name,
            'source_feature_id' => $boundary->source_feature_id,
            'boundary_version' => $boundary->boundary_version,
            'algorithm_version' => ClimateImportService::ALGORITHM_VERSION,
            'geometry' => $boundary->geometry(),
        ])->all();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'algorithm_version' => ClimateImportService::ALGORITHM_VERSION,
            'spatial_method' => 'area_weighted_grid_intersection',
            'jobs' => $jobs,
        ];

        file_put_contents($output, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
        $this->line('Jobs: '.count($jobs));
        $this->line('Output: '.$output);

        return self::SUCCESS;
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
