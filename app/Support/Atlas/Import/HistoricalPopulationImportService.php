<?php

namespace App\Support\Atlas\Import;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingMethod;
use App\Enums\CensusMappingStatus;
use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Enums\VerificationStatus;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoSettlement;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementPopulation;
use App\Support\Atlas\SourceAuthority;
use App\Support\Atlas\SourcePriorityComparator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class HistoricalPopulationImportService
{
    public const IMPORTER_VERSION = 'm8.1';

    public const IMPORT_TYPE = 'pilot_historical_census_population';

    public const CENSUS_YEARS = [1971, 1981, 1991];

    /** @var array<string, list<string>> */
    private const RELATED_HISTORICAL_MUNICIPALITIES = [
        'modrica' => ['Modriča', 'Gradačac'],
        'samac' => ['Bosanski Šamac'],
    ];

    public function __construct(
        private SourcePriorityComparator $comparator = new SourcePriorityComparator,
    ) {}

    /**
     * @param  list<string>|null  $municipalitySlugs
     */
    public function import(
        string $path,
        ?string $mappingPath = null,
        ?array $municipalitySlugs = null,
        bool $dryRun = false,
    ): HistoricalPopulationImportResult {
        $result = new HistoricalPopulationImportResult;
        $mappingPath ??= $this->defaultMappingPath($path);

        try {
            $payload = $this->readJson($path);
            $mappingDoc = $this->readJson($mappingPath);
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());

            return $result;
        }

        $result->artifactHash = hash_file('sha256', $path);
        $result->mappingHash = hash_file('sha256', $mappingPath);
        $source = $this->upsertHistoricalSource($payload['source'] ?? []);
        $import = $this->startImportLog($source, $path, $mappingPath, $municipalitySlugs, $dryRun, $payload);

        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];
        $mappings = is_array($mappingDoc['mappings'] ?? null) ? $mappingDoc['mappings'] : [];
        $mappingIndex = $this->indexMappings($mappings);

        try {
            DB::transaction(function () use ($records, $mappingIndex, $mappings, $source, $municipalitySlugs, $dryRun, $result) {
                $processedKeys = [];
                foreach ($records as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $result->recordsRead++;
                    $key = $this->recordKey($row);
                    $processedKeys[$key] = true;
                    $this->persistRecord($row, $mappingIndex[$key] ?? null, $source, $municipalitySlugs, $dryRun, $result);
                }

                foreach ($mappings as $mappingRow) {
                    if (! is_array($mappingRow)) {
                        continue;
                    }
                    $key = $this->recordKey($mappingRow);
                    if (isset($processedKeys[$key])) {
                        continue;
                    }
                    $this->persistGapMapping($mappingRow, $source, $municipalitySlugs, $dryRun, $result);
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
     * @param  array<string, mixed>|null  $mappingRow
     * @param  list<string>|null  $municipalitySlugs
     */
    private function persistRecord(
        array $row,
        ?array $mappingRow,
        DataSource $source,
        ?array $municipalitySlugs,
        bool $dryRun,
        HistoricalPopulationImportResult $result,
    ): void {
        $year = (int) ($row['census_year'] ?? 0);
        if (! in_array($year, self::CENSUS_YEARS, true)) {
            $result->rejected++;
            $result->addWarning('Preskočena godina koja nije 1971/1981/1991: '.$year);

            return;
        }

        if (! array_key_exists('population', $row) || $row['population'] === null || $row['population'] === '') {
            $result->rejected++;
            $result->addWarning('Nedostaje population za '.$this->recordLabel($row));

            return;
        }

        if ($mappingRow === null) {
            $result->unmatched++;
            $result->rejected++;
            $result->addWarning('Nema checked-in mapiranja za '.$this->recordLabel($row));

            return;
        }

        $status = CensusMappingStatus::tryFrom((string) ($mappingRow['mapping_status'] ?? ''));
        $comparability = CensusComparability::tryFrom((string) ($mappingRow['comparability'] ?? ''));
        if ($status === null || $comparability === null) {
            $result->rejected++;
            $result->addError('Neispravan mapping_status/comparability za '.$this->recordLabel($row));

            return;
        }

        if ($status === CensusMappingStatus::Ambiguous) {
            $result->ambiguous++;
            $this->upsertMapping($mappingRow, null, $source, $status, $comparability, $dryRun, $result);

            return;
        }

        if ($status === CensusMappingStatus::Unmatched || ! $status->mayPersistPopulation()) {
            $result->unmatched++;
            $this->upsertMapping($mappingRow, null, $source, $status, $comparability, $dryRun, $result);

            return;
        }

        $settlement = $this->resolveSettlement($mappingRow);
        if ($settlement === null) {
            $result->unmatched++;
            $result->rejected++;
            $this->upsertMapping($mappingRow, null, $source, $status, $comparability, $dryRun, $result);
            $result->addWarning('Mapiranje nije razriješilo kanonsko naselje: '.$this->recordLabel($row));

            return;
        }

        if ($this->outsideMunicipalityFilter($settlement->municipality?->slug, $mappingRow, $municipalitySlugs)) {
            $result->skipped++;

            return;
        }

        $this->countMappingStatus($status, $comparability, $result);
        $mapping = $this->upsertMapping($mappingRow, $settlement, $source, $status, $comparability, $dryRun, $result);
        $this->persistPopulation($row, $settlement, $source, $mapping, $dryRun, $result);
        $this->appendQaRow($row, $mappingRow, $settlement, $status, $comparability, $result);
    }

    /**
     * @param  array<string, mixed>  $mappingRow
     * @param  list<string>|null  $municipalitySlugs
     */
    private function persistGapMapping(
        array $mappingRow,
        DataSource $source,
        ?array $municipalitySlugs,
        bool $dryRun,
        HistoricalPopulationImportResult $result,
    ): void {
        $status = CensusMappingStatus::tryFrom((string) ($mappingRow['mapping_status'] ?? '')) ?? CensusMappingStatus::Unmatched;
        $comparability = CensusComparability::tryFrom((string) ($mappingRow['comparability'] ?? '')) ?? CensusComparability::Unresolved;
        $settlement = $this->resolveSettlement($mappingRow);

        if ($this->outsideMunicipalityFilter($settlement?->municipality?->slug, $mappingRow, $municipalitySlugs)) {
            $result->skipped++;

            return;
        }

        if ($status === CensusMappingStatus::Ambiguous) {
            $result->ambiguous++;
        } else {
            $result->unmatched++;
        }

        $this->upsertMapping($mappingRow, $settlement, $source, $status, $comparability, $dryRun, $result);
        $this->appendQaRow($mappingRow, $mappingRow, $settlement, $status, $comparability, $result);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persistPopulation(
        array $row,
        GeoSettlement $settlement,
        DataSource $source,
        SettlementCensusMapping $mapping,
        bool $dryRun,
        HistoricalPopulationImportResult $result,
    ): void {
        $year = (int) $row['census_year'];
        $population = (int) $row['population'];
        $households = array_key_exists('households', $row) && $row['households'] !== null && $row['households'] !== ''
            ? (int) $row['households']
            : null;

        $existingSameSource = SettlementPopulation::query()
            ->where('settlement_id', $settlement->id)
            ->where('census_year', $year)
            ->where('source_id', $source->id)
            ->first();

        $others = SettlementPopulation::query()
            ->with('source')
            ->where('settlement_id', $settlement->id)
            ->where('census_year', $year)
            ->where('source_id', '!=', $source->id)
            ->get();

        foreach ($others as $other) {
            if ($other->source === null) {
                continue;
            }
            if ($other->source->source_type === 'census_settlements' && $year === 2013) {
                $result->rejected++;
                $result->addWarning("2013. red iz M2 nije diran za {$settlement->slug}.");

                return;
            }
            $preferred = $this->comparator->compare($source, $other->source);
            if ((int) $other->population !== $population) {
                $result->conflicts++;
                $result->addWarning(sprintf(
                    'Konflikt stanovništva %s %d: postojeći izvor %s=%d, uvoz %s=%d.',
                    $settlement->slug,
                    $year,
                    $other->source->name,
                    $other->population,
                    $source->name,
                    $population,
                ));
            }
            if ($preferred > 0) {
                $result->rejected++;
                $result->addWarning("Sekundarni izvor ne smije prepisati službeni popis za {$settlement->slug} {$year}.");

                return;
            }
        }

        $payload = [
            'settlement_id' => $settlement->id,
            'census_year' => $year,
            'population' => $population,
            'households' => $households,
            'statistical_settlement_code' => $row['historical_settlement_code'] ?? $mapping->historical_settlement_code,
            'source_id' => $source->id,
            'boundary_version' => null,
            'notes' => $mapping->notes,
        ];

        if ($existingSameSource && $this->samePopulation($existingSameSource, $payload)) {
            $result->unchanged++;

            return;
        }

        if ($dryRun) {
            $existingSameSource ? $result->updated++ : $result->inserted++;

            return;
        }

        if ($existingSameSource) {
            $existingSameSource->fill($payload)->save();
            $result->updated++;

            return;
        }

        SettlementPopulation::query()->create($payload);
        $result->inserted++;
    }

    /**
     * @param  array<string, mixed>  $mappingRow
     */
    private function upsertMapping(
        array $mappingRow,
        ?GeoSettlement $settlement,
        DataSource $source,
        CensusMappingStatus $status,
        CensusComparability $comparability,
        bool $dryRun,
        HistoricalPopulationImportResult $result,
    ): SettlementCensusMapping {
        $payload = [
            'settlement_id' => $settlement?->id,
            'source_id' => $source->id,
            'census_year' => (int) ($mappingRow['census_year'] ?? 0),
            'historical_settlement_name' => (string) ($mappingRow['historical_settlement_name'] ?? ''),
            'historical_settlement_code' => $mappingRow['historical_settlement_code'] ?? null,
            'historical_municipality_name' => (string) ($mappingRow['historical_municipality_name'] ?? ''),
            'historical_municipality_code' => $mappingRow['historical_municipality_code'] ?? null,
            'mapping_status' => $status,
            'mapping_method' => CensusMappingMethod::tryFrom((string) ($mappingRow['mapping_method'] ?? ''))
                ?? CensusMappingMethod::CheckedInMapping,
            'comparability' => $comparability,
            'verification_status' => VerificationStatus::OfficialSourceVerified,
            'notes' => $mappingRow['notes'] ?? null,
            'source_properties' => [
                'current_municipality_slug' => $mappingRow['current_municipality_slug'] ?? null,
                'current_settlement_slug' => $mappingRow['current_settlement_slug'] ?? null,
                'alias' => (bool) ($mappingRow['alias'] ?? false),
            ],
        ];

        $existing = SettlementCensusMapping::query()
            ->where('source_id', $source->id)
            ->where('census_year', $payload['census_year'])
            ->where('historical_municipality_name', $payload['historical_municipality_name'])
            ->where('historical_settlement_name', $payload['historical_settlement_name'])
            ->first();

        if ($dryRun) {
            return $existing ?? new SettlementCensusMapping($payload);
        }

        if ($existing && $this->sameMapping($existing, $payload)) {
            return $existing;
        }

        if ($existing) {
            $existing->fill($payload)->save();

            return $existing;
        }

        return SettlementCensusMapping::query()->create($payload);
    }

    /**
     * @param  array<string, mixed>  $mappingRow
     */
    private function resolveSettlement(array $mappingRow): ?GeoSettlement
    {
        $muni = $mappingRow['current_municipality_slug'] ?? null;
        $slug = $mappingRow['current_settlement_slug'] ?? null;
        if (! is_string($muni) || $muni === '' || ! is_string($slug) || $slug === '') {
            return null;
        }

        return GeoSettlement::query()
            ->with('municipality:id,slug,name')
            ->where('slug', $slug)
            ->whereHas('municipality', fn ($query) => $query->where('slug', $muni))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $mappingRow
     * @param  list<string>|null  $municipalitySlugs
     */
    private function outsideMunicipalityFilter(?string $currentSlug, array $mappingRow, ?array $municipalitySlugs): bool
    {
        if ($municipalitySlugs === null) {
            return false;
        }

        if ($currentSlug !== null && $currentSlug !== '') {
            return ! in_array($currentSlug, $municipalitySlugs, true);
        }

        $historical = (string) ($mappingRow['historical_municipality_name'] ?? '');
        foreach ($municipalitySlugs as $slug) {
            $related = self::RELATED_HISTORICAL_MUNICIPALITIES[$slug] ?? [];
            if (in_array($historical, $related, true)) {
                return false;
            }
        }

        return true;
    }

    private function countMappingStatus(
        CensusMappingStatus $status,
        CensusComparability $comparability,
        HistoricalPopulationImportResult $result,
    ): void {
        if ($comparability === CensusComparability::ComparableWithCaveat) {
            $result->mappedWithCaveat++;

            return;
        }
        if ($status === CensusMappingStatus::ExactIdentity || $status === CensusMappingStatus::Renamed) {
            $result->mappedExact++;
        } else {
            $result->mappedWithCaveat++;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $mappingRow
     */
    private function appendQaRow(
        array $payload,
        array $mappingRow,
        ?GeoSettlement $settlement,
        CensusMappingStatus $status,
        CensusComparability $comparability,
        HistoricalPopulationImportResult $result,
    ): void {
        $result->qaRows[] = [
            'current_municipality_slug' => $settlement?->municipality?->slug ?? ($mappingRow['current_municipality_slug'] ?? null),
            'current_settlement' => $settlement?->name,
            'current_settlement_slug' => $settlement?->slug ?? ($mappingRow['current_settlement_slug'] ?? null),
            'census_year' => (int) ($payload['census_year'] ?? $mappingRow['census_year'] ?? 0),
            'historical_settlement_name' => $mappingRow['historical_settlement_name'] ?? null,
            'historical_municipality_name' => $mappingRow['historical_municipality_name'] ?? null,
            'population' => $payload['population'] ?? null,
            'mapping_status' => $status->value,
            'mapping_method' => $mappingRow['mapping_method'] ?? CensusMappingMethod::CheckedInMapping->value,
            'comparability' => $comparability->value,
            'notes' => $mappingRow['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function samePopulation(SettlementPopulation $existing, array $payload): bool
    {
        return (int) $existing->population === (int) $payload['population']
            && $existing->households === $payload['households']
            && $existing->statistical_settlement_code === $payload['statistical_settlement_code']
            && $existing->notes === $payload['notes'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sameMapping(SettlementCensusMapping $existing, array $payload): bool
    {
        return $existing->settlement_id === $payload['settlement_id']
            && $existing->mapping_status === $payload['mapping_status']
            && $existing->comparability === $payload['comparability']
            && $existing->notes === $payload['notes']
            && $existing->historical_municipality_name === $payload['historical_municipality_name'];
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     * @return array<string, array<string, mixed>>
     */
    private function indexMappings(array $mappings): array
    {
        $index = [];
        foreach ($mappings as $row) {
            if (! is_array($row)) {
                continue;
            }
            $index[$this->recordKey($row)] = $row;
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function recordKey(array $row): string
    {
        return implode('|', [
            (string) ((int) ($row['census_year'] ?? 0)),
            (string) ($row['historical_municipality_name'] ?? ''),
            (string) ($row['historical_settlement_name'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function recordLabel(array $row): string
    {
        return trim(($row['historical_municipality_name'] ?? '?').' / '.($row['historical_settlement_name'] ?? '?').' '.($row['census_year'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("JSON nije čitljiv: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("JSON nije validan: {$path}");
        }

        return $decoded;
    }

    private function defaultMappingPath(string $dataPath): string
    {
        $dir = dirname($dataPath);
        $candidate = $dir.DIRECTORY_SEPARATOR.'census-historical-pilot-mapping.json';
        if (is_readable($candidate)) {
            return $candidate;
        }

        return database_path('imports/atlas/census-historical-pilot-mapping.json');
    }

    /**
     * @param  array<string, mixed>  $sourceMeta
     */
    private function upsertHistoricalSource(array $sourceMeta): DataSource
    {
        return DataSource::query()->updateOrCreate(
            ['name' => $sourceMeta['name'] ?? 'Popis stanovništva 1991. — uporedni podaci 1971, 1981. i 1991. (naseljena mjesta)'],
            [
                'provider' => $sourceMeta['provider'] ?? 'Federalni zavod za statistiku Federacije Bosne i Hercegovine',
                'source_class' => DataSourceClass::tryFrom((string) ($sourceMeta['source_class'] ?? 'A')) ?? DataSourceClass::Official,
                'authority_rank' => (int) ($sourceMeta['authority_rank'] ?? SourceAuthority::OFFICIAL_STATE),
                'source_type' => (string) ($sourceMeta['source_type'] ?? 'census_settlements_historical'),
                'url' => $sourceMeta['url'] ?? 'https://fzs.ba/wp-content/uploads/2016/06/stanovnistvo-uporedni-podaci-1971_1981_-1991-bilten-265.pdf',
                'license' => null,
                'attribution' => $sourceMeta['attribution'] ?? 'Federalni zavod za statistiku, Statistički bilten 265, Sarajevo, april 1998.',
                'dataset_version' => $sourceMeta['dataset_version'] ?? 'bilten-265-1998',
                'metadata' => [
                    'retrieval_date' => $sourceMeta['retrieval_date'] ?? '2026-09-02',
                    'publication_title' => $sourceMeta['publication_title'] ?? null,
                    'publication_date' => $sourceMeta['publication_date'] ?? '1998-04',
                    'bulletin_number' => $sourceMeta['bulletin_number'] ?? 265,
                    'sha256_bilten_265' => $sourceMeta['sha256_bilten_265'] ?? null,
                    'sha256_nacion_mz_1991' => $sourceMeta['sha256_nacion_mz_1991'] ?? null,
                    'url_mz_1991' => $sourceMeta['url_mz_1991'] ?? null,
                    'transcription' => $sourceMeta['transcription'] ?? null,
                    'used_for' => ['pilot_historical_census_population'],
                    'notes' => 'Naseljske vrijednosti 1971/1981/1991 su ukupno stanovništvo. Historijska opština je izvorni kontekst, ne današnja administracija. Etnička struktura nije uvezena. Domaćinstva nisu uvezena jer settlement-level tabela nije raspoloživa s usklađenom semantikom.',
                ],
            ],
        );
    }

    /**
     * @param  list<string>|null  $municipalitySlugs
     * @param  array<string, mixed>  $payload
     */
    private function startImportLog(
        DataSource $source,
        string $path,
        string $mappingPath,
        ?array $municipalitySlugs,
        bool $dryRun,
        array $payload,
    ): DataImport {
        return DataImport::query()->create([
            'data_source_id' => $source->id,
            'import_type' => self::IMPORT_TYPE,
            'status' => DataImportStatus::Running,
            'started_at' => now(),
            'metadata' => [
                'dry_run' => $dryRun,
                'file' => basename($path),
                'mapping_file' => basename($mappingPath),
                'municipality_scope' => $municipalitySlugs ?? ['modrica', 'samac'],
                'importer_version' => self::IMPORTER_VERSION,
                'census_years' => self::CENSUS_YEARS,
                'dataset_version' => $payload['source']['dataset_version'] ?? 'bilten-265-1998',
                'publication_title' => $payload['source']['publication_title'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finishImportLog(
        DataImport $import,
        HistoricalPopulationImportResult $result,
        bool $dryRun,
        array $payload,
    ): void {
        $import->fill([
            'status' => $result->ok ? DataImportStatus::Completed : DataImportStatus::Failed,
            'finished_at' => now(),
            'records_read' => $result->recordsRead,
            'records_inserted' => $dryRun ? 0 : $result->inserted,
            'records_updated' => $dryRun ? 0 : $result->updated,
            'records_skipped' => $result->skipped + $result->unchanged + $result->rejected + $result->unmatched + $result->ambiguous,
            'errors' => $result->errors === [] ? null : implode("\n", $result->errors),
            'metadata' => array_merge($import->metadata ?? [], [
                'warnings' => $result->warnings,
                'mapped_exact' => $result->mappedExact,
                'mapped_with_caveat' => $result->mappedWithCaveat,
                'ambiguous' => $result->ambiguous,
                'unmatched' => $result->unmatched,
                'unchanged' => $result->unchanged,
                'rejected' => $result->rejected,
                'conflicts' => $result->conflicts,
                'artifact_sha256' => $result->artifactHash,
                'mapping_sha256' => $result->mappingHash,
                'importer_version' => self::IMPORTER_VERSION,
                'census_years' => self::CENSUS_YEARS,
                'dataset_version' => $payload['source']['dataset_version'] ?? null,
            ]),
        ])->save();
    }
}
