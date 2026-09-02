<?php

namespace App\Http\Resources\Geo;

use App\Models\GeoSettlement;
use App\Models\SettlementBoundary;
use App\Support\Atlas\ClimatePresenter;
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
            'geography' => $this->geography(),
            'climate' => $this->climate(),
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
     * @return array<string, mixed>|null
     */
    private function geography(): ?array
    {
        $boundary = $this->relationLoaded('boundaries')
            ? $this->boundaries->firstWhere('geometry_valid', true)
            : null;
        $terrain = $this->relationLoaded('terrainStats')
            ? $this->terrainStats
                ->filter(fn ($row) => $row->isPubliclyUsable())
                ->sortByDesc(fn ($row) => $row->calculated_at?->timestamp ?? 0)
                ->first()
            : null;

        $block = [];

        if ($boundary?->area_km2 !== null) {
            $block['area_km2'] = (float) $boundary->area_km2;
            $block['area_algorithm'] = $boundary->area_algorithm;
        }

        if ($this->geographic_centroid_lat !== null && $this->geographic_centroid_lng !== null) {
            $block['geographic_centroid'] = [
                'lat' => (float) $this->geographic_centroid_lat,
                'lng' => (float) $this->geographic_centroid_lng,
            ];
        }

        if ($terrain) {
            $block['terrain'] = array_filter([
                'elevation_min_m' => $terrain->elevation_min_m !== null ? (float) $terrain->elevation_min_m : null,
                'elevation_max_m' => $terrain->elevation_max_m !== null ? (float) $terrain->elevation_max_m : null,
                'elevation_mean_m' => $terrain->elevation_mean_m !== null ? (float) $terrain->elevation_mean_m : null,
                'elevation_median_m' => $terrain->elevation_median_m !== null ? (float) $terrain->elevation_median_m : null,
                'elevation_range_m' => $terrain->elevation_range_m !== null ? (float) $terrain->elevation_range_m : null,
                'slope_mean_deg' => $terrain->slope_mean_deg !== null ? (float) $terrain->slope_mean_deg : null,
                'sample_count' => $terrain->valid_cell_count,
                'quality_flag' => $terrain->quality_flag,
                'dem_source' => $terrain->dem_source,
                'dem_version' => $terrain->dem_version,
                'dem_resolution_m' => $terrain->dem_resolution_m,
                'algorithm_version' => $terrain->algorithm_version,
                'calculated_at' => $terrain->calculated_at?->toDateString(),
            ], fn ($value) => $value !== null && $value !== '');
        }

        if ($boundary?->relationLoaded('source') && $boundary->source) {
            $block['boundary_source'] = (new GeoSourceResource($boundary->source))->resolve();
        }

        if ($terrain?->relationLoaded('source') && $terrain->source) {
            $block['terrain_source'] = (new GeoSourceResource($terrain->source))->resolve();
        }

        if ($boundary || $terrain) {
            $block['methodology'] = $this->geographyMethodology($boundary, $terrain);
        }

        return $block === [] ? null : $block;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function climate(): ?array
    {
        if (! $this->relationLoaded('climateProfiles')) {
            return null;
        }

        $profile = $this->climateProfiles
            ->filter(fn ($row) => $row->isPubliclyUsable())
            ->sortByDesc(fn ($row) => $row->calculated_at?->timestamp ?? 0)
            ->first();

        return (new ClimatePresenter)->present($profile);
    }

    private function geographyMethodology(?SettlementBoundary $boundary, mixed $terrain): string
    {
        $parts = [];
        if ($boundary) {
            $parts[] = 'Površina i geografski centroid izvedeni su iz OSM poligona naselja (admin_level=9), ne iz katastra. Geografski centroid nije središte naseljenog dijela.';
        }
        if ($terrain) {
            $parts[] = 'Nadmorske visine i nagib izračunati su iz Copernicus DEM GLO-30 (raster, ~30 m). Piksel se računa ako mu je središte unutar granice naselja. To nisu službene izmjerene visine.';
        }

        return implode(' ', $parts);
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
