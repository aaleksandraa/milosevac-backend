<?php

namespace App\Support\PowerOutage;

final readonly class MatchedPlace
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $locative,
        public bool $isPrimary,
    ) {}

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'locative' => $this->locative,
            'isPrimary' => $this->isPrimary,
        ];
    }
}
