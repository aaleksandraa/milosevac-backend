<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class WordpressContentSeeder extends Seeder
{
    public function run(): void
    {
        $exitCode = Artisan::call('wordpress:import-posts', [
            'file' => 'database/imports/miloevac.WordPress.2026-05-10.xml',
        ]);

        $this->command?->getOutput()->write(Artisan::output());

        if ($exitCode !== 0) {
            throw new RuntimeException('WordPress import nije uspješno završen.');
        }
    }
}
