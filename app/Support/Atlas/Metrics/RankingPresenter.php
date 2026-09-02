<?php

namespace App\Support\Atlas\Metrics;

final class RankingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function table(RankingTable $table, ?int $limit, RankingDatasetVersion $versions): array
    {
        $municipalities = $table->universe
            ->map(fn ($settlement) => $settlement->municipality)
            ->filter()
            ->unique('id')
            ->values();

        $entries = $table->entries;
        $appliedLimit = $limit;
        if ($limit !== null && $limit > 0 && $entries->count() > $limit) {
            $entries = $entries->take($limit)->values();
        } else {
            $appliedLimit = $table->entries->count();
        }

        return [
            'metric' => $table->metric->catalog(),
            'scope' => $table->scope->value,
            'scope_label' => $table->scope->publicLabel(),
            'scope_note' => $versions->universeNote($table->scope, $table->municipality, $municipalities),
            'municipality' => $table->municipality === null ? null : [
                'name' => $table->municipality->name,
                'slug' => $table->municipality->slug,
            ],
            'direction' => $table->direction->value,
            'ranking_method' => MetricDefinition::METHOD_COMPETITION,
            'percentile' => [
                'formula' => 'share of eligible settlements with a strictly higher or lower value',
                'ties' => 'equal values are excluded from both strictly-higher and strictly-lower counts',
                'denominator' => 'eligible settlements for this metric and scope',
            ],
            'eligible' => $table->eligible,
            'missing' => $table->missing,
            'universe' => $table->universe->count(),
            'limit' => $appliedLimit,
            'min' => $table->min,
            'max' => $table->max,
            'entries' => $entries->map(fn (RankedEntry $entry) => [
                'rank' => $entry->rank,
                'value' => $this->roundValue($entry->value, $table->metric->precision),
                'percentile_strictly_above' => $entry->percentileStrictlyAbove,
                'percentile_strictly_below' => $entry->percentileStrictlyBelow,
                'tie_count' => $entry->tieCount,
                'eligible' => $entry->eligible,
                'settlement' => [
                    'id' => $entry->settlement->id,
                    'name' => $entry->settlement->name,
                    'slug' => $entry->settlement->slug,
                    'path' => '/mjesta/'.$entry->settlement->municipality?->slug.'/'.$entry->settlement->slug,
                    'municipality' => [
                        'name' => $entry->settlement->municipality?->name,
                        'slug' => $entry->settlement->municipality?->slug,
                    ],
                ],
            ])->all(),
        ];
    }

    public function defaultLimit(int $eligible): int
    {
        return min(10, max($eligible, 0));
    }

    private function roundValue(int|float $value, int $precision): int|float
    {
        if ($precision === 0) {
            return (int) round($value);
        }

        return round((float) $value, $precision);
    }
}
