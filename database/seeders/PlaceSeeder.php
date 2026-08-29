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
        ];

        foreach ($places as $place) {
            Place::query()->updateOrCreate(
                ['slug' => $place['slug']],
                $place,
            );
        }
    }
}
