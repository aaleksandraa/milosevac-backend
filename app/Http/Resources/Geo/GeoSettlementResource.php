<?php

namespace App\Http\Resources\Geo;

use App\Models\GeoSettlement;
use App\Support\Atlas\PopulationSelector;
use App\Support\Atlas\SettlementIndexability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GeoSettlement */
class GeoSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $history = $this->relationLoaded('populationRecords')
            ? (new PopulationSelector)->history($this->populationRecords)
            : collect();
        $latest = $history->first();
        $siblings = $this->siblings();

        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'name_cyrillic' => $this->name_cyrillic,
            'statistical_code' => $this->statistical_code,
            'official_code' => $this->official_code,
            'wikidata_id' => $this->wikidata_id,
            'geonames_id' => $this->geonames_id,
            'osm_relation_id' => $this->osm_relation_id,
            'path' => '/mjesta/'.$this->municipality?->slug.'/'.$this->slug,
            'indexable' => SettlementIndexability::isReady($this->resource),
            'municipality' => $this->whenLoaded('municipality', fn () => (new GeoMunicipalitySummaryResource($this->municipality))->resolve()),
            'entity' => $this->when(
                $this->relationLoaded('municipality') && $this->municipality?->relationLoaded('entity'),
                fn () => (new GeoEntityResource($this->municipality->entity))->resolve(),
            ),
            'population' => $latest ? [
                'year' => $latest->census_year,
                'population' => $latest->population,
                'households' => $latest->households,
            ] : null,
            'census' => $history->isEmpty() ? null : GeoPopulationResource::collection($history)->resolve(),
            'points' => $this->points() ?: null,
            'neighbors' => $siblings,
            'source' => $latest && $latest->relationLoaded('source') && $latest->source
                ? (new GeoSourceResource($latest->source))->resolve()
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return list<array{type: string, lat: float, lng: float}>
     */
    private function points(): array
    {
        $points = [];

        if ($this->label_point_lat !== null && $this->label_point_lng !== null) {
            $points[] = [
                'type' => 'label_point',
                'lat' => (float) $this->label_point_lat,
                'lng' => (float) $this->label_point_lng,
            ];
        }

        if ($this->geographic_centroid_lat !== null && $this->geographic_centroid_lng !== null) {
            $points[] = [
                'type' => 'geographic_centroid',
                'lat' => (float) $this->geographic_centroid_lat,
                'lng' => (float) $this->geographic_centroid_lng,
            ];
        }

        return $points;
    }

    /**
     * @return array{previous: array<string, mixed>|null, next: array<string, mixed>|null}|null
     */
    private function siblings(): ?array
    {
        if (! $this->relationLoaded('municipality') || ! $this->municipality?->relationLoaded('settlements')) {
            return null;
        }

        $ordered = $this->municipality->settlements->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $index = $ordered->search(fn (GeoSettlement $settlement) => $settlement->id === $this->id);
        if ($index === false) {
            return null;
        }

        $link = fn (?GeoSettlement $settlement) => $settlement === null ? null : [
            'name' => $settlement->name,
            'slug' => $settlement->slug,
            'path' => '/mjesta/'.$this->municipality->slug.'/'.$settlement->slug,
        ];

        return [
            'previous' => $link($ordered->get($index - 1)),
            'next' => $link($ordered->get($index + 1)),
        ];
    }
}
