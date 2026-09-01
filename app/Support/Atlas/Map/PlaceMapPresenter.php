<?php

namespace App\Support\Atlas\Map;

use App\Http\Resources\Geo\GeoPlaceResource;
use App\Models\GeoPlace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PlaceMapPresenter
{
    public const COVERAGE_NOTE = 'Prikazani objekti su trenutno mapirani i uvezeni zapisi iz OpenStreetMap (ODbL). To nije službeni niti iscrpan inventar objekata na terenu.';

    public const POINT_NOTE = 'Koordinata je representative point za prikaz na karti, ne nužno ulaz u zgradu.';

    public const ASSIGNMENT_NOTE = 'Dodjela naselju koristi trenutno dostupni atlas poligon (OSM admin_level=9), a ne službenu statističku pripadnost.';

    /**
     * @param  Collection<int, GeoPlace>  $places
     * @return array{type: string, features: list<array<string, mixed>>, bbox?: list<float>, meta: array<string, mixed>}
     */
    public function collection(Collection $places): array
    {
        $features = [];
        $minLng = $minLat = $maxLng = $maxLat = null;

        foreach ($places as $place) {
            $features[] = $this->feature($place);
            $lng = (float) $place->lng;
            $lat = (float) $place->lat;
            $minLng = min($minLng ?? $lng, $lng);
            $minLat = min($minLat ?? $lat, $lat);
            $maxLng = max($maxLng ?? $lng, $lng);
            $maxLat = max($maxLat ?? $lat, $lat);
        }

        $payload = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'source_type' => 'osm_pois',
                'license' => 'ODbL',
                'attribution' => '© OpenStreetMap contributors',
                'feature_count' => count($features),
                'coverage_note' => self::COVERAGE_NOTE,
                'point_note' => self::POINT_NOTE,
                'assignment_note' => self::ASSIGNMENT_NOTE,
            ],
        ];

        if ($minLng !== null) {
            $payload['bbox'] = [$minLng, $minLat, $maxLng, $maxLat];
        }

        return $payload;
    }

    /**
     * @return array{type: string, id: int, properties: array<string, mixed>, geometry: array<string, mixed>}
     */
    public function feature(GeoPlace $place): array
    {
        $properties = (new GeoPlaceResource($place))->resolve(new Request);
        unset($properties['lat'], $properties['lng']);

        return [
            'type' => 'Feature',
            'id' => $place->id,
            'properties' => $properties,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float) $place->lng, (float) $place->lat],
            ],
        ];
    }
}
