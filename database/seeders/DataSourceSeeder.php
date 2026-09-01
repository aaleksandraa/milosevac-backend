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
            [
                'name' => 'OpenStreetMap — granice naselja (admin_level=9)',
                'provider' => 'OpenStreetMap contributors',
                'source_class' => DataSourceClass::CommunityGeographic,
                'authority_rank' => SourceAuthority::OPENSTREETMAP,
                'source_type' => 'osm_settlement_boundaries',
                'url' => 'https://www.openstreetmap.org/copyright',
                'license' => 'ODbL',
                'attribution' => '© OpenStreetMap contributors (ODbL). Granice nisu katastarski izvod.',
                'dataset_version' => 'osm-2026-09-01',
                'metadata' => [
                    'retrieval_date' => '2026-09-02',
                    'used_for' => ['pilot_settlement_boundaries'],
                    'notes' => 'Zajednički OSM poligoni admin_level=9, usklađeni sa popisnim identitetom. Nisu službene katastarske granice.',
                ],
            ],
            [
                'name' => 'Copernicus DEM GLO-30 Public',
                'provider' => 'European Space Agency / European Commission',
                'source_class' => DataSourceClass::ReputablePublic,
                'authority_rank' => SourceAuthority::SCIENTIFIC_PUBLIC,
                'source_type' => 'copernicus_dem_glo30',
                'url' => 'https://spacedata.copernicus.eu/collections/copernicus-digital-elevation-model',
                'license' => 'Copernicus DEM GLO-30 Public licence',
                'attribution' => 'Copernicus DEM GLO-30 Public, ESA/EC. Raster-izvedene visine unutar granice naselja.',
                'dataset_version' => '2021_1',
                'metadata' => [
                    'retrieval_date' => '2026-09-02',
                    'nominal_resolution_m' => 30,
                    'horizontal_crs' => 'EPSG:4326',
                    'vertical_datum' => 'EGM2008',
                    'used_for' => ['pilot_settlement_terrain'],
                    'notes' => 'Nadmorske visine su raster-izvedene iz Copernicus DEM GLO-30, nisu službeno izmjerene visine naselja.',
                ],
            ],
            [
                'name' => 'OpenStreetMap — tačke interesa (POI)',
                'provider' => 'OpenStreetMap contributors',
                'source_class' => DataSourceClass::CommunityGeographic,
                'authority_rank' => SourceAuthority::OPENSTREETMAP,
                'source_type' => 'osm_pois',
                'url' => 'https://www.openstreetmap.org/copyright',
                'license' => 'ODbL',
                'attribution' => '© OpenStreetMap contributors (ODbL). Katalog objekata nije službeni popis niti potpun inventar.',
                'dataset_version' => 'osm-overpass-pilot',
                'metadata' => [
                    'used_for' => ['pilot_settlement_pois'],
                    'notes' => 'Zajednički OSM objekti uvezeni Overpass-om u operator/import toku. Nisu službeno verifikovani, nisu Google podaci, i ne predstavljaju iscrpan popis objekata na terenu. Licenca ODbL.',
                ],
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
