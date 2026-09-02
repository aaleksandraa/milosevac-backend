<?php

namespace App\Support\Atlas\Import;

use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementClimateExtreme;
use App\Models\SettlementClimateMonth;
use App\Models\SettlementClimateProfile;
use App\Support\Atlas\SourceAuthority;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ClimateImportService
{
    public const IMPORTER_VERSION = 'm7.1';

    public const IMPORT_TYPE = 'pilot_settlement_climate';

    public const ALGORITHM_VERSION = 'm7.1';

    /**
     * @param  list<string>|null  $municipalitySlugs
     */
    public function import(string $path, ?array $municipalitySlugs = null, bool $dryRun = false): ClimateImportResult
    {
        $result = new ClimateImportResult;

        try {
            $payload = $this->read($path);
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());

            return $result;
        }

        $result->artifactHash = is_string($payload['artifact_sha256'] ?? null) ? $payload['artifact_sha256'] : hash_file('sha256', $path);
        $source = $this->upsertClimateSource($payload['source'] ?? []);
        $import = $this->startImportLog($source, $path, $municipalitySlugs, $dryRun, $payload);
        $records = $payload['records'] ?? [];
        if (! is_array($records)) {
            $result->addError('Climate JSON nema records.');
            $this->finishImportLog($import, $result, $dryRun, $payload);

            return $result;
        }

        try {
            DB::transaction(function () use ($records, $source, $municipalitySlugs, $dryRun, $result) {
                foreach ($records as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $result->recordsRead++;
                    $this->persist($row, $source, $municipalitySlugs, $dryRun, $result);
                }
            });
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());
        }

        $this->finishImportLog($import, $result, $dryRun, $payload);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>|null  $municipalitySlugs
     */
    private function persist(
        array $row,
        DataSource $source,
        ?array $municipalitySlugs,
        bool $dryRun,
        ClimateImportResult $result,
    ): void {
        $settlement = GeoSettlement::query()
            ->with('municipality:id,slug')
            ->find($row['settlement_id'] ?? 0);

        if ($settlement === null) {
            $result->invalid++;
            $result->addWarning('Climate red bez naselja: '.($row['settlement_id'] ?? '?'));

            return;
        }

        $slug = $settlement->municipality?->slug;
        if ($municipalitySlugs !== null && $slug !== null && ! in_array($slug, $municipalitySlugs, true)) {
            $result->skipped++;

            return;
        }

        if (($row['status'] ?? '') !== 'ok' || ($row['quality_flag'] ?? '') === SettlementClimateProfile::QUALITY_UNAVAILABLE) {
            $result->unavailable++;

            return;
        }

        $monthly = $row['monthly'] ?? [];
        $annual = $row['annual'] ?? [];
        if (! is_array($monthly) || count($monthly) !== 12 || ! is_array($annual)) {
            $result->invalid++;
            $result->addWarning("Climate profil {$settlement->slug} nema 12 mjeseci.");

            return;
        }

        foreach ($monthly as $monthRow) {
            if (! is_array($monthRow) || (int) ($monthRow['month'] ?? 0) < 1 || (int) ($monthRow['month'] ?? 0) > 12) {
                $result->invalid++;
                $result->addWarning("Climate mjesec nije 1–12 za {$settlement->slug}.");

                return;
            }
        }

        $algorithm = (string) ($row['algorithm_version'] ?? self::ALGORITHM_VERSION);
        $normalStart = (string) ($row['normal_period_start'] ?? '1991-01-01');
        $normalEnd = (string) ($row['normal_period_end'] ?? '2020-12-31');

        $existing = SettlementClimateProfile::query()
            ->where('settlement_id', $settlement->id)
            ->where('source_id', $source->id)
            ->where('algorithm_version', $algorithm)
            ->whereDate('normal_period_start', $normalStart)
            ->whereDate('normal_period_end', $normalEnd)
            ->with(['monthly', 'extremes'])
            ->first();

        $payload = [
            'settlement_id' => $settlement->id,
            'source_id' => $source->id,
            'boundary_id' => $row['boundary_id'] ?? null,
            'normal_period_start' => $normalStart,
            'normal_period_end' => $normalEnd,
            'historical_period_start' => $row['historical_period_start'] ?? null,
            'historical_period_end' => $row['historical_period_end'] ?? null,
            'spatial_method' => (string) ($row['spatial_method'] ?? 'area_weighted_grid_intersection'),
            'source_resolution_km' => (float) ($row['source_resolution_km'] ?? 9.0),
            'grid_cell_count' => (int) ($row['grid_cell_count'] ?? 0),
            'temperature_mean_annual_c' => $this->nullableNumber($annual['temperature_mean_c'] ?? null),
            'precipitation_annual_mm' => $this->nullableNumber($annual['precipitation_mm'] ?? null),
            'coldest_month' => isset($annual['coldest_month']) ? (int) $annual['coldest_month'] : null,
            'warmest_month' => isset($annual['warmest_month']) ? (int) $annual['warmest_month'] : null,
            'wettest_month' => isset($annual['wettest_month']) ? (int) $annual['wettest_month'] : null,
            'driest_month' => isset($annual['driest_month']) ? (int) $annual['driest_month'] : null,
            'temperature_range_annual_c' => $this->nullableNumber($annual['temperature_range_c'] ?? null),
            'temperature_mean_1961_1990_c' => $this->nullableNumber($row['temperature_mean_1961_1990_c'] ?? null),
            'temperature_mean_change_c' => $this->nullableNumber($row['temperature_mean_change_c'] ?? null),
            'indices' => is_array($row['indices'] ?? null) ? $row['indices'] : null,
            'algorithm_version' => $algorithm,
            'quality_flag' => (string) ($row['quality_flag'] ?? SettlementClimateProfile::QUALITY_COARSE),
            'calculated_at' => $row['calculated_at'] ?? now(),
            'metadata' => $row['metadata'] ?? null,
        ];

        $extremes = is_array($row['extremes'] ?? null) ? $row['extremes'] : [];

        if ($existing && $this->samePayload($existing, $payload, $monthly, $extremes)) {
            $result->unchanged++;

            return;
        }

        if ($dryRun) {
            $existing ? $result->updated++ : $result->inserted++;

            return;
        }

        if ($existing) {
            $existing->fill($payload)->save();
            $profile = $existing;
            $result->updated++;
        } else {
            $profile = SettlementClimateProfile::query()->create($payload);
            $result->inserted++;
        }

        $this->syncMonthly($profile, $monthly);
        $this->syncExtremes($profile, $extremes);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $monthly
     * @param  list<array<string, mixed>>  $extremes
     */
    private function samePayload(SettlementClimateProfile $existing, array $payload, array $monthly, array $extremes): bool
    {
        if (! $this->sameNumber($existing->temperature_mean_annual_c, $payload['temperature_mean_annual_c'])
            || ! $this->sameNumber($existing->precipitation_annual_mm, $payload['precipitation_annual_mm'])
            || (int) $existing->grid_cell_count !== (int) $payload['grid_cell_count']
            || $existing->quality_flag !== $payload['quality_flag']
            || $existing->algorithm_version !== $payload['algorithm_version']) {
            return false;
        }

        $existingMonths = $existing->monthly->keyBy('month');
        if ($existingMonths->count() !== 12) {
            return false;
        }
        foreach ($monthly as $row) {
            $month = $existingMonths->get((int) $row['month']);
            if ($month === null
                || ! $this->sameNumber($month->temperature_mean_c, $row['temperature_mean_c'] ?? null)
                || ! $this->sameNumber($month->precipitation_mean_mm, $row['precipitation_mean_mm'] ?? null)) {
                return false;
            }
        }

        $existingExtremes = $existing->extremes->keyBy('metric_code');
        if ($existingExtremes->count() !== count($extremes)) {
            return false;
        }
        foreach ($extremes as $row) {
            $current = $existingExtremes->get((string) ($row['metric_code'] ?? ''));
            if ($current === null || ! $this->sameNumber($current->value, $row['value'] ?? null)) {
                return false;
            }
            if ($current->occurred_on?->toDateString() !== ($row['occurred_on'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $monthly
     */
    private function syncMonthly(SettlementClimateProfile $profile, array $monthly): void
    {
        $keep = [];
        foreach ($monthly as $row) {
            $month = (int) $row['month'];
            $keep[] = $month;
            SettlementClimateMonth::query()->updateOrCreate(
                ['climate_profile_id' => $profile->id, 'month' => $month],
                [
                    'temperature_mean_c' => $this->nullableNumber($row['temperature_mean_c'] ?? null),
                    'temperature_mean_min_c' => $this->nullableNumber($row['temperature_mean_min_c'] ?? null),
                    'temperature_mean_max_c' => $this->nullableNumber($row['temperature_mean_max_c'] ?? null),
                    'precipitation_mean_mm' => $this->nullableNumber($row['precipitation_mean_mm'] ?? null),
                ],
            );
        }
        SettlementClimateMonth::query()
            ->where('climate_profile_id', $profile->id)
            ->whereNotIn('month', $keep)
            ->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $extremes
     */
    private function syncExtremes(SettlementClimateProfile $profile, array $extremes): void
    {
        $keep = [];
        foreach ($extremes as $row) {
            if (! is_array($row) || empty($row['metric_code'])) {
                continue;
            }
            $code = (string) $row['metric_code'];
            $keep[] = $code;
            SettlementClimateExtreme::query()->updateOrCreate(
                [
                    'climate_profile_id' => $profile->id,
                    'metric_code' => $code,
                    'period_start' => $row['period_start'] ?? $profile->historical_period_start,
                    'period_end' => $row['period_end'] ?? $profile->historical_period_end,
                ],
                [
                    'value' => (float) ($row['value'] ?? 0),
                    'unit' => (string) ($row['unit'] ?? ''),
                    'occurred_on' => $row['occurred_on'] ?? null,
                    'metadata' => [
                        'wording' => $row['wording'] ?? null,
                        'kind' => $row['kind'] ?? 'gridded_reanalysis_extreme',
                    ],
                ],
            );
        }
        SettlementClimateExtreme::query()
            ->where('climate_profile_id', $profile->id)
            ->whereNotIn('metric_code', $keep === [] ? [''] : $keep)
            ->delete();
    }

    private function sameNumber(mixed $left, mixed $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            return false;
        }

        return abs((float) $left - (float) $right) < 0.051;
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Climate JSON nije čitljiv: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Climate JSON nije validan: {$path}");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $sourceMeta
     */
    private function upsertClimateSource(array $sourceMeta): DataSource
    {
        return DataSource::query()->updateOrCreate(
            ['name' => 'Copernicus Climate Change Service — ERA5-Land'],
            [
                'provider' => $sourceMeta['provider'] ?? 'Copernicus Climate Change Service / ECMWF',
                'source_class' => DataSourceClass::ReputablePublic,
                'authority_rank' => SourceAuthority::SCIENTIFIC_PUBLIC,
                'source_type' => 'copernicus_era5_land',
                'url' => $sourceMeta['url'] ?? 'https://cds.climate.copernicus.eu/datasets/reanalysis-era5-land',
                'license' => $sourceMeta['licence'] ?? 'Copernicus C3S/CAMS Licence',
                'attribution' => 'ERA5-Land reanalysis, Copernicus Climate Change Service / ECMWF. Padavine: ERA5 (forsirajuće polje). Nisu mjerene vrijednosti meteorološke stanice.',
                'dataset_version' => $sourceMeta['dataset_version'] ?? 'era5-land-open-meteo-archive',
                'metadata' => [
                    'retrieval_date' => $sourceMeta['retrieval_date'] ?? null,
                    'temperature_doi' => $sourceMeta['temperature_doi'] ?? '10.24381/cds.e2161bac',
                    'precipitation_doi' => $sourceMeta['precipitation_doi'] ?? '10.24381/cds.adbb2d47',
                    'temperature_dataset' => 'ERA5-Land',
                    'precipitation_dataset' => 'ERA5',
                    'retrieval' => $sourceMeta['retrieval'] ?? null,
                    'grid' => $sourceMeta['grid'] ?? 'ERA5-Land 0.1°; ERA5 0.25°',
                    'timezone' => $sourceMeta['timezone'] ?? 'Europe/Sarajevo',
                    'used_for' => ['pilot_settlement_climate'],
                    'notes' => 'Reanaliza/model, nije službeno mjerenje u naselju. Open-Meteo ERA5-Land ne isporučuje padavine; padavine dolaze iz ERA5.',
                ],
            ],
        );
    }

    /**
     * @param  list<string>|null  $municipalitySlugs
     * @param  array<string, mixed>  $payload
     */
    private function startImportLog(DataSource $source, string $path, ?array $municipalitySlugs, bool $dryRun, array $payload): DataImport
    {
        return DataImport::query()->create([
            'data_source_id' => $source->id,
            'import_type' => self::IMPORT_TYPE,
            'status' => DataImportStatus::Running,
            'started_at' => now(),
            'metadata' => [
                'dry_run' => $dryRun,
                'file' => basename($path),
                'municipality_scope' => $municipalitySlugs ?? ['modrica', 'samac'],
                'importer_version' => self::IMPORTER_VERSION,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'source_period' => ($payload['source']['normal_period'] ?? '1991-01-01/2020-12-31'),
                'historical_period' => $payload['source']['historical_period'] ?? null,
                'artifact_sha256' => $payload['artifact_sha256'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finishImportLog(DataImport $import, ClimateImportResult $result, bool $dryRun, array $payload): void
    {
        $import->fill([
            'status' => $result->ok ? DataImportStatus::Completed : DataImportStatus::Failed,
            'finished_at' => now(),
            'records_read' => $result->recordsRead,
            'records_inserted' => $dryRun ? 0 : $result->inserted,
            'records_updated' => $dryRun ? 0 : $result->updated,
            'records_skipped' => $result->skipped + $result->unchanged + $result->unavailable + $result->invalid,
            'errors' => $result->errors === [] ? null : implode("\n", $result->errors),
            'metadata' => array_merge($import->metadata ?? [], [
                'warnings' => $result->warnings,
                'unchanged' => $result->unchanged,
                'unavailable' => $result->unavailable,
                'invalid' => $result->invalid,
                'artifact_sha256' => $result->artifactHash,
                'algorithm_version' => self::ALGORITHM_VERSION,
                'source_period' => $payload['source']['normal_period'] ?? null,
            ]),
        ])->save();
    }
}
