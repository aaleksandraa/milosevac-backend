<?php

namespace App\Support\Atlas\Import;

use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Enums\GeoPlaceAssignmentStatus;
use App\Enums\VerificationStatus;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoPlace;
use App\Models\GeoPlaceType;
use App\Support\Atlas\Poi\OsmElementGeometry;
use App\Support\Atlas\Poi\OsmPoiClassifier;
use App\Support\Atlas\Poi\OsmPoiContactNormalizer;
use App\Support\Atlas\Poi\OsmPoiNameResolver;
use App\Support\Atlas\Poi\PoiSettlementAssigner;
use App\Support\Atlas\SourceAuthority;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PoiImportService
{
    public const IMPORTER_VERSION = 'm6.0';

    public const IMPORT_TYPE = 'pilot_settlement_pois_osm';

    public const SOURCE_NAME = 'OpenStreetMap — tačke interesa (POI)';

    public function __construct(
        private OsmPoiClassifier $classifier = new OsmPoiClassifier,
        private OsmElementGeometry $geometry = new OsmElementGeometry,
        private OsmPoiNameResolver $names = new OsmPoiNameResolver,
        private OsmPoiContactNormalizer $contacts = new OsmPoiContactNormalizer,
        private PoiSettlementAssigner $assigner = new PoiSettlementAssigner,
    ) {}

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $municipalitySlugs
     */
    public function import(array $paths, array $municipalitySlugs, bool $dryRun = false): PoiImportResult
    {
        $result = new PoiImportResult;
        $source = $this->upsertSource();
        $types = GeoPlaceType::query()->get()->keyBy('code');
        if ($types->isEmpty()) {
            $result->addError('geo_place_types nije naseljen.');

            return $result;
        }

        $elements = [];
        $hashes = [];
        foreach ($paths as $path) {
            try {
                $document = $this->read($path);
            } catch (Throwable $exception) {
                $result->addError($exception->getMessage());

                return $result;
            }
            $hashes[] = $document['hash'];
            foreach ($document['elements'] as $element) {
                if (is_array($element)) {
                    $elements[] = $element;
                }
            }
        }

        $result->sourceHash = hash('sha256', implode('|', $hashes));
        $import = $this->startImportLog($source, $paths, $municipalitySlugs, $dryRun, $result->sourceHash);

        try {
            DB::transaction(function () use ($elements, $types, $source, $municipalitySlugs, $dryRun, $result) {
                foreach ($elements as $element) {
                    $this->persistElement($element, $types, $source, $municipalitySlugs, $dryRun, $result);
                }
            });
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());
        }

        $this->flagPossibleDuplicates($result);
        $this->finishImportLog($import, $result, $dryRun);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  Collection<string, GeoPlaceType>  $types
     * @param  list<string>  $municipalitySlugs
     */
    private function persistElement(
        array $element,
        $types,
        DataSource $source,
        array $municipalitySlugs,
        bool $dryRun,
        PoiImportResult $result,
    ): void {
        $osmType = (string) ($element['type'] ?? '');
        $osmId = $element['id'] ?? null;
        if (! in_array($osmType, ['node', 'way', 'relation'], true) || ! is_numeric($osmId)) {
            $result->failed++;
            $result->addWarning('OSM element bez valjanog type/id.');

            return;
        }

        $result->recordsRead++;
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        $classification = $this->classifier->classify($tags);
        $point = $this->geometry->representativePoint($element);

        if ($classification === null) {
            $result->unclassified++;
            if (count($result->unclassifiedSamples) < 40) {
                $result->unclassifiedSamples[] = [
                    'osm_type' => $osmType,
                    'osm_id' => (int) $osmId,
                    'tags' => $this->contacts->snapshot($tags),
                    'lat' => $point['lat'] ?? null,
                    'lng' => $point['lng'] ?? null,
                ];
            }

            return;
        }

        if ($point === null) {
            $result->failed++;
            $result->addWarning("Nema representative point za {$osmType}/{$osmId}.");

            return;
        }

        $type = $types->get($classification->typeCode);
        if ($type === null) {
            $result->failed++;
            $result->addError("Nepoznat geo_place_type: {$classification->typeCode}");

            return;
        }

        $result->classified++;
        $assignment = $this->assigner->assign($point['lat'], $point['lng'], $municipalitySlugs);
        if (! $assignment['persist'] || $assignment['municipality'] === null) {
            $result->unassigned++;
            $result->qaRows[] = $this->qaRow($element, $tags, $type, $point, $assignment, null, 'skipped');

            return;
        }

        $names = $this->names->resolve($tags);
        $contacts = $this->contacts->normalize($tags);
        if ($names['name'] === null) {
            $result->unnamed++;
        }

        $result->municipalityAssigned++;
        if ($assignment['settlement'] !== null) {
            $result->settlementAssigned++;
        }
        if ($assignment['status'] === GeoPlaceAssignmentStatus::Ambiguous) {
            $result->ambiguous++;
        }

        $family = $type->family->value;
        $result->byFamily[$family] = ($result->byFamily[$family] ?? 0) + 1;
        $result->byType[$type->code] = ($result->byType[$type->code] ?? 0) + 1;

        $payload = [
            'place_type_id' => $type->id,
            'settlement_id' => $assignment['settlement']?->id,
            'municipality_id' => $assignment['municipality']->id,
            'source_id' => $source->id,
            'name' => $names['name'],
            'slug' => $names['name'] ? Str::slug($names['name']) : null,
            'name_cyrillic' => $names['name_cyrillic'],
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'osm_type' => $osmType,
            'osm_id' => (int) $osmId,
            'osm_version' => isset($element['version']) && is_numeric($element['version']) ? (int) $element['version'] : null,
            'osm_timestamp' => $this->osmTimestamp($element['timestamp'] ?? null),
            'last_synced_at' => now(),
            'operator' => $contacts['operator'],
            'address' => $contacts['address'],
            'phone' => $contacts['phone'],
            'website' => $contacts['website'],
            'opening_hours' => $contacts['opening_hours'],
            'verification_status' => VerificationStatus::OsmImported,
            'classification_status' => $classification->status,
            'assignment_status' => $assignment['status'],
            'point_algorithm' => $point['algorithm'],
            'source_properties' => $this->contacts->snapshot($tags),
            'metadata' => array_filter([
                'assignment_note' => $assignment['note'],
                'assignment_method' => $assignment['status']->value,
                'point_semantics' => 'representative_point',
                'website_warning' => $contacts['website_warning'],
                'payload_fingerprint' => null,
            ], fn ($value) => $value !== null && $value !== ''),
            'is_active' => true,
            'source_deleted_at' => null,
        ];
        $payload['metadata']['payload_fingerprint'] = $this->fingerprint($payload);

        $result->qaRows[] = $this->qaRow($element, $tags, $type, $point, $assignment, $names['name'], $dryRun ? 'dry-run' : 'import');

        if ($dryRun) {
            return;
        }

        $existing = GeoPlace::query()
            ->where('source_id', $source->id)
            ->where('osm_type', $osmType)
            ->where('osm_id', (int) $osmId)
            ->first();

        if ($existing === null) {
            GeoPlace::query()->create($payload);
            $result->inserted++;

            return;
        }

        $existingFingerprint = is_array($existing->metadata) ? ($existing->metadata['payload_fingerprint'] ?? null) : null;
        if ($existingFingerprint === $payload['metadata']['payload_fingerprint']) {
            $result->unchanged++;

            return;
        }

        $existing->fill($payload);
        $existing->save();
        $result->updated++;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        $slice = [
            'place_type_id' => $payload['place_type_id'],
            'settlement_id' => $payload['settlement_id'],
            'municipality_id' => $payload['municipality_id'],
            'name' => $payload['name'],
            'name_cyrillic' => $payload['name_cyrillic'],
            'lat' => $payload['lat'],
            'lng' => $payload['lng'],
            'osm_version' => $payload['osm_version'],
            'osm_timestamp' => $payload['osm_timestamp'],
            'operator' => $payload['operator'],
            'address' => $payload['address'],
            'phone' => $payload['phone'],
            'website' => $payload['website'],
            'opening_hours' => $payload['opening_hours'],
            'classification_status' => $payload['classification_status']->value,
            'assignment_status' => $payload['assignment_status']->value,
            'point_algorithm' => $payload['point_algorithm'],
            'source_properties' => $payload['source_properties'],
        ];

        return hash('sha256', json_encode($slice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $tags
     * @param  array{lat: float, lng: float, algorithm: string}  $point
     * @param  array<string, mixed>  $assignment
     * @return array<string, mixed>
     */
    private function qaRow(
        array $element,
        array $tags,
        GeoPlaceType $type,
        array $point,
        array $assignment,
        ?string $name,
        string $action,
    ): array {
        return [
            'family' => $type->family->value,
            'type' => $type->code,
            'municipality' => $assignment['municipality']?->slug,
            'settlement' => $assignment['settlement']?->slug,
            'named' => $name !== null,
            'name' => $name,
            'osm_type' => $element['type'] ?? null,
            'osm_id' => $element['id'] ?? null,
            'assignment_method' => $assignment['status']->value,
            'verification_status' => VerificationStatus::OsmImported->value,
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'action' => $action,
            'amenity' => $tags['amenity'] ?? null,
        ];
    }

    private function flagPossibleDuplicates(PoiImportResult $result): void
    {
        $named = [];
        foreach ($result->qaRows as $row) {
            if (! ($row['named'] ?? false) || ($row['action'] ?? '') === 'skipped') {
                continue;
            }
            $key = ($row['type'] ?? '').'|'.mb_strtolower((string) ($row['name'] ?? ''));
            $named[$key][] = $row;
        }

        foreach ($named as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            for ($i = 0; $i < count($rows); $i++) {
                for ($j = $i + 1; $j < count($rows); $j++) {
                    if ((string) $rows[$i]['osm_id'] === (string) $rows[$j]['osm_id']
                        && (string) $rows[$i]['osm_type'] === (string) $rows[$j]['osm_type']) {
                        continue;
                    }
                    $dLat = ((float) $rows[$i]['lat'] - (float) $rows[$j]['lat']);
                    $dLng = ((float) $rows[$i]['lng'] - (float) $rows[$j]['lng']);
                    if (($dLat ** 2 + $dLng ** 2) > 0.0004) {
                        continue;
                    }
                    $result->addWarning('Mogući duplikat istog stvarnog objekta (nije spojen): '.$rows[$i]['osm_type'].'/'.$rows[$i]['osm_id'].' i '.$rows[$j]['osm_type'].'/'.$rows[$j]['osm_id'].' ('.$rows[$i]['name'].')');
                }
            }
        }
    }

    /**
     * @return array{elements: list<mixed>, hash: string}
     */
    private function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("POI JSON nije čitljiv: {$path}");
        }
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("POI JSON nije validan: {$path}");
        }

        $elements = $decoded['elements'] ?? null;
        if (! is_array($elements)) {
            throw new RuntimeException("POI JSON nema elements: {$path}");
        }

        return [
            'elements' => $elements,
            'hash' => is_string($decoded['meta']['response_hash'] ?? null)
                ? $decoded['meta']['response_hash']
                : hash('sha256', $raw),
        ];
    }

    private function osmTimestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function upsertSource(): DataSource
    {
        return DataSource::query()->updateOrCreate(
            ['name' => self::SOURCE_NAME],
            [
                'provider' => 'OpenStreetMap contributors',
                'source_class' => DataSourceClass::CommunityGeographic,
                'authority_rank' => SourceAuthority::OPENSTREETMAP,
                'source_type' => 'osm_pois',
                'url' => 'https://www.openstreetmap.org/copyright',
                'license' => 'ODbL',
                'attribution' => '© OpenStreetMap contributors (ODbL). Katalog objekata nije službeni popis niti potpun inventar.',
                'dataset_version' => 'osm-overpass-pilot',
                'metadata' => [
                    'used_for' => ['pilot_settlement_pois'],
                    'importer_version' => self::IMPORTER_VERSION,
                    'notes' => 'Zajednički OSM objekti uvezeni Overpass-om. Nisu službeno verifikovani. Licenca ODbL.',
                ],
            ],
        );
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $municipalitySlugs
     */
    private function startImportLog(
        DataSource $source,
        array $paths,
        array $municipalitySlugs,
        bool $dryRun,
        string $sourceHash,
    ): DataImport {
        return DataImport::query()->create([
            'data_source_id' => $source->id,
            'import_type' => self::IMPORT_TYPE,
            'status' => DataImportStatus::Running,
            'started_at' => now(),
            'metadata' => [
                'dry_run' => $dryRun,
                'files' => array_map(basename(...), $paths),
                'municipality_scope' => $municipalitySlugs,
                'importer_version' => self::IMPORTER_VERSION,
                'source_hash' => $sourceHash,
                'deletion_policy' => 'missing_from_one_overpass_response_does_not_deactivate',
            ],
        ]);
    }

    private function finishImportLog(DataImport $import, PoiImportResult $result, bool $dryRun): void
    {
        $import->fill([
            'status' => $result->ok ? DataImportStatus::Completed : DataImportStatus::Failed,
            'finished_at' => now(),
            'records_read' => $result->recordsRead,
            'records_inserted' => $dryRun ? 0 : $result->inserted,
            'records_updated' => $dryRun ? 0 : $result->updated,
            'records_skipped' => $result->unchanged + $result->unclassified + $result->unassigned + $result->failed,
            'errors' => $result->errors === [] ? null : implode("\n", $result->errors),
            'metadata' => array_merge($import->metadata ?? [], [
                'classified' => $result->classified,
                'unclassified' => $result->unclassified,
                'unchanged' => $result->unchanged,
                'deactivated' => $result->deactivated,
                'unnamed' => $result->unnamed,
                'municipality_assigned' => $result->municipalityAssigned,
                'settlement_assigned' => $result->settlementAssigned,
                'ambiguous' => $result->ambiguous,
                'unassigned' => $result->unassigned,
                'failed' => $result->failed,
                'by_family' => $result->byFamily,
                'by_type' => $result->byType,
                'warnings' => $result->warnings,
                'unclassified_samples' => $result->unclassifiedSamples,
            ]),
        ])->save();
    }
}
