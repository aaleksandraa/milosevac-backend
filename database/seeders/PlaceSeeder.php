<?php

namespace Database\Seeders;

use App\Models\Place;
use Illuminate\Database\Seeder;

class PlaceSeeder extends Seeder
{
    public function run(): void
    {
        $places = [
            [
                'name' => 'Miloševac',
                'slug' => 'milosevac',
                'locative' => 'Miloševcu',
                'aliases' => ['milosevac', 'miloševac', 'milosevcu'],
                'is_primary' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Dobrinja',
                'slug' => 'dobrinja',
                'locative' => 'Dobrinji',
                'aliases' => ['dobrinja', 'dobrinj', 'dobrinja 1'],
                'is_primary' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Modriča',
                'slug' => 'modrica',
                'locative' => 'Modriči',
                'aliases' => ['modrica', 'modriča', 'modricko polje', 'modričko polje'],
                'is_primary' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Koprivna',
                'slug' => 'koprivna',
                'locative' => 'Koprivni',
                'aliases' => ['koprivna'],
                'is_primary' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'Dugo Polje',
                'slug' => 'dugo-polje',
                'locative' => 'Dugom Polju',
                'aliases' => ['dugo polje', 'dugopolje'],
                'is_primary' => false,
                'sort_order' => 5,
            ],
            [
                'name' => 'Tarevci',
                'slug' => 'tarevci',
                'locative' => 'Tarevcima',
                'aliases' => ['tarevci'],
                'is_primary' => false,
                'sort_order' => 6,
            ],
            [
                'name' => 'Garevac',
                'slug' => 'garevac',
                'locative' => 'Garevcu',
                'aliases' => ['garevac'],
                'is_primary' => false,
                'sort_order' => 7,
            ],
            [
                'name' => 'Kužnjača',
                'slug' => 'kuznjaca',
                'locative' => 'Kužnjači',
                'aliases' => ['kuznjaca', 'kužnjača'],
                'is_primary' => false,
                'sort_order' => 8,
            ],
            [
                'name' => 'Vranjak',
                'slug' => 'vranjak',
                'locative' => 'Vranjku',
                'aliases' => ['vranjak', 'mrkonjici', 'mrkonjići'],
                'is_primary' => false,
                'sort_order' => 9,
            ],
            [
                'name' => 'Višnjik',
                'slug' => 'visnjik',
                'locative' => 'Višnjiku',
                'aliases' => ['visnjik', 'višnjik'],
                'is_primary' => false,
                'sort_order' => 10,
            ],
            [
                'name' => 'Jakeš',
                'slug' => 'jakes',
                'locative' => 'Jakešu',
                'aliases' => ['jakes', 'jakeš'],
                'is_primary' => false,
                'sort_order' => 11,
            ],
            [
                'name' => 'Duga Njiva',
                'slug' => 'duga-njiva',
                'locative' => 'Dugoj Njivi',
                'aliases' => ['duga njiva', 'duganjiva'],
                'is_primary' => false,
                'sort_order' => 12,
            ],
            [
                'name' => 'Pećnik',
                'slug' => 'pecnik',
                'locative' => 'Pećniku',
                'aliases' => ['pecnik', 'pećnik'],
                'is_primary' => false,
                'sort_order' => 13,
            ],
            [
                'name' => 'Ždreban',
                'slug' => 'zdreban',
                'locative' => 'Ždrebanu',
                'aliases' => ['zdreban', 'ždreban'],
                'is_primary' => false,
                'sort_order' => 14,
            ],
            [
                'name' => 'Skugrić',
                'slug' => 'skugric',
                'locative' => 'Skugriću',
                'aliases' => ['skugric', 'skugrić'],
                'is_primary' => false,
                'sort_order' => 15,
            ],
        ];

        foreach ($places as $place) {
            Place::query()->updateOrCreate(
                ['slug' => $place['slug']],
                $place,
            );
        }
    }
}
