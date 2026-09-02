<?php

namespace App\Support\Atlas;

use App\Enums\CensusComparability;
use App\Enums\CensusMappingStatus;
use App\Models\SettlementCensusMapping;
use App\Models\SettlementPopulation;
use Illuminate\Support\Collection;

final class PopulationPresenter
{
    public function __construct(
        private PopulationChangeCalculator $changes = new PopulationChangeCalculator,
        private PublicSourcePresenter $sources = new PublicSourcePresenter,
    ) {}

    /**
     * @param  Collection<int, SettlementPopulation>  $history
     * @param  Collection<int, SettlementCensusMapping>  $mappings
     * @return array<string, mixed>
     */
    public function latest(Collection $history): ?array
    {
        $row = $history->first();
        if (! $row instanceof SettlementPopulation) {
            return null;
        }

        return [
            'year' => $row->census_year,
            'population' => $row->population,
            'households' => $row->households,
        ];
    }

    /**
     * @param  Collection<int, SettlementPopulation>  $history
     * @param  Collection<int, SettlementCensusMapping>  $mappings
     * @return list<array<string, mixed>>
     */
    public function census(Collection $history, Collection $mappings): array
    {
        $rows = [];
        foreach ($history as $record) {
            $mapping = $this->mappingFor($record, $mappings);
            $rows[] = array_filter([
                'census_year' => $record->census_year,
                'population' => $record->population,
                'households' => $record->households,
                'statistical_settlement_code' => $record->statistical_settlement_code,
                'comparability' => $record->census_year === 2013
                    ? CensusComparability::Comparable->value
                    : ($mapping?->comparability?->value ?? CensusComparability::Unresolved->value),
                'mapping_status' => $mapping?->mapping_status?->value,
                'historical_settlement_name' => $mapping?->historical_settlement_name,
                'historical_municipality_name' => $mapping?->historical_municipality_name,
                'reconciliation_note' => $this->publicNote($mapping),
                'notes' => $record->notes,
                'source' => $record->relationLoaded('source') && $record->source
                    ? $this->sources->present($record->source)
                    : null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $rows;
    }

    /**
     * @param  Collection<int, SettlementPopulation>  $history
     * @param  Collection<int, SettlementCensusMapping>  $mappings
     * @return list<array<string, mixed>>
     */
    public function changes(Collection $history, Collection $mappings): array
    {
        return $this->changes->series($history, $mappings);
    }

    /**
     * @param  Collection<int, SettlementCensusMapping>  $mappings
     */
    private function mappingFor(SettlementPopulation $record, Collection $mappings): ?SettlementCensusMapping
    {
        return $mappings->first(function (SettlementCensusMapping $mapping) use ($record) {
            if ((int) $mapping->census_year !== (int) $record->census_year) {
                return false;
            }
            if ($mapping->settlement_id !== null && (int) $mapping->settlement_id !== (int) $record->settlement_id) {
                return false;
            }

            return $mapping->mapping_status !== CensusMappingStatus::Unmatched
                && $mapping->mapping_status !== CensusMappingStatus::Ambiguous;
        });
    }

    private function publicNote(?SettlementCensusMapping $mapping): ?string
    {
        if ($mapping === null || ! is_string($mapping->notes) || $mapping->notes === '') {
            return null;
        }

        return $mapping->notes;
    }
}
