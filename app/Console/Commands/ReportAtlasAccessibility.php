<?php

namespace App\Console\Commands;

use App\Models\GeoSettlement;
use App\Support\Atlas\Network\NetworkPresenter;
use Illuminate\Console\Command;

class ReportAtlasAccessibility extends Command
{
    protected $signature = 'atlas:report-accessibility {--pilot} {--json}';

    protected $description = 'Report current M11 pilot profiles, origins and missing targets';

    public function handle(NetworkPresenter $presenter): int
    {
        $rows = GeoSettlement::with(['municipality', 'boundaries', 'transportProfiles', 'hydroProfiles', 'proximities'])->whereHas('municipality', fn ($q) => $q->whereIn('slug', ['modrica', 'samac']))->orderBy('id')->get()->map(fn ($s) => ['municipality' => $s->municipality->name, 'settlement' => $s->name, 'boundary_status' => $s->boundaries->contains('geometry_valid', true) ? 'available' : 'unavailable'] + $presenter->present($s));
        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Municipality', 'Settlement', 'Boundary', 'Road km', 'Major km', 'Density', 'Waterway km', 'Centre m', 'Major road m', 'Pharmacy m', 'Hospital m', 'School m', 'Origin', 'Named watercourses', 'Quality'], $rows->map(fn ($r) => [$r['municipality'], $r['settlement'], $r['boundary_status'], $r['transport']['mapped_road_length_km'] ?? 'missing', $r['transport']['major_road_length_km'] ?? 'missing', $r['transport']['mapped_road_density_km_per_km2'] ?? 'missing', $r['hydrography']['waterway_length_km'] ?? 'missing', ...array_map(fn ($code) => $r['proximity'][$code]['distance_straight_m'] ?? 'missing', ['municipality_centre', 'nearest_major_road', 'nearest_pharmacy', 'nearest_hospital', 'nearest_school']), $r['proximity']['municipality_centre']['origin_type'] ?? 'missing', implode(', ', array_unique(array_column($r['hydrography']['named_features'] ?? [], 'name'))), $r['transport']['quality_flag'] ?? 'boundary_unavailable'])->all());
        }

        return self::SUCCESS;
    }
}
