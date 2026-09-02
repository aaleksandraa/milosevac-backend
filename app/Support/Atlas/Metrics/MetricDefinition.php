<?php

namespace App\Support\Atlas\Metrics;

use App\Enums\MetricCategory;
use App\Enums\RankingDirection;

final readonly class MetricDefinition
{
    public const METHOD_COMPETITION = 'competition';

    public function __construct(
        public string $code,
        public string $publicLabel,
        public string $shortLabel,
        public string $unit,
        public string $sourceFamily,
        public MetricCategory $category,
        public RankingDirection $defaultDirection,
        public bool $allowsBothDirections,
        public bool $rankable,
        public bool $zeroIsValid,
        public int $precision,
        public string $highSemanticLabel,
        public string $lowSemanticLabel,
        public bool $percentageDifferenceUseful,
        public string $methodologyCopy,
        public string $eligibilityCopy,
        public string $highRankingLabel,
        public string $lowRankingLabel,
        public bool $percentagePointDifference = false,
    ) {}

    public function rankingMethod(): string
    {
        return self::METHOD_COMPETITION;
    }

    public function permitsDirection(RankingDirection $direction): bool
    {
        if ($this->allowsBothDirections) {
            return true;
        }

        return $direction === $this->defaultDirection;
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->publicLabel,
            'short_label' => $this->shortLabel,
            'unit' => $this->unit,
            'source_family' => $this->sourceFamily,
            'category' => $this->category->value,
            'category_label' => $this->category->publicLabel(),
            'default_direction' => $this->defaultDirection->value,
            'allows_both_directions' => $this->allowsBothDirections,
            'rankable' => $this->rankable,
            'zero_is_valid' => $this->zeroIsValid,
            'precision' => $this->precision,
            'ranking_method' => $this->rankingMethod(),
            'high_semantic_label' => $this->highSemanticLabel,
            'low_semantic_label' => $this->lowSemanticLabel,
            'percentage_difference_useful' => $this->percentageDifferenceUseful,
            'percentage_point_difference' => $this->percentagePointDifference,
            'methodology' => $this->methodologyCopy,
            'eligibility' => $this->eligibilityCopy,
            'high_ranking_label' => $this->highRankingLabel,
            'low_ranking_label' => $this->lowRankingLabel,
        ];
    }
}
