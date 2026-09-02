<?php

namespace App\Console\Commands;

use App\Enums\CensusComparability;
use App\Models\GeoSettlement;
use App\Models\SettlementCensusMapping;
use App\Support\Atlas\PopulationSelector;
use Illuminate\Console\Command;

class ReportAtlasHistoricalPopulation extends Command
{
    protected $signature = 'atlas:report-historical-population
        {--json : Print JSON instead of a table}';

    protected $description = 'QA report of historical census identity mappings for current atlas settlements';

    public function handle(): int
    {
        $selector = new PopulationSelector;
        $rows = GeoSettlement::query()
            ->with(['municipality:id,name,slug', 'populationRecords.source', 'censusMappings'])
            ->orderBy('municipality_id')
            ->orderBy('name')
            ->get()
            ->map(fn (GeoSettlement $settlement) => $this->row($settlement, $selector))
            ->values();

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Naselje', 'Opština', '1971', '1981', '1991', '2013', 'Hist. opština', 'Status', 'Uporedivost'],
            $rows->map(fn (array $row) => [
                $row['current_settlement'],
                $row['current_municipality'],
                $row['population_1971'] ?? '—',
                $row['population_1981'] ?? '—',
                $row['population_1991'] ?? '—',
                $row['population_2013'] ?? '—',
                $row['historical_municipality_1991'] ?? '—',
                $row['mapping_status_1991'] ?? '—',
                $row['comparability_1991'] ?? '—',
            ])->all(),
        );

        $full = $rows->filter(fn (array $row) => $row['population_1971'] !== null
            && $row['population_1981'] !== null
            && $row['population_1991'] !== null
            && $row['population_2013'] !== null)->count();
        $this->line('Naselja s punim nizom 1971/1981/1991/2013: '.$full.' / '.$rows->count());

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(GeoSettlement $settlement, PopulationSelector $selector): array
    {
        $history = $selector->history($settlement->populationRecords);
        $byYear = $history->keyBy(fn ($row) => (int) $row->census_year);
        $identity = function (int $year) use ($settlement): ?SettlementCensusMapping {
            return $settlement->censusMappings->first(
                fn (SettlementCensusMapping $mapping) => (int) $mapping->census_year === $year
                    && (int) $mapping->settlement_id === (int) $settlement->id,
            );
        };

        $id1971 = $identity(1971);
        $id1981 = $identity(1981);
        $id1991 = $identity(1991);

        return [
            'current_settlement' => $settlement->name,
            'current_settlement_slug' => $settlement->slug,
            'current_municipality' => $settlement->municipality?->name,
            'current_municipality_slug' => $settlement->municipality?->slug,
            'identity_1971' => $id1971?->historical_settlement_name,
            'identity_1981' => $id1981?->historical_settlement_name,
            'identity_1991' => $id1991?->historical_settlement_name,
            'identity_2013' => $settlement->name,
            'historical_municipality_1971' => $id1971?->historical_municipality_name,
            'historical_municipality_1981' => $id1981?->historical_municipality_name,
            'historical_municipality_1991' => $id1991?->historical_municipality_name,
            'mapping_method' => $id1991?->mapping_method?->value ?? $id1971?->mapping_method?->value,
            'mapping_status_1991' => $id1991?->mapping_status?->value,
            'comparability_1991' => $id1991?->comparability?->value ?? CensusComparability::Unresolved->value,
            'notes' => $id1991?->notes ?? $id1971?->notes,
            'population_1971' => $byYear->get(1971)?->population,
            'population_1981' => $byYear->get(1981)?->population,
            'population_1991' => $byYear->get(1991)?->population,
            'population_2013' => $byYear->get(2013)?->population,
        ];
    }
}
