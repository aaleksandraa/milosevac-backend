<?php

namespace Database\Seeders;

use App\Enums\GeoEntityKind;
use App\Enums\GeoMunicipalityAdminType;
use App\Models\GeoCanton;
use App\Models\GeoCountry;
use App\Models\GeoEntity;
use App\Models\GeoMunicipality;
use Illuminate\Database\Seeder;

class GeoHierarchySeeder extends Seeder
{
    public function run(): void
    {
        $country = GeoCountry::query()->updateOrCreate(
            ['iso2' => 'BA'],
            [
                'name' => 'Bosna i Hercegovina',
                'slug' => 'bosna-i-hercegovina',
                'iso3' => 'BIH',
            ],
        );

        $federation = GeoEntity::query()->updateOrCreate(
            ['country_id' => $country->id, 'slug' => 'federacija-bih'],
            [
                'name' => 'Federacija Bosne i Hercegovine',
                'kind' => GeoEntityKind::Federation,
            ],
        );

        $republikaSrpska = GeoEntity::query()->updateOrCreate(
            ['country_id' => $country->id, 'slug' => 'republika-srpska'],
            [
                'name' => 'Republika Srpska',
                'kind' => GeoEntityKind::Republic,
            ],
        );

        $brckoDistrict = GeoEntity::query()->updateOrCreate(
            ['country_id' => $country->id, 'slug' => 'brcko-distrikt'],
            [
                'name' => 'Brčko distrikt Bosne i Hercegovine',
                'kind' => GeoEntityKind::District,
            ],
        );

        // Canton names: Federalni zavod za statistiku, Federacija u brojkama 2026.
        $cantons = [
            ['name' => 'Unsko-sanski kanton', 'slug' => 'unsko-sanski', 'official_code' => '01'],
            ['name' => 'Kanton Posavski', 'slug' => 'posavski', 'official_code' => '02'],
            ['name' => 'Tuzlanski kanton', 'slug' => 'tuzlanski', 'official_code' => '03'],
            ['name' => 'Zeničko-dobojski kanton', 'slug' => 'zenicko-dobojski', 'official_code' => '04'],
            ['name' => 'Bosansko-podrinjski kanton', 'slug' => 'bosansko-podrinjski', 'official_code' => '05'],
            ['name' => 'Srednjobosanski kanton', 'slug' => 'srednjobosanski', 'official_code' => '06'],
            ['name' => 'Hercegovačko-neretvanski kanton', 'slug' => 'hercegovacko-neretvanski', 'official_code' => '07'],
            ['name' => 'Zapadnohercegovački kanton', 'slug' => 'zapadnohercegovacki', 'official_code' => '08'],
            ['name' => 'Kanton Sarajevo', 'slug' => 'sarajevo', 'official_code' => '09'],
            ['name' => 'Kanton 10', 'slug' => 'kanton-10', 'official_code' => '10'],
        ];

        foreach ($cantons as $canton) {
            GeoCanton::query()->updateOrCreate(
                ['entity_id' => $federation->id, 'slug' => $canton['slug']],
                [
                    'name' => $canton['name'],
                    'official_code' => $canton['official_code'],
                ],
            );
        }

        GeoMunicipality::query()->updateOrCreate(
            ['slug' => 'modrica'],
            [
                'entity_id' => $republikaSrpska->id,
                'canton_id' => null,
                'name' => 'Modriča',
                'admin_type' => GeoMunicipalityAdminType::Municipality,
            ],
        );

        GeoMunicipality::query()->updateOrCreate(
            ['slug' => 'samac'],
            [
                'entity_id' => $republikaSrpska->id,
                'canton_id' => null,
                'name' => 'Šamac',
                'admin_type' => GeoMunicipalityAdminType::Municipality,
            ],
        );

        GeoMunicipality::query()->updateOrCreate(
            ['slug' => 'brcko'],
            [
                'entity_id' => $brckoDistrict->id,
                'canton_id' => null,
                'name' => 'Brčko',
                'admin_type' => GeoMunicipalityAdminType::District,
            ],
        );
    }
}
