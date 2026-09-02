<?php

namespace App\Console\Commands;

use App\Enums\RankingDirection;
use App\Enums\RankingScope;
use App\Support\Atlas\Metrics\SettlementMetricRegistry;
use App\Support\Atlas\Metrics\SettlementRankingService;
use Illuminate\Console\Command;

class ReportAtlasRankings extends Command
{
    protected $signature = 'atlas:report-rankings
        {--json : Print JSON instead of tables}';

    protected $description = 'QA report of settlement ranking denominators, extremes, and ties';

    public function handle(SettlementMetricRegistry $registry, SettlementRankingService $rankings): int
    {
        $reports = [];
        foreach ($registry->rankable() as $metric) {
            $table = $rankings->table($metric->code, RankingScope::Pilot, RankingDirection::Desc);
            $first = $table->entries->take(5)->map(fn ($entry) => [
                'rank' => $entry->rank,
                'name' => $entry->settlement->name,
                'municipality' => $entry->settlement->municipality?->name,
                'value' => $entry->value,
            ])->all();
            $last = $table->entries->reverse()->take(5)->reverse()->map(fn ($entry) => [
                'rank' => $entry->rank,
                'name' => $entry->settlement->name,
                'municipality' => $entry->settlement->municipality?->name,
                'value' => $entry->value,
            ])->all();

            $reports[] = [
                'metric' => $metric->code,
                'eligible' => $table->eligible,
                'missing' => $table->missing,
                'universe' => $table->universe->count(),
                'min' => $table->min,
                'max' => $table->max,
                'tie_groups' => $table->tieGroups,
                'first_5' => $first,
                'last_5' => $last,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode($reports, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Metrik', 'Eligible', 'Missing', 'Universe', 'Min', 'Max', 'Ties'],
            array_map(fn (array $row) => [
                $row['metric'],
                $row['eligible'],
                $row['missing'],
                $row['universe'],
                $row['min'] ?? '—',
                $row['max'] ?? '—',
                $row['tie_groups'],
            ], $reports),
        );

        foreach ($reports as $row) {
            $this->newLine();
            $this->info($row['metric']);
            $this->line('First 5: '.json_encode($row['first_5'], JSON_UNESCAPED_UNICODE));
            $this->line('Last 5: '.json_encode($row['last_5'], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
