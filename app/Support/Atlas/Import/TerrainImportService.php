<?php

namespace App\Support\Atlas\Import;

use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\SettlementBoundary;
use App\Models\SettlementTerrainStat;
use App\Support\Atlas\SourceAuthority;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class TerrainImportService
{
    public const IMPORTER_VERSION = 'm4.1';

    public const IMPORT_TYPE = 'pilot_settlement_terrain';

    public const ALGORITHM_VERSION = 'm4.1';

    /**
     * @param  list<string>|null  $municipalitySlugs
     */
    public function import(string $path, ?array $municipalitySlugs = null, bool $dryRun = false, bool $force = false): TerrainImportResult
    {
        $result = new TerrainImportResult;

        try {
            $payload = $this->read($path);
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());

            return $result;
        }

        $source = $this->upsertDemSource($payload['source'] ?? []);
        $import = $this->startImportLog($source, $path, $municipalitySlugs, $dryRun);
        $records = $payload['records'] ?? [];
        if (! is_array($records)) {
            $result->addError('Terrain JSON nema records.');
            $this->finishImportLog($import, $result, $dryRun);

            return $result;
        }

        try {
            DB::transaction(function () use ($records, $source, $municipalitySlugs, $dryRun, $force, $result) {
                foreach ($records as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $result->recordsRead++;
                    $this->persist($row, $source, $municipalitySlugs, $dryRun, $force, $result);
                }
            });
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());
        }

        $this->finishImportLog($import, $result, $dryRun);

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
        bool $force,
        TerrainImportResult $result,
    ): void {
        $boundary = SettlementBoundary::query()
            ->with('settlement.municipality')
            ->find($row['boundary_id'] ?? 0);

        if ($boundary === null) {
            $result->skipped++;
            $result->addWarning('Terrain red bez granice: '.($row['boundary_id'] ?? '?'));

            return;
        }

        $slug = $boundary->settlement?->municipality?->slug;
        if ($municipalitySlugs !== null && $slug !== null && ! in_array($slug, $municipalitySlugs, true)) {
            $result->skipped++;

            return;
        }

        if ((int) ($row['settlement_id'] ?? 0) !== (int) $boundary->settlement_id) {
            $result->skipped++;
            $result->addWarning("Terrain settlement_id ne pripada granici {$boundary->id}.");

            return;
        }

        $algorithm = (string) ($row['algorithm_version'] ?? self::ALGORITHM_VERSION);
        $demVersion = (string) ($row['dem_version'] ?? '');
        if ($demVersion === '') {
            $result->skipped++;
            $result->addWarning("Nedostaje dem_version za granicu {$boundary->id}.");

            return;
        }

        $existing = SettlementTerrainStat::query()
            ->where('settlement_id', $boundary->settlement_id)
            ->where('boundary_id', $boundary->id)
            ->where('dem_version', $demVersion)
            ->where('algorithm_version', $algorithm)
            ->first();

        $min = $this->nullableNumber($row['elevation_min_m'] ?? null);
        $payload = [
            'settlement_id' => $boundary->settlement_id,
            'boundary_id' => $boundary->id,
            'source_id' => $source->id,
            'elevation_min_m' => $min,
            'elevation_max_m' => $this->nullableNumber($row['elevation_max_m'] ?? null),
            'elevation_mean_m' => $this->nullableNumber($row['elevation_mean_m'] ?? null),
            'elevation_median_m' => $this->nullableNumber($row['elevation_median_m'] ?? null),
            'elevation_range_m' => $this->nullableNumber($row['elevation_range_m'] ?? null),
            'slope_mean_deg' => $this->nullableNumber($row['slope_mean_deg'] ?? null),
            'slope_median_deg' => $this->nullableNumber($row['slope_median_deg'] ?? null),
            'slope_max_deg' => $this->nullableNumber($row['slope_max_deg'] ?? null),
            'elevation_min_lat' => $this->nullableNumber($row['elevation_min_lat'] ?? null),
            'elevation_min_lng' => $this->nullableNumber($row['elevation_min_lng'] ?? null),
            'elevation_max_lat' => $this->nullableNumber($row['elevation_max_lat'] ?? null),
            'elevation_max_lng' => $this->nullableNumber($row['elevation_max_lng'] ?? null),
            'dem_source' => (string) ($row['dem_source'] ?? 'Copernicus DEM GLO-30 Public'),
            'dem_version' => $demVersion,
            'dem_resolution_m' => (int) ($row['dem_resolution_m'] ?? 30),
            'sample_count' => (int) ($row['sample_count'] ?? 0),
            'valid_cell_count' => (int) ($row['valid_cell_count'] ?? 0),
            'nodata_cell_count' => (int) ($row['nodata_cell_count'] ?? 0),
            'quality_flag' => (string) ($row['quality_flag'] ?? SettlementTerrainStat::QUALITY_UNAVAILABLE),
            'algorithm_version' => $algorithm,
            'calculated_at' => $row['calculated_at'] ?? now(),
            'metadata' => $row['metadata'] ?? null,
        ];

        if ($min === 0.0) {
            $payload['elevation_min_m'] = 0.0;
        }

        if ($existing && ! $force && $this->samePayload($existing, $payload)) {
            $result->unchanged++;

            return;
        }

        if ($dryRun) {
            $existing ? $result->updated++ : $result->inserted++;

            return;
        }

        if ($existing) {
            $existing->fill($payload)->save();
            $result->updated++;
        } else {
            SettlementTerrainStat::query()->create($payload);
            $result->inserted++;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function samePayload(SettlementTerrainStat $existing, array $payload): bool
    {
        return $this->sameNumber($existing->elevation_min_m, $payload['elevation_min_m'])
            && $this->sameNumber($existing->elevation_max_m, $payload['elevation_max_m'])
            && $this->sameNumber($existing->elevation_mean_m, $payload['elevation_mean_m'])
            && (int) $existing->valid_cell_count === (int) $payload['valid_cell_count']
            && $existing->quality_flag === $payload['quality_flag'];
    }

    private function sameNumber(mixed $left, mixed $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            return false;
        }

        return abs((float) $left - (float) $right) < 0.05;
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
            throw new RuntimeException("Terrain JSON nije čitljiv: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Terrain JSON nije validan: {$path}");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $sourceMeta
     */
    private function upsertDemSource(array $sourceMeta): DataSource
    {
        return DataSource::query()->updateOrCreate(
            ['name' => 'Copernicus DEM GLO-30 Public'],
            [
                'provider' => $sourceMeta['provider'] ?? 'European Space Agency / European Commission',
                'source_class' => DataSourceClass::ReputablePublic,
                'authority_rank' => SourceAuthority::SCIENTIFIC_PUBLIC,
                'source_type' => 'copernicus_dem_glo30',
                'url' => $sourceMeta['url'] ?? 'https://spacedata.copernicus.eu/collections/copernicus-digital-elevation-model',
                'license' => $sourceMeta['license'] ?? 'Copernicus DEM GLO-30 Public licence',
                'attribution' => 'Copernicus DEM GLO-30 Public, ESA/EC. Raster-izvedene visine unutar OSM granice naselja.',
                'dataset_version' => $sourceMeta['dataset_version'] ?? '2021_1',
                'metadata' => [
                    'retrieval_date' => $sourceMeta['retrieval_date'] ?? '2026-09-02',
                    'nominal_resolution_m' => 30,
                    'horizontal_crs' => 'EPSG:4326',
                    'vertical_datum' => 'EGM2008',
                    'notes' => 'Nadmorske visine su raster-izvedene iz Copernicus DEM GLO-30, nisu službeno izmjerene visine naselja.',
                ],
            ],
        );
    }

    /**
     * @param  list<string>|null  $municipalitySlugs
     */
    private function startImportLog(DataSource $source, string $path, ?array $municipalitySlugs, bool $dryRun): DataImport
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
            ],
        ]);
    }

    private function finishImportLog(DataImport $import, TerrainImportResult $result, bool $dryRun): void
    {
        $import->fill([
            'status' => $result->ok ? DataImportStatus::Completed : DataImportStatus::Failed,
            'finished_at' => now(),
            'records_read' => $result->recordsRead,
            'records_inserted' => $dryRun ? 0 : $result->inserted,
            'records_updated' => $dryRun ? 0 : $result->updated,
            'records_skipped' => $result->skipped + $result->unchanged,
            'errors' => $result->errors === [] ? null : implode("\n", $result->errors),
            'metadata' => array_merge($import->metadata ?? [], [
                'warnings' => $result->warnings,
                'unchanged' => $result->unchanged,
            ]),
        ])->save();
    }
}
