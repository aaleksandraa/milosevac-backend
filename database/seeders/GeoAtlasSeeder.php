<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class GeoAtlasSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DataSourceSeeder::class,
            GeoHierarchySeeder::class,
        ]);
    }
}
