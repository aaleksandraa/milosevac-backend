<?php

namespace App\Support\Atlas\Import;

use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Enums\VerificationStatus;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Support\Atlas\Geometry\GeoJsonPolygon;
use App\Support\Atlas\SourceAuthority;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BoundaryImportService
{
    public const IMPORTER_VERSION = 'm4.1';

    public const IMPORT_TYPE = 'pilot_settlement_boundaries_osm';

    public const DEFAULT_MAPPING = 'database/imports/atlas/osm-pilot-boundary-mapping.json';

    public const DEFAULT_GEOJSON = 'database/imports/atlas/osm-pilot-settlement-boundaries.geojson';

    /**
     * @param  list<string>|null  $municipalitySlugs
     */
    public function import(
        string $geojsonPath,
        string $mappingPath,
        ?array $municipalitySlugs = null,
        bool $dryRun = false,
    ): BoundaryImportResult {
        $result = new BoundaryImportResult;

        try {
            $collection = $this->readGeoJson($geojsonPath);
            $mappingDoc = $this->readMapping($mappingPath);
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());

            return $result;
        }

        $features = $this->indexFeatures($collection, $result);
        $mappings = $this->scopeMappings($mappingDoc['mappings'] ?? [], $municipalitySlugs);
        $source = $this->upsertDataSource($mappingDoc['source'] ?? []);
        $import = $this->startImportLog($source, $geojsonPath, $municipalitySlugs, $dryRun);

        $this->detectDuplicateFeatureIds($mappings, $result);
        $this->detectDuplicateSettlementTargets($mappings, $result);

        if (! $result->ok) {
            $this->finishImportLog($import, $result, $dryRun);

            return $result;
        }

        $mappedFeatureIds = [];

        try {
            DB::transaction(function () use ($mappings, $features, $source, $mappingDoc, $result, $dryRun, &$mappedFeatureIds) {
                foreach ($mappings as $row) {
                    $featureId = (string) ($row['source_feature_id'] ?? '');
                    $mappedFeatureIds[$featureId] = true;
                    $feature = $features[$featureId] ?? null;
                    if ($feature === null) {
                        $result->unmatchedFeatures++;
                        $result->addWarning("Mapirani feature nije u GeoJSON-u: {$featureId}");

                        continue;
                    }

                    $this->persistMappedFeature($row, $feature, $source, $mappingDoc, $dryRun, $result);
                }
            });
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());
        }

        foreach ($features as $featureId => $feature) {
            if (! isset($mappedFeatureIds[$featureId])) {
                $result->unmatchedFeatures++;
                $result->addWarning("Feature bez mapiranja: {$featureId}");
            }
        }

        $this->reportMissingSettlements($municipalitySlugs, $mappings, $result);
        $this->finishImportLog($import, $result, $dryRun);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $feature
     * @param  array<string, mixed>  $mappingDoc
     */
    private function persistMappedFeature(
        array $row,
        array $feature,
        DataSource $source,
        array $mappingDoc,
        bool $dryRun,
        BoundaryImportResult $result,
    ): void {
        $settlement = $this->findSettlement($row);
        if ($settlement === null) {
            $result->unmatchedFeatures++;
            $result->addWarning('Nema naselja za '.($row['municipality_slug'] ?? '?').'/'.($row['statistical_code'] ?? $row['settlement_slug'] ?? '?'));

            return;
        }

        try {
            $polygon = GeoJsonPolygon::from($feature['geometry']);
        } catch (InvalidArgumentException $exception) {
            $result->invalidGeometries++;
            $result->addWarning("Neispravna geometrija {$row['source_feature_id']}: {$exception->getMessage()}");

            return;
        }

        if ($polygon->isAbsurdlyLarge()) {
            $result->invalidGeometries++;
            $result->addWarning("Geometrija je prevelika za naselje ({$polygon->areaKm2()} km²): {$row['source_feature_id']}");

            return;
        }

        $result->matchedSettlements++;
        $version = (string) ($mappingDoc['source']['dataset_version'] ?? $row['boundary_version'] ?? 'osm-2026-09-01');
        $bbox = $polygon->bbox();
        $centroid = $polygon->geographicCentroid();
        $fingerprint = $polygon->fingerprint();
        $existing = SettlementBoundary::query()
            ->where('settlement_id', $settlement->id)
            ->where('source_id', $source->id)
            ->where('boundary_version', $version)
            ->first();

        $payload = [
            'settlement_id' => $settlement->id,
            'source_id' => $source->id,
            'geom_geojson' => $polygon->encoded(),
            'source_feature_id' => (string) $row['source_feature_id'],
            'boundary_version' => $version,
            'source_properties' => [
                'osm_id' => $row['osm_id'] ?? null,
                'source_name' => $row['source_name'] ?? ($feature['properties']['name'] ?? null),
                'source_names' => $row['source_names'] ?? null,
                'municipality_slug' => $row['municipality_slug'] ?? null,
                'municipality_name' => $feature['properties']['municipality_name'] ?? null,
                'settlement_name' => $row['settlement_name'] ?? null,
                'statistical_code' => $row['statistical_code'] ?? null,
                'match_method' => $row['match_method'] ?? null,
                'notes' => $row['notes'] ?? null,
                'raw' => $row['source_properties'] ?? ($feature['properties']['source_properties'] ?? null),
            ],
            'bbox_min_lng' => $bbox['min_lng'],
            'bbox_min_lat' => $bbox['min_lat'],
            'bbox_max_lng' => $bbox['max_lng'],
            'bbox_max_lat' => $bbox['max_lat'],
            'geometry_valid' => true,
            'verification_status' => ($row['match_method'] ?? '') === 'explicit_manual_review'
                ? VerificationStatus::ManuallyVerified
                : VerificationStatus::OsmImported,
            'area_km2' => $polygon->areaKm2(),
            'area_algorithm' => GeoJsonPolygon::AREA_ALGORITHM,
            'area_calculated_at' => now(),
            'geometry_fingerprint' => $fingerprint,
            'imported_at' => now(),
        ];

        $centroidChanged = $settlement->geographic_centroid_lat === null
            || abs((float) $settlement->geographic_centroid_lat - $centroid['lat']) > 0.0000001
            || abs((float) $settlement->geographic_centroid_lng - $centroid['lng']) > 0.0000001;

        if ($existing && $existing->geometry_fingerprint === $fingerprint && ! $centroidChanged) {
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
            SettlementBoundary::query()->create($payload);
            $result->inserted++;
        }

        $labelLat = $settlement->label_point_lat;
        $labelLng = $settlement->label_point_lng;
        $settlement->geographic_centroid_lat = $centroid['lat'];
        $settlement->geographic_centroid_lng = $centroid['lng'];
        if ($row['osm_id'] ?? null) {
            $settlement->osm_relation_id = (int) $row['osm_id'];
        }
        $settlement->save();
        $settlement->refresh();
        if ((string) $settlement->label_point_lat !== (string) $labelLat || (string) $settlement->label_point_lng !== (string) $labelLng) {
            throw new RuntimeException("label_point je izmijenjen za {$settlement->slug}");
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function findSettlement(array $row): ?GeoSettlement
    {
        $municipality = GeoMunicipality::query()->where('slug', $row['municipality_slug'] ?? '')->first();
        if ($municipality === null) {
            return null;
        }

        $query = GeoSettlement::query()->where('municipality_id', $municipality->id);
        if (! empty($row['statistical_code'])) {
            $byCode = (clone $query)->where('statistical_code', $row['statistical_code'])->get();
            if ($byCode->count() === 1) {
                return $byCode->first();
            }
            if ($byCode->count() > 1) {
                return null;
            }
        }

        if (! empty($row['settlement_slug'])) {
            return (clone $query)->where('slug', $row['settlement_slug'])->first();
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexFeatures(array $collection, BoundaryImportResult $result): array
    {
        $features = [];
        foreach ($collection['features'] as $feature) {
            if (! is_array($feature)) {
                continue;
            }
            $result->featuresRead++;
            $id = (string) ($feature['properties']['source_feature_id'] ?? $feature['id'] ?? '');
            if ($id === '') {
                $result->invalidGeometries++;
                $result->addWarning('Feature bez source_feature_id.');

                continue;
            }
            if (isset($features[$id])) {
                $result->ambiguousMatches++;
                $result->addError("Duplikat source_feature_id u GeoJSON-u: {$id}");

                continue;
            }
            if (! isset($feature['geometry']) || ! is_array($feature['geometry'])) {
                $result->invalidGeometries++;
                $result->addWarning("Feature bez geometrije: {$id}");

                continue;
            }
            $features[$id] = $feature;
        }

        return $features;
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     * @param  list<string>|null  $municipalitySlugs
     * @return list<array<string, mixed>>
     */
    private function scopeMappings(array $mappings, ?array $municipalitySlugs): array
    {
        if ($municipalitySlugs === null) {
            return array_values($mappings);
        }

        $wanted = array_fill_keys($municipalitySlugs, true);

        return array_values(array_filter(
            $mappings,
            fn (array $row) => isset($wanted[(string) ($row['municipality_slug'] ?? '')]),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     */
    private function detectDuplicateFeatureIds(array $mappings, BoundaryImportResult $result): void
    {
        $seen = [];
        foreach ($mappings as $row) {
            $id = (string) ($row['source_feature_id'] ?? '');
            if ($id === '') {
                $result->addError('Mapiranje bez source_feature_id.');

                continue;
            }
            if (isset($seen[$id])) {
                $result->ambiguousMatches++;
                $result->addError("Duplikat source_feature_id u mapiranju: {$id}");
            }
            $seen[$id] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     */
    private function detectDuplicateSettlementTargets(array $mappings, BoundaryImportResult $result): void
    {
        $seen = [];
        foreach ($mappings as $row) {
            $key = ($row['municipality_slug'] ?? '').'|'.($row['statistical_code'] ?? $row['settlement_slug'] ?? '');
            if (isset($seen[$key])) {
                $result->ambiguousMatches++;
                $result->addError("Više poligona mapirano na isto naselje: {$key}");
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param  list<string>|null  $municipalitySlugs
     * @param  list<array<string, mixed>>  $mappings
     */
    private function reportMissingSettlements(?array $municipalitySlugs, array $mappings, BoundaryImportResult $result): void
    {
        $slugs = $municipalitySlugs ?? ['modrica', 'samac'];
        $mapped = [];
        foreach ($mappings as $row) {
            $mapped[($row['municipality_slug'] ?? '').'|'.($row['statistical_code'] ?? '')] = true;
            $mapped[($row['municipality_slug'] ?? '').'|slug:'.($row['settlement_slug'] ?? '')] = true;
        }

        $settlements = GeoSettlement::query()
            ->whereHas('municipality', fn ($query) => $query->whereIn('slug', $slugs))
            ->with('municipality:id,slug')
            ->with('boundaries')
            ->orderBy('name')
            ->get();

        foreach ($settlements as $settlement) {
            $muni = $settlement->municipality?->slug;
            $hasMapping = isset($mapped[$muni.'|'.$settlement->statistical_code])
                || isset($mapped[$muni.'|slug:'.$settlement->slug]);
            if (! $hasMapping || $settlement->boundaries->isEmpty()) {
                $result->settlementsWithoutBoundaries++;
                $result->missingSettlements[] = $muni.'/'.$settlement->slug;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readGeoJson(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("GeoJSON nije čitljiv: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'FeatureCollection' || ! isset($decoded['features']) || ! is_array($decoded['features'])) {
            throw new RuntimeException("GeoJSON nije FeatureCollection: {$path}");
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function readMapping(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Mapiranje nije čitljivo: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['mappings']) || ! is_array($decoded['mappings'])) {
            throw new RuntimeException("Mapiranje nije validno: {$path}");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $sourceMeta
     */
    private function upsertDataSource(array $sourceMeta): DataSource
    {
        return DataSource::query()->updateOrCreate(
            ['name' => 'OpenStreetMap — granice naselja (admin_level=9)'],
            [
                'provider' => $sourceMeta['publisher'] ?? 'OpenStreetMap contributors',
                'source_class' => DataSourceClass::CommunityGeographic,
                'authority_rank' => SourceAuthority::OPENSTREETMAP,
                'source_type' => 'osm_settlement_boundaries',
                'url' => $sourceMeta['source_url'] ?? 'https://www.openstreetmap.org/copyright',
                'license' => $sourceMeta['license'] ?? 'ODbL',
                'attribution' => '© OpenStreetMap contributors (ODbL). Granice nisu katastarski izvod.',
                'dataset_version' => $sourceMeta['dataset_version'] ?? 'osm-2026-09-01',
                'metadata' => [
                    'retrieval_date' => $sourceMeta['retrieval_date'] ?? null,
                    'geographic_crs' => $sourceMeta['geographic_crs'] ?? 'EPSG:4326',
                    'feature_identifier_field' => $sourceMeta['feature_identifier_field'] ?? 'osm_id',
                    'geometry_type' => $sourceMeta['geometry_type'] ?? 'MultiPolygon',
                    'known_limitations' => $sourceMeta['known_limitations'] ?? null,
                    'notes' => 'Zajednički OSM poligoni admin_level=9, usklađeni sa popisnim identitetom preko statističke šifre i opštine. Nisu službene katastarske granice.',
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

    private function finishImportLog(DataImport $import, BoundaryImportResult $result, bool $dryRun): void
    {
        $import->fill([
            'status' => $result->ok ? DataImportStatus::Completed : DataImportStatus::Failed,
            'finished_at' => now(),
            'records_read' => $result->featuresRead,
            'records_inserted' => $dryRun ? 0 : $result->inserted,
            'records_updated' => $dryRun ? 0 : $result->updated,
            'records_skipped' => $result->unchanged + $result->unmatchedFeatures + $result->invalidGeometries,
            'errors' => $result->errors === [] ? null : implode("\n", $result->errors),
            'metadata' => array_merge($import->metadata ?? [], [
                'warnings' => $result->warnings,
                'matched_settlements' => $result->matchedSettlements,
                'unchanged' => $result->unchanged,
                'unmatched_features' => $result->unmatchedFeatures,
                'settlements_without_boundaries' => $result->settlementsWithoutBoundaries,
                'missing_settlements' => $result->missingSettlements,
                'invalid_geometries' => $result->invalidGeometries,
                'ambiguous_matches' => $result->ambiguousMatches,
            ]),
        ])->save();
    }
}
