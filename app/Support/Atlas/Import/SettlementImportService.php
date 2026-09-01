<?php

namespace App\Support\Atlas\Import;

use App\Enums\DataImportStatus;
use App\Enums\DataSourceClass;
use App\Models\DataImport;
use App\Models\DataSource;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use App\Models\SettlementPopulation;
use App\Support\Atlas\SourceAuthority;
use App\Support\PowerOutage\PlaceNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class SettlementImportService
{
    public const IMPORTER_VERSION = 'm2.0';

    public const IMPORT_TYPE = 'pilot_settlements_census_2013';

    public function __construct(
        private PilotCensusFixtureReader $reader = new PilotCensusFixtureReader,
        private OutagePlaceLinker $outageLinker = new OutagePlaceLinker,
        private PlaceNormalizer $names = new PlaceNormalizer,
    ) {}

    /**
     * @param  list<string>|null  $municipalitySlugs
     */
    public function import(string $path, ?array $municipalitySlugs = null, bool $dryRun = false): SettlementImportResult
    {
        $result = new SettlementImportResult;

        try {
            $parsed = $this->reader->read($path, $municipalitySlugs);
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());

            return $result;
        }

        $records = $parsed['records'];
        $result->recordsRead = count($records);

        $source = $this->upsertDataSource($parsed['source']);
        $import = $this->startImportLog($source, $path, $municipalitySlugs, $dryRun);

        if ($municipalitySlugs !== null) {
            foreach ($municipalitySlugs as $slug) {
                if (GeoMunicipality::query()->where('slug', $slug)->doesntExist()) {
                    $result->addError("Opština nije pronađena: {$slug}");
                }
            }
        }

        if ($records === [] && ($municipalitySlugs !== null && $municipalitySlugs !== [])) {
            $result->addError('Fixture ne sadrži naselja za navedeni opseg.');
        }

        if (! $result->ok) {
            $this->finishImportLog($import, $result, $dryRun);

            return $result;
        }

        try {
            DB::transaction(function () use ($records, $source, $result, $dryRun) {
                foreach ($this->groupByMunicipality($records) as $municipalitySlug => $rows) {
                    $municipality = GeoMunicipality::query()->where('slug', $municipalitySlug)->first();
                    if ($municipality === null) {
                        $result->addError("Opština nije pronađena: {$municipalitySlug}");

                        continue;
                    }

                    $this->syncMunicipalityCodes($municipality, $rows[0], $dryRun, $result);

                    foreach ($rows as $record) {
                        $this->persistSettlement($municipality, $record, $source, $dryRun, $result);
                    }
                }
            });
        } catch (Throwable $exception) {
            $result->addError($exception->getMessage());
        }

        $this->finishImportLog($import, $result, $dryRun);

        return $result;
    }

    /**
     * @param  list<NormalizedSettlementRecord>  $records
     * @return array<string, list<NormalizedSettlementRecord>>
     */
    private function groupByMunicipality(array $records): array
    {
        $grouped = [];
        foreach ($records as $record) {
            $grouped[$record->municipalitySlug][] = $record;
        }

        return $grouped;
    }

    private function persistSettlement(
        GeoMunicipality $municipality,
        NormalizedSettlementRecord $record,
        DataSource $source,
        bool $dryRun,
        SettlementImportResult $result,
    ): void {
        $existing = $this->findExisting($municipality, $record);

        if ($existing === null) {
            $slugConflict = GeoSettlement::query()
                ->where('municipality_id', $municipality->id)
                ->where('slug', $record->slug)
                ->exists();
            if ($slugConflict) {
                $result->addError("Sukob slugova u {$municipality->slug}: {$record->slug}");
                $result->recordsSkipped++;

                return;
            }

            $result->recordsInserted++;
            if ($dryRun) {
                return;
            }

            $settlement = GeoSettlement::query()->create([
                'municipality_id' => $municipality->id,
                'name' => $record->name,
                'slug' => $record->slug,
                'statistical_code' => $record->statisticalCode,
                'official_code' => $record->statisticalCode,
            ]);
            $this->upsertPopulation($settlement, $record, $source);
            $this->linkOutagePlace($settlement, $result);

            return;
        }

        if (! $this->mayOverwriteIdentity($existing, $source)) {
            $result->recordsSkipped++;
            $result->addWarning("Preskočeno slabijim izvorom: {$municipality->slug}/{$record->slug}");
            if (! $dryRun) {
                $this->upsertPopulation($existing, $record, $source);
            }

            return;
        }

        $changed = $existing->name !== $record->name
            || $existing->slug !== $record->slug
            || $existing->statistical_code !== $record->statisticalCode
            || $existing->official_code !== $record->statisticalCode;

        $populationChanged = $this->populationWouldChange($existing, $record, $source);
        $link = $this->outageLinker->resolve($existing);

        if (! $changed && ! $populationChanged && ! in_array($link['status'], [OutagePlaceLinker::LINKED], true)) {
            $result->recordsSkipped++;
            if ($link['status'] === OutagePlaceLinker::NOT_FOUND) {
                $result->outageNotFound++;
            } elseif ($link['status'] === OutagePlaceLinker::AMBIGUOUS) {
                $result->outageAmbiguous++;
            }

            return;
        }

        $result->recordsUpdated++;
        if ($dryRun) {
            return;
        }

        $existing->fill([
            'name' => $record->name,
            'slug' => $record->slug,
            'statistical_code' => $record->statisticalCode,
            'official_code' => $record->statisticalCode,
        ])->save();
        $this->upsertPopulation($existing, $record, $source);
        $this->linkOutagePlace($existing->fresh(), $result, $link);
    }

    private function findExisting(GeoMunicipality $municipality, NormalizedSettlementRecord $record): ?GeoSettlement
    {
        $byCode = GeoSettlement::query()
            ->where('municipality_id', $municipality->id)
            ->where('statistical_code', $record->statisticalCode)
            ->first();
        if ($byCode) {
            return $byCode;
        }

        $byName = GeoSettlement::query()
            ->where('municipality_id', $municipality->id)
            ->get()
            ->first(fn (GeoSettlement $settlement) => $this->names->key($settlement->name) === $this->names->key($record->name));
        if ($byName) {
            return $byName;
        }

        return GeoSettlement::query()
            ->where('municipality_id', $municipality->id)
            ->where('slug', $record->slug)
            ->first();
    }

    private function mayOverwriteIdentity(GeoSettlement $existing, DataSource $incoming): bool
    {
        if ($existing->statistical_code === null || $existing->statistical_code === '') {
            return true;
        }

        return $incoming->authority_rank >= SourceAuthority::OFFICIAL_STATE;
    }

    private function upsertPopulation(GeoSettlement $settlement, NormalizedSettlementRecord $record, DataSource $source): void
    {
        if ($record->population === null) {
            return;
        }

        SettlementPopulation::query()->updateOrCreate(
            [
                'settlement_id' => $settlement->id,
                'census_year' => 2013,
                'source_id' => $source->id,
            ],
            [
                'population' => $record->population,
                'households' => $record->households,
                'statistical_settlement_code' => $record->statisticalCode,
                'notes' => $record->population === 0 ? 'Službeni popis 2013: nula stanovnika (nenaseljeno).' : null,
            ],
        );
    }

    private function populationWouldChange(GeoSettlement $settlement, NormalizedSettlementRecord $record, DataSource $source): bool
    {
        if ($record->population === null) {
            return false;
        }

        $row = SettlementPopulation::query()
            ->where('settlement_id', $settlement->id)
            ->where('census_year', 2013)
            ->where('source_id', $source->id)
            ->first();

        if ($row === null) {
            return true;
        }

        return $row->population !== $record->population || $row->households !== $record->households;
    }

    /**
     * @param  array{status: string, place_id: int|null}|null  $resolved
     */
    private function linkOutagePlace(GeoSettlement $settlement, SettlementImportResult $result, ?array $resolved = null): void
    {
        $resolved ??= $this->outageLinker->resolve($settlement);

        if ($resolved['status'] === OutagePlaceLinker::AMBIGUOUS) {
            $result->outageAmbiguous++;
            $result->addWarning("Nejednoznačna outage veza: {$settlement->slug}");

            return;
        }

        if ($resolved['status'] === OutagePlaceLinker::NOT_FOUND) {
            $result->outageNotFound++;

            return;
        }

        if ($resolved['status'] === OutagePlaceLinker::LINKED && $resolved['place_id']) {
            $alreadyLinked = GeoSettlement::query()
                ->where('outage_place_id', $resolved['place_id'])
                ->where('id', '!=', $settlement->id)
                ->exists();
            if ($alreadyLinked) {
                $result->outageAmbiguous++;
                $result->addWarning("Outage mjesto već vezano: {$settlement->slug}");

                return;
            }

            $settlement->outage_place_id = $resolved['place_id'];
            $settlement->save();
            $result->outageLinked++;
        }
    }

    private function syncMunicipalityCodes(
        GeoMunicipality $municipality,
        NormalizedSettlementRecord $record,
        bool $dryRun,
        SettlementImportResult $result,
    ): void {
        if ($record->municipalityStatisticalCode === null) {
            return;
        }

        if ($municipality->statistical_code === $record->municipalityStatisticalCode) {
            return;
        }

        if ($municipality->statistical_code !== null && $municipality->statistical_code !== $record->municipalityStatisticalCode) {
            $result->addWarning("Statistička šifra opštine {$municipality->slug} nije prepisana ({$municipality->statistical_code}).");

            return;
        }

        if (! $dryRun) {
            $municipality->statistical_code = $record->municipalityStatisticalCode;
            $municipality->save();
        }
    }

    /**
     * @param  array<string, mixed>  $sourceMeta
     */
    private function upsertDataSource(array $sourceMeta): DataSource
    {
        $name = (string) ($sourceMeta['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Fixture nema naziv izvora.');
        }

        return DataSource::query()->updateOrCreate(
            ['name' => $name],
            [
                'provider' => $sourceMeta['provider'] ?? null,
                'source_class' => DataSourceClass::from((string) ($sourceMeta['source_class'] ?? 'A')),
                'authority_rank' => (int) ($sourceMeta['authority_rank'] ?? SourceAuthority::OFFICIAL_STATE),
                'source_type' => $sourceMeta['source_type'] ?? 'census_settlements',
                'url' => is_array($sourceMeta['urls'] ?? null) ? ($sourceMeta['urls'][0] ?? null) : ($sourceMeta['url'] ?? null),
                'license' => $sourceMeta['license'] ?? null,
                'attribution' => $sourceMeta['attribution'] ?? null,
                'dataset_version' => $sourceMeta['dataset_version'] ?? '2013',
                'metadata' => [
                    'retrieval_date' => $sourceMeta['retrieval_date'] ?? null,
                    'urls' => $sourceMeta['urls'] ?? null,
                    'notes' => $sourceMeta['notes'] ?? null,
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
            'records_read' => 0,
            'records_inserted' => 0,
            'records_updated' => 0,
            'records_skipped' => 0,
            'metadata' => [
                'dry_run' => $dryRun,
                'file' => basename($path),
                'path' => $path,
                'municipality_scope' => $municipalitySlugs ?? ['modrica', 'samac'],
                'source_version' => $source->dataset_version,
                'importer_version' => self::IMPORTER_VERSION,
            ],
        ]);
    }

    private function finishImportLog(DataImport $import, SettlementImportResult $result, bool $dryRun): void
    {
        $import->fill([
            'status' => $result->ok ? DataImportStatus::Completed : DataImportStatus::Failed,
            'finished_at' => now(),
            'records_read' => $result->recordsRead,
            'records_inserted' => $dryRun ? 0 : $result->recordsInserted,
            'records_updated' => $dryRun ? 0 : $result->recordsUpdated,
            'records_skipped' => $result->recordsSkipped,
            'errors' => $result->errors === [] ? null : implode("\n", $result->errors),
            'metadata' => array_merge($import->metadata ?? [], [
                'warnings' => $result->warnings,
                'outage_linked' => $result->outageLinked,
                'outage_not_found' => $result->outageNotFound,
                'outage_ambiguous' => $result->outageAmbiguous,
            ]),
        ])->save();
    }
}
