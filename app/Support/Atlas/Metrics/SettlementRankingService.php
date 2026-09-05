<?php

namespace App\Support\Atlas\Metrics;

use App\Enums\RankingDirection;
use App\Enums\RankingScope;
use App\Models\GeoMunicipality;
use App\Models\GeoSettlement;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class SettlementRankingService
{
    public function __construct(
        private SettlementMetricRegistry $registry = new SettlementMetricRegistry,
        private MetricValueExtractor $extractor = new MetricValueExtractor,
        private RankingDatasetVersion $versions = new RankingDatasetVersion,
    ) {}

    public function table(
        string $metricCode,
        RankingScope $scope,
        RankingDirection $direction,
        ?string $municipalitySlug = null,
    ): RankingTable {
        $metric = $this->registry->get($metricCode);
        if (! $metric->rankable) {
            throw new InvalidArgumentException('Metrik nije rangabilan: '.$metricCode);
        }
        if (! $scope->isImplemented()) {
            throw new InvalidArgumentException('Rang-lista za ovaj obuhvat još nije dostupna.');
        }
        if (! $metric->permitsDirection($direction)) {
            throw new InvalidArgumentException('Smjer rangiranja nije dozvoljen za ovaj metrik.');
        }
        if ($scope === RankingScope::Municipality && ($municipalitySlug === null || $municipalitySlug === '')) {
            throw new InvalidArgumentException('Opština je obavezna za rang-listu unutar opštine.');
        }

        $municipality = $scope === RankingScope::Municipality
            ? GeoMunicipality::query()->where('slug', $municipalitySlug)->first()
            : null;
        if ($scope === RankingScope::Municipality && $municipality === null) {
            throw new InvalidArgumentException('Opština nije pronađena.');
        }

        $version = $this->versions->forFamily($metric->sourceFamily);
        $cacheKey = implode('.', [
            'geo.rankings',
            $metric->code,
            $scope->value,
            $municipalitySlug ?? 'all',
            $direction->value,
            'rounded-'.$metric->precision,
            $version,
        ]);

        return cache()->remember(
            $cacheKey,
            RankingDatasetVersion::CACHE_TTL_SECONDS,
            fn () => $this->compute($metric, $scope, $direction, $municipality, $version),
        );
    }

    /**
     * @param  list<string>|null  $codes
     * @return list<array<string, mixed>>
     */
    public function relativeFacts(GeoSettlement $settlement, RankingScope $scope = RankingScope::Pilot, ?array $codes = null): array
    {
        $codes ??= SettlementMetricRegistry::SETTLEMENT_FACT_CODES;
        $facts = [];

        foreach ($codes as $code) {
            $metric = $this->registry->find($code);
            if ($metric === null || ! $metric->rankable) {
                continue;
            }
            $table = $this->table($code, $scope, $metric->defaultDirection);
            $entry = $table->forSettlement((int) $settlement->id);
            if ($entry === null) {
                continue;
            }

            $facts[] = [
                'metric' => $metric->code,
                'label' => $metric->publicLabel,
                'short_label' => $metric->shortLabel,
                'value' => $this->roundValue($entry->value, $metric->precision),
                'unit' => $metric->unit,
                'rank' => $entry->rank,
                'eligible' => $entry->eligible,
                'tied' => $entry->tieCount > 1,
                'tie_count' => $entry->tieCount,
                'percentile_strictly_above' => $entry->percentileStrictlyAbove,
                'percentile_strictly_below' => $entry->percentileStrictlyBelow,
                'direction' => $metric->defaultDirection->value,
                'scope' => $scope->value,
                'scope_note' => $this->versions->universeNote(
                    $scope,
                    $table->municipality,
                    $table->universe->map(fn (GeoSettlement $row) => $row->municipality)->filter()->unique('id')->values(),
                ),
                'eligibility_note' => $metric->eligibilityCopy,
                'methodology' => $metric->methodologyCopy,
                'source_family' => $metric->sourceFamily,
            ];
        }

        return $facts;
    }

    /**
     * @return Collection<int, GeoSettlement>
     */
    public function universe(RankingScope $scope, ?GeoMunicipality $municipality): Collection
    {
        $query = GeoSettlement::query()
            ->with([
                'municipality:id,name,slug',
                'populationRecords.source',
                'censusMappings',
                'boundaries',
                'terrainStats',
                'climateProfiles',
                'transportProfiles', 'hydroProfiles', 'proximities',
                'surfaceProfiles',
                'places' => function (HasMany $query) {
                    $query->where('is_active', true)->with('placeType:id,family,code,name,singular_label');
                },
            ])
            ->orderBy('name');

        if ($scope === RankingScope::Municipality && $municipality !== null) {
            $query->where('municipality_id', $municipality->id);
        }

        return $query->get();
    }

    private function compute(
        MetricDefinition $metric,
        RankingScope $scope,
        RankingDirection $direction,
        ?GeoMunicipality $municipality,
        string $version,
    ): RankingTable {
        $universe = $this->universe($scope, $municipality);
        $eligibleRows = $universe
            ->filter(fn (GeoSettlement $settlement) => $this->extractor->isEligible($settlement, $metric))
            ->map(function (GeoSettlement $settlement) use ($metric) {
                $value = $this->extractor->extract($settlement, $metric);

                return $value === null ? null : [
                    'settlement' => $settlement,
                    'value' => $this->roundValue($value, $metric->precision),
                ];
            })
            ->filter()
            ->values();

        $eligible = $eligibleRows->count();
        $values = $eligibleRows->pluck('value');
        $min = $values->isEmpty() ? null : $values->min();
        $max = $values->isEmpty() ? null : $values->max();

        $entries = $eligibleRows->map(function (array $row) use ($eligibleRows, $eligible, $direction) {
            $value = $row['value'];
            $better = $eligibleRows->filter(function (array $other) use ($value, $direction) {
                return $direction->higherFirst()
                    ? $other['value'] > $value
                    : $other['value'] < $value;
            })->count();
            $strictlyAbove = $eligibleRows->filter(fn (array $other) => $other['value'] > $value)->count();
            $strictlyBelow = $eligibleRows->filter(fn (array $other) => $other['value'] < $value)->count();
            $tieCount = $eligibleRows->filter(fn (array $other) => $other['value'] == $value)->count();

            return new RankedEntry(
                settlement: $row['settlement'],
                value: $value,
                rank: $better + 1,
                eligible: $eligible,
                percentileStrictlyAbove: $eligible === 0 ? 0 : (int) round(100 * $strictlyAbove / $eligible),
                percentileStrictlyBelow: $eligible === 0 ? 0 : (int) round(100 * $strictlyBelow / $eligible),
                tieCount: $tieCount,
            );
        });

        $sorted = $entries
            ->sort(function (RankedEntry $left, RankedEntry $right) {
                if ($left->rank !== $right->rank) {
                    return $left->rank <=> $right->rank;
                }

                return strcasecmp($left->settlement->name, $right->settlement->name);
            })
            ->values();

        $tieGroups = $sorted
            ->groupBy(fn (RankedEntry $entry) => (string) $entry->value)
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->count();

        return new RankingTable(
            metric: $metric,
            scope: $scope,
            direction: $direction,
            entries: $sorted,
            universe: $universe,
            municipality: $municipality,
            eligible: $eligible,
            missing: $universe->count() - $eligible,
            min: $min,
            max: $max,
            tieGroups: $tieGroups,
            datasetVersion: $version,
        );
    }

    private function roundValue(int|float $value, int $precision): int|float
    {
        if ($precision === 0) {
            return (int) round($value);
        }

        return round((float) $value, $precision);
    }
}
