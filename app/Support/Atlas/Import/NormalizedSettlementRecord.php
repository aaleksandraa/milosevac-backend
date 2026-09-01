<?php

namespace App\Support\Atlas\Import;

final readonly class NormalizedSettlementRecord
{
    public function __construct(
        public string $municipalitySlug,
        public string $name,
        public string $slug,
        public string $statisticalCode,
        public ?string $municipalityStatisticalCode,
        public ?int $population,
        public ?int $households,
        public array $sourceMetadata = [],
    ) {}
}
