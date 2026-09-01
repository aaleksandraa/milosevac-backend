<?php

namespace App\Http\Resources\Geo;

use App\Models\SettlementPopulation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SettlementPopulation */
class GeoPopulationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'census_year' => $this->census_year,
            'population' => $this->population,
            'households' => $this->households,
            'statistical_settlement_code' => $this->statistical_settlement_code,
            'notes' => $this->notes,
            'source' => $this->whenLoaded('source', fn () => (new GeoSourceResource($this->source))->resolve()),
        ], fn ($value) => $value !== null);
    }
}
