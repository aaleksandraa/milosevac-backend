<?php

namespace App\Http\Resources\Geo;

use App\Models\GeoMunicipality;
use App\Models\SettlementPopulation;
use App\Support\Atlas\PopulationSelector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GeoMunicipality */
class GeoMunicipalitySummaryResource extends JsonResource
{
    public function __construct($resource, private bool $includeSettlements = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'admin_type' => $this->admin_type->value,
            'admin_type_label' => $this->admin_type->publicLabel(),
            'path' => '/mjesta/'.$this->slug,
            'entity' => $this->whenLoaded('entity', fn () => (new GeoEntityResource($this->entity))->resolve()),
            'canton' => $this->when(
                $this->relationLoaded('canton') && $this->canton !== null,
                fn () => (new GeoCantonResource($this->canton))->resolve(),
            ),
            'settlement_count' => $this->settlements_count ?? ($this->relationLoaded('settlements') ? $this->settlements->count() : 0),
        ];

        if ($this->statistical_code) {
            $payload['statistical_code'] = $this->statistical_code;
        }

        $population = $this->populationSummary();
        if ($population !== null) {
            $payload['population'] = $population['summary'];
            $payload['source'] = $population['source'];
        }

        if ($this->includeSettlements && $this->relationLoaded('settlements')) {
            $this->settlements->each(fn ($settlement) => $settlement->setRelation('municipality', $this->resource));
            $payload['settlements'] = GeoSettlementSummaryResource::collection($this->settlements)->resolve();
        }

        return $payload;
    }

    /**
     * @return array{summary: array<string, mixed>, source: array<string, mixed>|null}|null
     */
    private function populationSummary(): ?array
    {
        if (! $this->relationLoaded('settlements')) {
            return null;
        }

        $selector = new PopulationSelector;
        $rows = $this->settlements
            ->map(fn ($settlement) => $settlement->relationLoaded('populationRecords')
                ? $selector->preferredForYear($settlement->populationRecords, 2013)
                : null)
            ->filter();

        if ($rows->isEmpty()) {
            return null;
        }

        /** @var SettlementPopulation $sample */
        $sample = $rows->first();

        return [
            'summary' => [
                'year' => 2013,
                'total' => (int) $rows->sum('population'),
                'households' => (int) $rows->sum('households'),
                'settlement_records' => $rows->count(),
                'note' => 'Zbir stanovništva uvezenih naselja prema Popisu 2013.',
            ],
            'source' => $sample->relationLoaded('source') && $sample->source
                ? (new GeoSourceResource($sample->source))->resolve()
                : null,
        ];
    }
}
