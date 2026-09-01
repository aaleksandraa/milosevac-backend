<?php

namespace Tests\Unit;

use App\Support\Atlas\Geometry\GeoJsonPolygon;
use App\Support\Atlas\Poi\OsmElementGeometry;
use Tests\TestCase;

class OsmElementGeometryTest extends TestCase
{
    public function test_node_uses_node_coordinates(): void
    {
        $point = (new OsmElementGeometry)->representativePoint([
            'type' => 'node',
            'id' => 1,
            'lat' => 44.9938123,
            'lon' => 18.4062123,
        ]);

        $this->assertSame('osm_node', $point['algorithm']);
        $this->assertEqualsWithDelta(44.9938123, $point['lat'], 0.0000001);
        $this->assertEqualsWithDelta(18.4062123, $point['lng'], 0.0000001);
    }

    public function test_closed_way_uses_representative_point_not_first_vertex(): void
    {
        $geometry = [
            ['lat' => 44.990, 'lon' => 18.400],
            ['lat' => 44.990, 'lon' => 18.410],
            ['lat' => 45.000, 'lon' => 18.410],
            ['lat' => 45.000, 'lon' => 18.400],
            ['lat' => 44.990, 'lon' => 18.400],
        ];

        $point = (new OsmElementGeometry)->representativePoint([
            'type' => 'way',
            'id' => 2,
            'geometry' => $geometry,
            'center' => ['lat' => 99, 'lon' => 99],
        ]);

        $this->assertNotEquals(18.400, $point['lng']);
        $this->assertNotEquals(44.990, $point['lat']);
        $this->assertGreaterThan(18.40, $point['lng']);
        $this->assertLessThan(18.41, $point['lng']);
        $this->assertContains($point['algorithm'], [
            'geographic_centroid_inside',
            'bbox_scan_point_on_surface',
        ]);
    }

    public function test_concave_polygon_keeps_representative_point_inside(): void
    {
        $polygon = GeoJsonPolygon::from([
            'type' => 'Polygon',
            'coordinates' => [[
                [18.40, 44.99],
                [18.43, 44.99],
                [18.43, 45.02],
                [18.40, 45.02],
                [18.40, 45.01],
                [18.42, 45.01],
                [18.42, 45.00],
                [18.40, 45.00],
                [18.40, 44.99],
            ]],
        ]);

        $centroid = $polygon->geographicCentroid();
        $point = $polygon->representativePoint();

        $this->assertTrue($polygon->contains($point['lng'], $point['lat']));
        if (! $polygon->contains($centroid['lng'], $centroid['lat'])) {
            $this->assertSame('bbox_scan_point_on_surface', $point['algorithm']);
        }
    }

    public function test_open_way_falls_back_to_vertex_midpoint(): void
    {
        $point = (new OsmElementGeometry)->representativePoint([
            'type' => 'way',
            'id' => 3,
            'geometry' => [
                ['lat' => 44.99, 'lon' => 18.40],
                ['lat' => 44.99, 'lon' => 18.41],
                ['lat' => 44.99, 'lon' => 18.42],
            ],
        ]);

        $this->assertSame('way_vertex_midpoint', $point['algorithm']);
        $this->assertEqualsWithDelta(18.41, $point['lng'], 0.0001);
    }

    public function test_point_in_polygon_respects_holes(): void
    {
        $polygon = GeoJsonPolygon::from([
            'type' => 'Polygon',
            'coordinates' => [
                [[18.40, 44.99], [18.42, 44.99], [18.42, 45.01], [18.40, 45.01], [18.40, 44.99]],
                [[18.405, 44.995], [18.415, 44.995], [18.415, 45.005], [18.405, 45.005], [18.405, 44.995]],
            ],
        ]);

        $this->assertTrue($polygon->contains(18.401, 44.991));
        $this->assertFalse($polygon->contains(18.410, 45.000));
    }
}
