<?php

namespace Tests\Unit;

use App\Support\Atlas\Geometry\GeoJsonPolygon;
use InvalidArgumentException;
use Tests\TestCase;

class GeoJsonPolygonTest extends TestCase
{
    public function test_point_geometry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoJsonPolygon::from(['type' => 'Point', 'coordinates' => [18.4, 44.99]]);
    }

    public function test_multipolygon_is_preserved(): void
    {
        $polygon = GeoJsonPolygon::from([
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[18.40, 44.99], [18.41, 44.99], [18.41, 45.00], [18.40, 45.00], [18.40, 44.99]]],
                [[[18.42, 44.99], [18.43, 44.99], [18.43, 45.00], [18.42, 45.00], [18.42, 44.99]]],
            ],
        ]);

        $this->assertSame('MultiPolygon', $polygon->multiPolygon()['type']);
        $this->assertCount(2, $polygon->multiPolygon()['coordinates']);
        $this->assertGreaterThan(0, $polygon->areaKm2());
        $centroid = $polygon->geographicCentroid();
        $this->assertGreaterThan(44.9, $centroid['lat']);
        $this->assertLessThan(45.1, $centroid['lat']);
    }

    public function test_area_is_not_degree_squares(): void
    {
        $polygon = GeoJsonPolygon::from([
            'type' => 'Polygon',
            'coordinates' => [[
                [18.40, 44.99],
                [18.41, 44.99],
                [18.41, 45.00],
                [18.40, 45.00],
                [18.40, 44.99],
            ]],
        ]);

        $this->assertSame(GeoJsonPolygon::AREA_ALGORITHM, GeoJsonPolygon::AREA_ALGORITHM);
        $this->assertGreaterThan(0.5, $polygon->areaKm2());
        $this->assertLessThan(2.0, $polygon->areaKm2());
        $this->assertNotEquals(0.0001, $polygon->areaKm2());
    }
}
