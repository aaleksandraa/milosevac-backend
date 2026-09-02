<?php

namespace App\Support\Atlas\Import;

use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementLandCoverClass;
use App\Models\SettlementSurfaceProfile;
use App\Support\Atlas\LandCover\ClcNomenclature;
use App\Support\Atlas\SourceAuthority;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class LandcoverImportService
{
    public const IMPORTER_VERSION = 'm10.1';

    public const IMPORT_TYPE = 'pilot_settlement_landcover';

    public const ALGORITHM_VERSION = 'm10.1';

    /** @var array<string, array<string, mixed>> */
    private const PRODUCT_SOURCES = [
        ClcNomenclature::PRODUCT_CLC => [
            'name' => 'Copernicus CORINE Land Cover 2018',
            'source_type' => 'copernicus_clc_2018',
            'url' => 'https://land.copernicus.eu/en/products/corine-land-cover/clc2018',
            'doi' => 'https://doi.org/10.2909/71c95a07-e296-44fc-b22b-415f42acfdf0',
            'dataset_version' => 'v2020_20u1',
            'attribution' => 'CORINE Land Cover 2018, Copernicus Land Monitoring Service / EEA. Generalizovani inventar pokrova, ne katastar i ne stanje iz tekuće godine.',
        ],
        ClcNomenclature::PRODUCT_TCD => [
            'name' => 'Copernicus HRL Tree Cover Density 2018',
            'source_type' => 'copernicus_hrl_tcd_2018',
            'url' => 'https://land.copernicus.eu/en/products/high-resolution-layer-tree-cover-density/tree-cover-density-2018',
            'doi' => 'https://doi.org/10.2909/e677441e-fb94-431c-b4f9-304f10e4dfd8',
            'dataset_version' => '2018',
            'attribution' => 'HRL Tree Cover Density 2018, Copernicus Land Monitoring Service. Gustoća krošnji 0–100, nije CLC udio šume.',
        ],
        ClcNomenclature::PRODUCT_IMD => [
            'name' => 'Copernicus HRL Imperviousness Density 2018',
            'source_type' => 'copernicus_hrl_imd_2018',
            'url' => 'https://land.copernicus.eu/en/products/high-resolution-layer-imperviousness/imperviousness-density-2018',
            'doi' => 'https://doi.org/10.2909/3bf542bd-eebd-4d73-b53c-a0243f2ed862',
            'dataset_version' => '2018',
            'attribution' => 'HRL Imperviousness Density 2018, Copernicus Land Monitoring Service. Gustoća nepropusnosti, nije binarni otisak zgrada.',
        ],
        ClcNomenclature::PRODUCT_WAW => [
            'name' => 'Copernicus HRL Water and Wetness 2018',
            'source_type' => 'copernicus_hrl_waw_2018',
            'url' => 'https://land.copernicus.eu/en/products/high-resolution-layer-water-and-wetness/water-and-wetness-2018',
            'doi' => 'https://sdi.eea.europa.eu/catalogue/srv/api/records/0dbafef2-b481-4993-8d7e-63ad74934559',
            'dataset_version' => '2018',
            'attribution' => 'HRL Water and Wetness 2018, Copernicus Land Monitoring Service. Nije riječna mreža, poplavna zona niti sliv.',
        ],
        ClcNomenclature::PRODUCT_FTY => [
            'name' => 'Copernicus HRL Forest Type 2018',
            'source_type' => 'copernicus_hrl_fty_2018',
            'url' => 'https://land.copernicus.eu/en/products/high-resolution-layer-forest-type/forest-type-2018',
            'doi' => 'https://sdi.eea.europa.eu/catalogue/srv/api/records/59b0620c-7bb4-4c82-b3ce-f16715573137',
            'dataset_version' => '2018',
            'attribution' => 'HRL Forest Type 2018, Copernicus Land Monitoring Service. Šumska maska (FAO-like), nije gustoća krošnji.',
        ],
    ];

    /**
     * @param  list<string>|null  $municipalitySlugs
     */
    public function import(string $path, ?array $municipalitySlugs = null, bool $dryRun = false): LandcoverImportResult
    {
        $result = new LandcoverImportResult;

        try {
            $payload = $this->read($path);
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());

            return $result;
        }

        $result->artifactHash = is_string($payload['artifact_sha256'] ?? null)
            ? $payload['artifact_sha256']
            : hash_file('sha256', $path);
        $logSource = $this->upsertProductSource(ClcNomenclature::PRODUCT_CLC, is_array($payload['source'] ?? null) ? $payload['source'] : []);
        $import = $this->startImportLog($logSource, $path, $municipalitySlugs, $dryRun, $payload);
        $records = $payload['records'] ?? [];
        if (! is_array($records)) {
            $result->addError('Land-cover JSON nema records.');
            $this->finishImportLog($import, $result, $dryRun, $payload);

            return $result;
        }

        try {
            DB::transaction(function () use ($records, $payload, $municipalitySlugs, $dryRun, $result) {
                foreach ($records as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $result->recordsRead++;
                    $this->persistSettlement($row, is_array($payload['source'] ?? null) ? $payload['source'] : [], $municipalitySlugs, $dryRun, $result);
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
     * @param  array<string, mixed>  $sourceMeta
     * @param  list<string>|null  $municipalitySlugs
     */
    private function persistSettlement(
        array $row,
        array $sourceMeta,
        ?array $municipalitySlugs,
        bool $dryRun,
        LandcoverImportResult $result,
    ): void {
        $settlement = GeoSettlement::query()
            ->with('municipality:id,slug')
            ->find($row['settlement_id'] ?? 0);

        if ($settlement === null) {
            $result->invalid++;
            $result->addWarning('Land-cover red bez naselja: '.($row['settlement_id'] ?? '?'));

            return;
        }

        $slug = $settlement->municipality?->slug;
        if ($municipalitySlugs !== null && $slug !== null && ! in_array($slug, $municipalitySlugs, true)) {
            $result->skipped++;

            return;
        }

        $profiles = $row['profiles'] ?? [];
        if (($row['status'] ?? '') !== 'ok' || ! is_array($profiles) || $profiles === []) {
            $result->unavailable++;

            return;
        }

        $any = false;
        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $any = true;
            $this->persistProfile($settlement, $profile, $sourceMeta, $dryRun, $result);
        }
        if (! $any) {
            $result->unavailable++;
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $sourceMeta
     */
    private function persistProfile(
        GeoSettlement $settlement,
        array $profile,
        array $sourceMeta,
        bool $dryRun,
        LandcoverImportResult $result,
    ): void {
        $product = (string) ($profile['product_code'] ?? '');
        if (! isset(self::PRODUCT_SOURCES[$product])) {
            $result->invalid++;
            $result->addWarning("Nepoznat land-cover proizvod za {$settlement->slug}: {$product}");

            return;
        }

        $quality = (string) ($profile['quality_flag'] ?? SettlementSurfaceProfile::QUALITY_UNAVAILABLE);
        if (in_array($quality, [
            SettlementSurfaceProfile::QUALITY_UNAVAILABLE,
            SettlementSurfaceProfile::QUALITY_INSUFFICIENT,
            SettlementSurfaceProfile::QUALITY_INCONSISTENT,
            SettlementSurfaceProfile::QUALITY_UNSUPPORTED,
        ], true)) {
            $result->unavailable++;

            return;
        }

        $classes = is_array($profile['classes'] ?? null) ? $profile['classes'] : [];
        if ($product === ClcNomenclature::PRODUCT_CLC) {
            if ($classes === []) {
                $result->invalid++;
                $result->addWarning("CLC profil {$settlement->slug} nema razrede.");

                return;
            }
            $shareSum = 0.0;
            foreach ($classes as $classRow) {
                if (! is_array($classRow) || ($classRow['class_code'] ?? '') === '' || ! isset($classRow['area_km2'])) {
                    $result->invalid++;
                    $result->addWarning("CLC razred nije validan za {$settlement->slug}.");

                    return;
                }
                $shareSum += (float) ($classRow['share_pct'] ?? 0);
                $mapped = ClcNomenclature::atlasGroup((string) $classRow['class_code']);
                $given = $classRow['atlas_group'] ?? null;
                if ($mapped !== null && $given !== null && $mapped !== $given) {
                    $result->invalid++;
                    $result->addWarning("CLC grupa ne odgovara kodu {$classRow['class_code']} za {$settlement->slug}.");

                    return;
                }
            }
            if (! ClcNomenclature::sharesSumOk($shareSum)) {
                $result->invalid++;
                $result->addWarning("CLC zbir udjela izvan tolerancije za {$settlement->slug} ({$shareSum}).");

                return;
            }
        }

        $source = $this->upsertProductSource($product, is_array($sourceMeta[$product] ?? null) ? $sourceMeta[$product] : $sourceMeta);
        $algorithm = (string) ($profile['algorithm_version'] ?? self::ALGORITHM_VERSION);
        $existing = SettlementSurfaceProfile::query()
            ->where('settlement_id', $settlement->id)
            ->where('source_id', $source->id)
            ->where('product_code', $product)
            ->where('algorithm_version', $algorithm)
            ->with('classes')
            ->first();

        $payload = [
            'settlement_id' => $settlement->id,
            'source_id' => $source->id,
            'boundary_id' => $profile['boundary_id'] ?? null,
            'product_code' => $product,
            'reference_year' => (int) ($profile['reference_year'] ?? 2018),
            'algorithm_version' => $algorithm,
            'processing_method' => (string) ($profile['processing_method'] ?? ''),
            'source_resolution_m' => isset($profile['source_resolution_m']) ? (int) $profile['source_resolution_m'] : null,
            'total_analysis_area_km2' => $this->nullableNumber($profile['total_analysis_area_km2'] ?? null),
            'valid_area_km2' => $this->nullableNumber($profile['valid_area_km2'] ?? null),
            'nodata_area_km2' => $this->nullableNumber($profile['nodata_area_km2'] ?? null),
            'quality_flag' => $quality,
            'summary' => is_array($profile['summary'] ?? null) ? $profile['summary'] : null,
            'metadata' => is_array($profile['metadata'] ?? null) ? $profile['metadata'] : null,
            'calculated_at' => $profile['calculated_at'] ?? now(),
        ];

        if ($existing && $this->samePayload($existing, $payload, $classes)) {
            $result->unchanged++;

            return;
        }

        if ($dryRun) {
            $existing ? $result->updated++ : $result->inserted++;

            return;
        }

        if ($existing) {
            $existing->fill($payload)->save();
            $saved = $existing;
            $result->updated++;
        } else {
            $saved = SettlementSurfaceProfile::query()->create($payload);
            $result->inserted++;
        }

        $this->syncClasses($saved, $classes);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $classes
     */
    private function samePayload(SettlementSurfaceProfile $existing, array $payload, array $classes): bool
    {
        if ($existing->quality_flag !== $payload['quality_flag']
            || $existing->processing_method !== $payload['processing_method']
            || (int) $existing->reference_year !== (int) $payload['reference_year']
            || ! $this->sameNumber($existing->valid_area_km2, $payload['valid_area_km2'])
            || json_encode($existing->summary) !== json_encode($payload['summary'])
        ) {
            return false;
        }

        $existingClasses = $existing->classes
            ->sortBy('class_code')
            ->map(fn (SettlementLandCoverClass $row) => [
                'class_code' => $row->class_code,
                'area_km2' => round((float) $row->area_km2, 6),
                'share_pct' => round((float) $row->share_pct, 4),
            ])
            ->values()
            ->all();
        $incoming = collect($classes)
            ->sortBy('class_code')
            ->map(fn (array $row) => [
                'class_code' => (string) $row['class_code'],
                'area_km2' => round((float) $row['area_km2'], 6),
                'share_pct' => round((float) ($row['share_pct'] ?? 0), 4),
            ])
            ->values()
            ->all();

        return $existingClasses === $incoming;
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     */
    private function syncClasses(SettlementSurfaceProfile $profile, array $classes): void
    {
        $keep = [];
        foreach ($classes as $row) {
            if (! is_array($row) || ($row['class_code'] ?? '') === '') {
                continue;
            }
            $code = (string) $row['class_code'];
            $keep[] = $code;
            SettlementLandCoverClass::query()->updateOrCreate(
                [
                    'surface_profile_id' => $profile->id,
                    'class_code' => $code,
                ],
                [
                    'source_class_code' => (string) ($row['source_class_code'] ?? $code),
                    'source_class_label' => (string) ($row['source_class_label'] ?? $code),
                    'atlas_group' => $row['atlas_group'] ?? ClcNomenclature::atlasGroup($code),
                    'area_km2' => (float) $row['area_km2'],
                    'share_pct' => (float) ($row['share_pct'] ?? 0),
                    'pixel_count' => isset($row['pixel_count']) ? (int) $row['pixel_count'] : null,
                    'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : null,
                ],
            );
        }
        SettlementLandCoverClass::query()
            ->where('surface_profile_id', $profile->id)
            ->whereNotIn('class_code', $keep === [] ? [''] : $keep)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $sourceMeta
     */
    private function upsertProductSource(string $product, array $sourceMeta): DataSource
    {
        $defaults = self::PRODUCT_SOURCES[$product];

        return DataSource::query()->updateOrCreate(
            ['name' => $defaults['name']],
            [
                'provider' => $sourceMeta['provider'] ?? 'European Environment Agency / Copernicus Land Monitoring Service',
                'source_class' => DataSourceClass::ReputablePublic,
                'authority_rank' => SourceAuthority::SCIENTIFIC_PUBLIC,
                'source_type' => $defaults['source_type'],
                'url' => $sourceMeta['catalogue'] ?? $sourceMeta['url'] ?? $defaults['url'],
                'license' => $sourceMeta['licence'] ?? 'Copernicus Land Monitoring Service data policy',
                'attribution' => $defaults['attribution'],
                'dataset_version' => $sourceMeta['version'] ?? $defaults['dataset_version'],
                'metadata' => [
                    'used_for' => ['pilot_settlement_landcover'],
                    'product_code' => $product,
                    'doi' => $sourceMeta['doi'] ?? $defaults['doi'],
                    'retrieval_date' => $sourceMeta['retrieval_date'] ?? null,
                    'sha256' => $sourceMeta['sha256'] ?? null,
                    'crs' => $sourceMeta['crs'] ?? 'EPSG:3035',
                    'resolution_m' => $sourceMeta['resolution_m'] ?? null,
                    'notes' => $sourceMeta['thematic'] ?? $defaults['attribution'],
                ],
            ],
        );
    }

    private function sameNumber(mixed $left, mixed $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }
        if ($left === null || $right === null) {
            return false;
        }

        return abs((float) $left - (float) $right) < 0.00015;
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
            throw new RuntimeException("Land-cover JSON nije čitljiv: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Land-cover JSON nije validan: {$path}");
        }

        return $decoded;
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
                'reference_year' => 2018,
                'artifact_sha256' => $payload['artifact_sha256'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finishImportLog(DataImport $import, LandcoverImportResult $result, bool $dryRun, array $payload): void
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
                'reference_year' => 2018,
            ]),
        ])->save();
    }
}
