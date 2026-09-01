<?php

namespace Database\Seeders;

use App\Enums\DataSourceClass;
use App\Models\DataSource;
use App\Support\Atlas\SourceAuthority;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder
{
    public function run(): void
    {
        $sources = [
            [
                'name' => 'Federalni zavod za statistiku — Federacija u brojkama 2026',
                'provider' => 'Federalni zavod za statistiku',
                'source_class' => DataSourceClass::Official,
                'authority_rank' => SourceAuthority::OFFICIAL_STATE,
                'source_type' => 'statistical_publication',
                'url' => 'https://fzs.ba/wp-content/uploads/2026/07/Federacija-u-brojkama-2026-.pdf',
                'license' => null,
                'attribution' => 'Federalni zavod za statistiku, Federacija u brojkama 2026.',
                'dataset_version' => '2026',
                'metadata' => ['used_for' => ['fbih_canton_names']],
            ],
            [
                'name' => 'OpenStreetMap',
                'provider' => 'OpenStreetMap contributors',
                'source_class' => DataSourceClass::CommunityGeographic,
                'authority_rank' => SourceAuthority::OPENSTREETMAP,
                'source_type' => 'openstreetmap',
                'url' => 'https://www.openstreetmap.org/copyright',
                'license' => 'ODbL',
                'attribution' => '© OpenStreetMap contributors',
                'dataset_version' => null,
                'metadata' => null,
            ],
            [
                'name' => 'Interni izvedeni izračun',
                'provider' => 'Miloševac atlas',
                'source_class' => DataSourceClass::Derived,
                'authority_rank' => SourceAuthority::SCIENTIFIC_PUBLIC,
                'source_type' => 'derived',
                'url' => null,
                'license' => null,
                'attribution' => 'Izračunate vrijednosti (centroid, udaljenost, DEM statistika) nisu službena geodetska mjerenja.',
                'dataset_version' => null,
                'metadata' => ['used_for' => ['derived_statistics']],
            ],
        ];

        foreach ($sources as $source) {
            DataSource::query()->updateOrCreate(
                ['name' => $source['name']],
                $source,
            );
        }
    }
}
