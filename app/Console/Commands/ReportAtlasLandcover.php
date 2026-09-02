<?php

namespace App\Console\Commands;

use App\Models\GeoSettlement;
use App\Models\SettlementSurfaceProfile;
use App\Support\Atlas\LandCover\ClcNomenclature;
use Illuminate\Console\Command;

class ReportAtlasLandcover extends Command
{
    protected $signature = 'atlas:report-landcover
        {--pilot : Restrict to Modriča and Šamac}
        {--json : Print JSON instead of a table}';

    protected $description = 'QA table of settlement land-cover profiles';

    public function handle(): int
    {
        $query = GeoSettlement::query()
            ->with([
                'municipality:id,slug,name',
                'boundaries',
                'surfaceProfiles.source',
                'surfaceProfiles.classes',
            ])
            ->orderBy('municipality_id')
            ->orderBy('name');
        if ($this->option('pilot')) {
            $query->whereHas('municipality', fn ($builder) => $builder->whereIn('slug', ['modrica', 'samac']));
        }

        $rows = $query->get()->map(function (GeoSettlement $settlement) {
            $boundary = $settlement->boundaries->firstWhere('geometry_valid', true);
            $clc = $settlement->surfaceProfiles->firstWhere('product_code', ClcNomenclature::PRODUCT_CLC);
            $tcd = $settlement->surfaceProfiles->firstWhere('product_code', ClcNomenclature::PRODUCT_TCD);
            $imd = $settlement->surfaceProfiles->firstWhere('product_code', ClcNomenclature::PRODUCT_IMD);
            $waw = $settlement->surfaceProfiles->firstWhere('product_code', ClcNomenclature::PRODUCT_WAW);
            $summary = $clc?->summary ?? [];
            $dominant = is_array($summary['dominant_class'] ?? null) ? $summary['dominant_class'] : [];

            return [
                'municipality' => $settlement->municipality?->name,
                'settlement' => $settlement->name,
                'boundary_id' => $boundary?->id,
                'clc_status' => $clc?->quality_flag ?? 'unavailable',
                'analyzed_area_km2' => $clc?->valid_area_km2,
                'dominant_class' => $dominant['code'] ?? null,
                'artificial_pct' => $summary['landcover_artificial_pct'] ?? null,
                'agricultural_pct' => $summary['landcover_agricultural_pct'] ?? null,
                'forest_pct' => $summary['landcover_forest_pct'] ?? null,
                'seminatural_pct' => $summary['landcover_seminatural_pct'] ?? null,
                'wetland_pct' => $summary['landcover_wetland_pct'] ?? null,
                'water_pct' => $summary['landcover_water_pct'] ?? null,
                'tree_cover_mean_pct' => $tcd?->summaryValue('tree_cover_mean_pct'),
                'imperviousness_mean_pct' => $imd?->summaryValue('imperviousness_mean_pct'),
                'water_wetness_pct' => $waw?->summaryValue('water_wetness_pct'),
                'quality_flag' => $clc?->quality_flag ?? ($boundary ? 'unavailable' : 'unavailable'),
            ];
        });

        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(array_keys($rows->first() ?? ['settlement' => null]), $rows->map(fn ($row) => array_values($row))->all());
        $this->line('Profiles: '.SettlementSurfaceProfile::query()->count());

        return self::SUCCESS;
    }
}
