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
                'authority_rank' => SourceAuthority::UNVERIFIED_MANUAL,
                'source_type' => 'derived',
                'url' => null,
                'license' => null,
                'attribution' => 'Izračunate vrijednosti nisu same po sebi autoritativne; rang dolazi od izvornog skupa i dokumentovane metodologije, ne od činjenice da je vrijednost izvedena.',
                'dataset_version' => null,
                'metadata' => ['used_for' => ['derived_statistics']],
            ],
            [
                'name' => 'Popis stanovništva, domaćinstava i stanova 2013. — naseljena mjesta',
                'provider' => 'Agencija za statistiku BiH / Republički zavod za statistiku Republike Srpske',
                'source_class' => DataSourceClass::Official,
                'authority_rank' => SourceAuthority::OFFICIAL_STATE,
                'source_type' => 'census_settlements',
                'url' => 'http://www.statistika.ba/?id=20354&show=12',
                'license' => null,
                'attribution' => 'Popis stanovništva, domaćinstava i stanova u BiH 2013. Tabele naseljenih mjesta.',
                'dataset_version' => '2013',
                'metadata' => ['used_for' => ['pilot_settlement_import']],
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
