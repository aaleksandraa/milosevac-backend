<?php

namespace App\Support\Atlas\Geometry;

use InvalidArgumentException;

final class GeoJsonPolygon
{
    public const MAX_AREA_KM2 = 100.0;

    public const AREA_ALGORITHM = 'spherical_excess_authalic_r6371008.8';

    public const CENTROID_ALGORITHM = 'equirectangular_shoelace_mean_latitude';

    /** WGS84 authalic radius in metres. */
    public const AUTHALIC_RADIUS_M = 6371008.8;

    /**
     * @param  array<string, mixed>  $geometry
     */
    public function __construct(private array $geometry)
    {
        $this->assertValid($geometry);
    }

    /**
     * @param  array<string, mixed>  $geometry
     */
    public static function from(array $geometry): self
    {
        return new self($geometry);
    }

    /**
     * @return array{type: string, coordinates: list<mixed>}
     */
    public function multiPolygon(): array
    {
        $type = $this->geometry['type'];
        $coordinates = $this->geometry['coordinates'];

        if ($type === 'Polygon') {
            return [
                'type' => 'MultiPolygon',
                'coordinates' => [$coordinates],
            ];
        }

        return [
            'type' => 'MultiPolygon',
            'coordinates' => $coordinates,
        ];
    }

    public function encoded(): string
    {
        $encoded = json_encode($this->multiPolygon(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new InvalidArgumentException('GeoJSON se ne može kodirati.');
        }

        return $encoded;
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->encoded());
    }

    /**
     * @return array{min_lng: float, min_lat: float, max_lng: float, max_lat: float}
     */
    public function bbox(): array
    {
        $minLng = 180.0;
        $minLat = 90.0;
        $maxLng = -180.0;
        $maxLat = -90.0;

        foreach ($this->allRings() as $ring) {
            foreach ($ring as $point) {
                $minLng = min($minLng, $point[0]);
                $maxLng = max($maxLng, $point[0]);
                $minLat = min($minLat, $point[1]);
                $maxLat = max($maxLat, $point[1]);
            }
        }

        return [
            'min_lng' => $minLng,
            'min_lat' => $minLat,
            'max_lng' => $maxLng,
            'max_lat' => $maxLat,
        ];
    }

    /**
     * Polygon-derived geographic centroid. Not an inhabited label point.
     *
     * @return array{lat: float, lng: float}
     */
    public function geographicCentroid(): array
    {
        $parts = [];
        $totalArea = 0.0;

        foreach ($this->polygons() as $rings) {
            $outer = $this->projectedCentroidAndArea($rings[0]);
            $area = $outer['area'];
            $cx = $outer['x'] * $area;
            $cy = $outer['y'] * $area;

            foreach (array_slice($rings, 1) as $hole) {
                $inner = $this->projectedCentroidAndArea($hole);
                $area -= $inner['area'];
                $cx -= $inner['x'] * $inner['area'];
                $cy -= $inner['y'] * $inner['area'];
            }

            if ($area <= 0) {
                continue;
            }

            $parts[] = ['x' => $cx / $area, 'y' => $cy / $area, 'area' => $area];
            $totalArea += $area;
        }

        if ($totalArea <= 0 || $parts === []) {
            $bbox = $this->bbox();

            return [
                'lat' => round(($bbox['min_lat'] + $bbox['max_lat']) / 2, 7),
                'lng' => round(($bbox['min_lng'] + $bbox['max_lng']) / 2, 7),
            ];
        }

        $x = 0.0;
        $y = 0.0;
        foreach ($parts as $part) {
            $x += $part['x'] * $part['area'];
            $y += $part['y'] * $part['area'];
        }

        $meanLat = $this->meanLatitude();
        $lng = ($x / $totalArea) / $this->metresPerDegreeLng($meanLat);
        $lat = ($y / $totalArea) / $this->metresPerDegreeLat();

        return [
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
        ];
    }

    public function areaKm2(): float
    {
        $area = 0.0;
        foreach ($this->polygons() as $rings) {
            $area += $this->sphericalRingAreaKm2($rings[0]);
            foreach (array_slice($rings, 1) as $hole) {
                $area -= $this->sphericalRingAreaKm2($hole);
            }
        }

        return round(abs($area), 4);
    }

    public function isAbsurdlyLarge(): bool
    {
        return $this->areaKm2() > self::MAX_AREA_KM2;
    }

    /**
     * @return list<list<list<array{0: float, 1: float}>>>
     */
    public function polygons(): array
    {
        $multi = $this->multiPolygon();

        /** @var list<list<list<array{0: float, 1: float}>>> $coordinates */
        $coordinates = $multi['coordinates'];

        return $coordinates;
    }

    /**
     * @param  array<string, mixed>  $geometry
     */
    private function assertValid(array $geometry): void
    {
        $type = $geometry['type'] ?? null;
        if (! in_array($type, ['Polygon', 'MultiPolygon'], true)) {
            throw new InvalidArgumentException('Očekivan je Polygon ili MultiPolygon.');
        }

        if (! isset($geometry['coordinates']) || ! is_array($geometry['coordinates']) || $geometry['coordinates'] === []) {
            throw new InvalidArgumentException('Geometrija je prazna.');
        }

        $ringCount = 0;
        foreach ($this->walkRings($geometry) as $ring) {
            $ringCount++;
            if (count($ring) < 4) {
                throw new InvalidArgumentException('Prsten poligona mora imati najmanje 4 tačke.');
            }

            foreach ($ring as $point) {
                if (! is_array($point) || count($point) < 2) {
                    throw new InvalidArgumentException('Koordinata nije par [lng, lat].');
                }
                $lng = $point[0];
                $lat = $point[1];
                if (! is_numeric($lng) || ! is_numeric($lat)) {
                    throw new InvalidArgumentException('Koordinate moraju biti brojevi.');
                }
                $lng = (float) $lng;
                $lat = (float) $lat;
                if (! is_finite($lng) || ! is_finite($lat)) {
                    throw new InvalidArgumentException('Koordinate moraju biti konačne.');
                }
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    throw new InvalidArgumentException('Koordinate su izvan WGS84 opsega.');
                }
            }
        }

        if ($ringCount === 0) {
            throw new InvalidArgumentException('Geometrija nema prstenova.');
        }
    }

    /**
     * @return list<list<array{0: float, 1: float}>>
     */
    private function allRings(): array
    {
        $rings = [];
        foreach ($this->polygons() as $polygon) {
            foreach ($polygon as $ring) {
                $rings[] = $this->numericRing($ring);
            }
        }

        return $rings;
    }

    /**
     * @param  list<mixed>  $ring
     * @return list<array{0: float, 1: float}>
     */
    private function numericRing(array $ring): array
    {
        $points = [];
        foreach ($ring as $point) {
            $points[] = [(float) $point[0], (float) $point[1]];
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return iterable<int, list<mixed>>
     */
    private function walkRings(array $geometry): iterable
    {
        if ($geometry['type'] === 'Polygon') {
            foreach ($geometry['coordinates'] as $ring) {
                if (is_array($ring)) {
                    yield $ring;
                }
            }

            return;
        }

        foreach ($geometry['coordinates'] as $polygon) {
            if (! is_array($polygon)) {
                continue;
            }
            foreach ($polygon as $ring) {
                if (is_array($ring)) {
                    yield $ring;
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $ring
     * @return array{x: float, y: float, area: float}
     */
    private function projectedCentroidAndArea(array $ring): array
    {
        $points = $this->numericRing($ring);
        $meanLat = $this->meanLatitude();
        $kx = $this->metresPerDegreeLng($meanLat);
        $ky = $this->metresPerDegreeLat();
        $projected = [];
        foreach ($points as $point) {
            $projected[] = [$point[0] * $kx, $point[1] * $ky];
        }

        $area2 = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        $count = count($projected);
        for ($i = 0; $i < $count - 1; $i++) {
            $x1 = $projected[$i][0];
            $y1 = $projected[$i][1];
            $x2 = $projected[$i + 1][0];
            $y2 = $projected[$i + 1][1];
            $cross = ($x1 * $y2) - ($x2 * $y1);
            $area2 += $cross;
            $cx += ($x1 + $x2) * $cross;
            $cy += ($y1 + $y2) * $cross;
        }

        if ($area2 == 0.0) {
            return ['x' => $projected[0][0], 'y' => $projected[0][1], 'area' => 0.0];
        }

        $area = $area2 / 2.0;

        return [
            'x' => $cx / (6.0 * $area),
            'y' => $cy / (6.0 * $area),
            'area' => abs($area),
        ];
    }

    /**
     * @param  list<mixed>  $ring
     */
    private function sphericalRingAreaKm2(array $ring): float
    {
        $points = $this->numericRing($ring);
        $sum = 0.0;
        $count = count($points);
        for ($i = 0; $i < $count - 1; $i++) {
            $lon1 = deg2rad($points[$i][0]);
            $lat1 = deg2rad($points[$i][1]);
            $lon2 = deg2rad($points[$i + 1][0]);
            $lat2 = deg2rad($points[$i + 1][1]);
            $sum += ($lon2 - $lon1) * (2.0 + sin($lat1) + sin($lat2));
        }

        $metres2 = abs($sum) * self::AUTHALIC_RADIUS_M * self::AUTHALIC_RADIUS_M / 2.0;

        return $metres2 / 1_000_000;
    }

    private function meanLatitude(): float
    {
        $bbox = $this->bbox();

        return ($bbox['min_lat'] + $bbox['max_lat']) / 2.0;
    }

    private function metresPerDegreeLat(): float
    {
        return 111132.954;
    }

    private function metresPerDegreeLng(float $latitude): float
    {
        return 111132.954 * cos(deg2rad($latitude));
    }
}
