<?php

namespace App\Support\Atlas\Metrics;

use App\Enums\CensusComparability;
use App\Models\GeoSettlement;
use Illuminate\Support\Collection;

final class SettlementComparisonService
{
    public function __construct(
        private SettlementMetricRegistry $registry = new SettlementMetricRegistry,
        private MetricValueExtractor $extractor = new MetricValueExtractor,
    ) {}

    /**
     * @param  list<string>|null  $codes
     * @return array<string, mixed>
     */
    public function compare(GeoSettlement $left, GeoSettlement $right, ?array $codes = null): array
    {
        $codes ??= SettlementMetricRegistry::COMPARISON_CODES;
        $sections = [];

        foreach (collect($codes)->groupBy(fn (string $code) => $this->registry->get($code)->category->value) as $category => $categoryCodes) {
            $rows = [];
            foreach ($categoryCodes as $code) {
                $row = $this->row($left, $right, $this->registry->get($code));
                if ($row === null) {
                    continue;
                }
                $rows[] = $row;
            }
            if ($rows === []) {
                continue;
            }
            $sections[] = [
                'code' => $category,
                'label' => $this->registry->get($categoryCodes->first())->category->publicLabel(),
                'rows' => $rows,
            ];
        }

        return [
            'a' => $this->settlementSummary($left),
            'b' => $this->settlementSummary($right),
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(GeoSettlement $left, GeoSettlement $right, MetricDefinition $metric): ?array
    {
        $leftValue = $this->extractor->extract($left, $metric, false);
        $rightValue = $this->extractor->extract($right, $metric, false);
        if ($leftValue === 0 || $leftValue === 0.0) {
            $leftValue = $metric->zeroIsValid ? $leftValue : null;
        }
        if ($rightValue === 0 || $rightValue === 0.0) {
            $rightValue = $metric->zeroIsValid ? $rightValue : null;
        }

        if ($leftValue === null && $rightValue === null) {
            return null;
        }

        $caveat = $this->caveat($left, $right, $metric);
        $comparable = $leftValue !== null && $rightValue !== null;
        if ($metric->code === 'population_change_1991_2013_pct') {
            $leftComp = $this->extractor->changeComparability($left);
            $rightComp = $this->extractor->changeComparability($right);
            $comparable = $comparable
                && $leftComp?->isDirectlyComparable()
                && $rightComp?->isDirectlyComparable();
        }

        $difference = null;
        if ($leftValue !== null && $rightValue !== null) {
            $absolute = $leftValue - $rightValue;
            $percentage = null;
            if ($metric->percentageDifferenceUseful && $rightValue != 0) {
                $percentage = round((($leftValue - $rightValue) / $rightValue) * 100, 1);
            }
            $difference = [
                'absolute' => $this->roundValue($absolute, $metric->precision),
                'percentage' => $percentage,
            ];
        }

        return [
            'metric' => $metric->code,
            'label' => $metric->publicLabel,
            'short_label' => $metric->shortLabel,
            'unit' => $metric->unit,
            'a' => [
                'value' => $leftValue === null ? null : $this->roundValue($leftValue, $metric->precision),
                'unavailable_reason' => $leftValue === null ? 'Nema dostupnog podatka' : null,
            ],
            'b' => [
                'value' => $rightValue === null ? null : $this->roundValue($rightValue, $metric->precision),
                'unavailable_reason' => $rightValue === null ? 'Nema dostupnog podatka' : null,
            ],
            'difference' => $difference,
            'comparable' => $comparable,
            'caveat' => $caveat,
            'source_family' => $metric->sourceFamily,
            'methodology' => $metric->methodologyCopy,
        ];
    }

    private function caveat(GeoSettlement $left, GeoSettlement $right, MetricDefinition $metric): ?string
    {
        if (! str_starts_with($metric->code, 'population')) {
            return null;
        }

        $comparabilities = Collection::make([
            $metric->code === 'population_1991' ? $this->extractor->populationComparability($left, 1991) : null,
            $metric->code === 'population_1991' ? $this->extractor->populationComparability($right, 1991) : null,
            str_starts_with($metric->code, 'population_change_1991_2013') ? $this->extractor->changeComparability($left) : null,
            str_starts_with($metric->code, 'population_change_1991_2013') ? $this->extractor->changeComparability($right) : null,
        ])->filter();

        if ($comparabilities->contains(CensusComparability::ComparableWithCaveat)) {
            return 'Istorijski podaci su povezani sa današnjim naseljem, ali administrativne ili statističke granice kroz vrijeme nisu nužno potpuno iste.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function settlementSummary(GeoSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'name' => $settlement->name,
            'slug' => $settlement->slug,
            'path' => '/mjesta/'.$settlement->municipality?->slug.'/'.$settlement->slug,
            'municipality' => [
                'id' => $settlement->municipality?->id,
                'name' => $settlement->municipality?->name,
                'slug' => $settlement->municipality?->slug,
                'path' => '/mjesta/'.$settlement->municipality?->slug,
            ],
        ];
    }

    private function roundValue(int|float $value, int $precision): int|float
    {
        if ($precision === 0) {
            return (int) round($value);
        }

        return round((float) $value, $precision);
    }
}
