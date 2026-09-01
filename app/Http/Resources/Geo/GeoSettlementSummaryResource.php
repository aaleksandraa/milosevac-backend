<?php

namespace App\Http\Resources\Geo;

use App\Models\GeoSettlement;
use App\Models\SettlementPopulation;
use App\Support\Atlas\PopulationSelector;
use App\Support\Atlas\SettlementIndexability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GeoSettlement */
class GeoSettlementSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $census = $this->census2013();

        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'name_cyrillic' => $this->name_cyrillic,
            'statistical_code' => $this->statistical_code,
            'path' => '/mjesta/'.$this->municipality?->slug.'/'.$this->slug,
            'indexable' => SettlementIndexability::isReady($this->resource),
            'population' => $census === null ? null : [
                'year' => $census->census_year,
                'population' => $census->population,
                'households' => $census->households,
            ],
        ], fn ($value) => $value !== null);
    }

    private function census2013(): ?SettlementPopulation
    {
        if (! $this->relationLoaded('populationRecords')) {
            return null;
        }

        return (new PopulationSelector)->preferredForYear($this->populationRecords, 2013);
    }
}
