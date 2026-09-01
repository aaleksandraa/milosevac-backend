<?php

namespace App\Support\Atlas\Poi;

use App\Support\Atlas\Geometry\GeoJsonPolygon;
use InvalidArgumentException;

final class OsmElementGeometry
{
    /**
     * Representative display point for an OSM element. Not a building entrance.
     *
     * @param  array<string, mixed>  $element
     * @return array{lat: float, lng: float, algorithm: string}|null
     */
    public function representativePoint(array $element): ?array
    {
        $type = (string) ($element['type'] ?? '');

        if ($type === 'node') {
            $lat = $this->coord($element['lat'] ?? null);
            $lng = $this->coord($element['lon'] ?? $element['lng'] ?? null);
            if ($lat === null || $lng === null) {
                return null;
            }

            return ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'algorithm' => 'osm_node'];
        }

        $polygon = $this->polygonFromElement($element);
        if ($polygon !== null) {
            return $polygon->representativePoint();
        }

        $fromVertices = $this->midpointFromGeometry($element['geometry'] ?? null);
        if ($fromVertices !== null) {
            return $fromVertices;
        }

        $center = $element['center'] ?? null;
        if (is_array($center)) {
            $lat = $this->coord($center['lat'] ?? null);
            $lng = $this->coord($center['lon'] ?? $center['lng'] ?? null);
            if ($lat !== null && $lng !== null) {
                return ['lat' => round($lat, 7), 'lng' => round($lng, 7), 'algorithm' => 'overpass_center'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function polygonFromElement(array $element): ?GeoJsonPolygon
    {
        $ring = $this->closedRing($element['geometry'] ?? null);
        if ($ring === null && isset($element['members']) && is_array($element['members'])) {
            $ring = $this->closedRingFromMembers($element['members']);
        }

        if ($ring === null) {
            return null;
        }

        try {
            return GeoJsonPolygon::from([
                'type' => 'Polygon',
                'coordinates' => [$ring],
            ]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return list<array{0: float, 1: float}>|null
     */
    private function closedRing(mixed $geometry): ?array
    {
        if (! is_array($geometry) || count($geometry) < 4) {
            return null;
        }

        $ring = [];
        foreach ($geometry as $point) {
            if (! is_array($point)) {
                return null;
            }
            $lat = $this->coord($point['lat'] ?? $point[1] ?? null);
            $lng = $this->coord($point['lon'] ?? $point['lng'] ?? $point[0] ?? null);
            if ($lat === null || $lng === null) {
                return null;
            }
            $ring[] = [$lng, $lat];
        }

        $first = $ring[0];
        $last = $ring[count($ring) - 1];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
            return null;
        }

        return $ring;
    }

    /**
     * @param  list<mixed>  $members
     * @return list<array{0: float, 1: float}>|null
     */
    private function closedRingFromMembers(array $members): ?array
    {
        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }
            $role = (string) ($member['role'] ?? '');
            if ($role !== '' && $role !== 'outer') {
                continue;
            }
            $ring = $this->closedRing($member['geometry'] ?? null);
            if ($ring !== null) {
                return $ring;
            }
        }

        return null;
    }

    /**
     * @return array{lat: float, lng: float, algorithm: string}|null
     */
    private function midpointFromGeometry(mixed $geometry): ?array
    {
        if (! is_array($geometry) || $geometry === []) {
            return null;
        }

        $lats = [];
        $lngs = [];
        foreach ($geometry as $point) {
            if (! is_array($point)) {
                continue;
            }
            $lat = $this->coord($point['lat'] ?? $point[1] ?? null);
            $lng = $this->coord($point['lon'] ?? $point['lng'] ?? $point[0] ?? null);
            if ($lat === null || $lng === null) {
                continue;
            }
            $lats[] = $lat;
            $lngs[] = $lng;
        }

        if ($lats === []) {
            return null;
        }

        $index = intdiv(count($lats), 2);

        return [
            'lat' => round($lats[$index], 7),
            'lng' => round($lngs[$index], 7),
            'algorithm' => 'way_vertex_midpoint',
        ];
    }

    private function coord(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
