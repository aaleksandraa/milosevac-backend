<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MysqlSpatialProbeTest extends TestCase
{
    public function test_mysql_spatial_functions_are_skipped_without_a_compatible_database(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Production MySQL spatial probe is not available in this SQLite test environment.');
        }

        $version = DB::selectOne('SELECT VERSION() as v');
        $this->assertNotEmpty($version->v ?? null);

        $geojson = DB::selectOne("SELECT ST_AsGeoJSON(ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[18.4,44.99]}')) as g");
        $this->assertNotEmpty($geojson->g ?? null);
    }
}
