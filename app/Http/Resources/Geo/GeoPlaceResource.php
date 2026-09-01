<?php

namespace App\Http\Resources\Geo;

use App\Models\GeoPlace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GeoPlace */
class GeoPlaceResource extends JsonResource
{
    /**
     * Compact public POI. Does not expose source_properties.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->placeType;
        $hasProperName = is_string($this->name) && $this->name !== '';

        return array_filter([
            'id' => $this->id,
            'type' => $type?->code,
            'family' => $type?->family->value,
            'family_label' => $type?->family->publicLabel(),
            'type_label' => $type?->singular_label ?: $type?->name,
            'name' => $hasProperName ? $this->name : null,
            'display_label' => $this->displayLabel(),
            'name_is_generic' => ! $hasProperName,
            'municipality_slug' => $this->municipality?->slug,
            'municipality_name' => $this->municipality?->name,
            'settlement_slug' => $this->settlement?->slug,
            'settlement_name' => $this->settlement?->name,
            'path' => $this->municipality && $this->settlement
                ? '/mjesta/'.$this->municipality->slug.'/'.$this->settlement->slug
                : ($this->municipality ? '/mjesta/'.$this->municipality->slug : null),
            'operator' => $this->operator,
            'address' => $this->address,
            'phone' => $this->phone,
            'website' => $this->website,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'verification' => $this->verification_status?->value,
            'source' => 'osm',
        ], fn ($value) => $value !== null && $value !== '');
    }
}
