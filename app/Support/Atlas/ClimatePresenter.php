<?php

namespace App\Support\Atlas;

use App\Models\SettlementClimateExtreme;
use App\Models\SettlementClimateMonth;
use App\Models\SettlementClimateProfile;
use App\Support\Atlas\Import\ClimateImportService;

final class ClimatePresenter
{
    public const METHODOLOGY = 'Vrijednosti su izvedene iz ERA5-Land reanalize (Copernicus C3S / ECMWF) za područje naselja, prostornim presjekom poligona i mreže 0.1°. Padavine dolaze iz ERA5 (0.25°), jer je u ERA5-Land padavina forsirajuće atmosfersko polje. To nisu mjerenja meteorološke stanice u naselju niti prognoza. Klimatološki normalni period je 1991–2020. Dnevne granice koriste Europe/Sarajevo.';

    /**
     * @return array<string, mixed>|null
     */
    public function present(?SettlementClimateProfile $profile): ?array
    {
        if ($profile === null || ! $profile->isPubliclyUsable()) {
            return null;
        }

        $monthly = $profile->relationLoaded('monthly')
            ? $profile->monthly->sortBy('month')->values()
            : collect();
        $extremes = $profile->relationLoaded('extremes')
            ? $profile->extremes
            : collect();

        return [
            'normal_period' => '1991–2020',
            'normal_period_start' => $profile->normal_period_start?->toDateString(),
            'normal_period_end' => $profile->normal_period_end?->toDateString(),
            'historical_period_start' => $profile->historical_period_start?->toDateString(),
            'historical_period_end' => $profile->historical_period_end?->toDateString(),
            'annual' => [
                'temperature_mean_c' => $this->decimal($profile->temperature_mean_annual_c, 2),
                'precipitation_mm' => $this->decimal($profile->precipitation_annual_mm, 1),
                'coldest_month' => $profile->coldest_month,
                'warmest_month' => $profile->warmest_month,
                'wettest_month' => $profile->wettest_month,
                'driest_month' => $profile->driest_month,
                'temperature_range_c' => $this->decimal($profile->temperature_range_annual_c, 2),
                'temperature_mean_1961_1990_c' => $this->decimal($profile->temperature_mean_1961_1990_c, 2),
                'temperature_mean_change_c' => $this->decimal($profile->temperature_mean_change_c, 2),
            ],
            'monthly' => $monthly->map(fn (SettlementClimateMonth $row) => [
                'month' => $row->month,
                'temperature_mean_c' => $this->decimal($row->temperature_mean_c, 2),
                'temperature_mean_min_c' => $this->decimal($row->temperature_mean_min_c, 2),
                'temperature_mean_max_c' => $this->decimal($row->temperature_mean_max_c, 2),
                'precipitation_mean_mm' => $this->decimal($row->precipitation_mean_mm, 1),
            ])->values()->all(),
            'indices' => $profile->indices ?? [],
            'extremes' => $extremes->map(fn (SettlementClimateExtreme $row) => [
                'metric_code' => $row->metric_code,
                'value' => $this->decimal($row->value, $row->unit === 'mm' ? 1 : 2),
                'unit' => $row->unit,
                'occurred_on' => $row->occurred_on?->toDateString(),
                'period_start' => $row->period_start?->toDateString(),
                'period_end' => $row->period_end?->toDateString(),
                'kind' => $row->metadata['kind'] ?? 'gridded_reanalysis_extreme',
                'wording' => $row->metadata['wording'] ?? null,
            ])->values()->all(),
            'methodology' => [
                'text' => self::METHODOLOGY,
                'spatial_method' => $profile->spatial_method,
                'source_resolution_km' => $this->decimal($profile->source_resolution_km, 1),
                'grid_cell_count' => $profile->grid_cell_count,
                'algorithm_version' => $profile->algorithm_version ?: ClimateImportService::ALGORITHM_VERSION,
                'quality_flag' => $profile->quality_flag,
                'timezone' => $profile->metadata['timezone'] ?? 'Europe/Sarajevo',
                'temperature_dataset' => $profile->metadata['temperature_dataset'] ?? 'ERA5-Land',
                'precipitation_dataset' => $profile->metadata['precipitation_dataset'] ?? 'ERA5',
            ],
            'source' => $profile->relationLoaded('source') && $profile->source
                ? (new PublicSourcePresenter)->present($profile->source)
                : null,
        ];
    }

    private function decimal(mixed $value, int $digits): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, $digits);
    }
}
