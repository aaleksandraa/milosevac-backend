<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect([
            'manage_posts' => 'Upravljanje člancima',
            'publish_posts' => 'Objava članaka',
            'manage_taxonomy' => 'Upravljanje kategorijama i tagovima',
            'manage_users' => 'Upravljanje korisnicima',
            'manage_settings' => 'Globalne postavke',
        ])->map(fn ($label, $name) => Permission::firstOrCreate(['name' => $name], ['label' => $label]));

        $roleDefinitions = [
            'super_admin' => ['Super Admin', $permissions->pluck('id')->all()],
            'admin' => ['Admin', $permissions->pluck('id')->except(4)->all()],
            'editor' => ['Editor', $permissions->whereIn('name', ['manage_posts', 'publish_posts', 'manage_taxonomy'])->pluck('id')->all()],
            'author' => ['Author', $permissions->where('name', 'manage_posts')->pluck('id')->all()],
            'contributor' => ['Contributor', $permissions->where('name', 'manage_posts')->pluck('id')->all()],
        ];

        $roles = collect($roleDefinitions)->map(function ($definition, $name) {
            [$label, $permissionIds] = $definition;
            $role = Role::firstOrCreate(['name' => $name], ['label' => $label]);
            $role->permissions()->sync($permissionIds);

            return $role;
        });

        $admin = User::firstOrCreate(
            ['email' => 'admin@milosevac.test'],
            [
                'role_id' => $roles['super_admin']->id,
                'name' => 'Glavni urednik',
                'slug' => 'glavni-urednik',
                'password' => Hash::make('password'),
                'bio' => 'Uredništvo portala Miloševac.',
            ]
        );

        $authors = collect([
            ['Redakcija', 'redakcija@milosevac.test', 'redakcija'],
            ['M. Jovanovic', 'm.jovanovic@milosevac.test', 'm-jovanovic'],
            ['S. Nikolic', 's.nikolic@milosevac.test', 's-nikolic'],
        ])->map(fn ($item) => User::firstOrCreate(
            ['email' => $item[1]],
            [
                'role_id' => $roles['author']->id,
                'name' => $item[0],
                'slug' => $item[2],
                'password' => Hash::make('password'),
                'bio' => 'Autor lokalnih vijesti, reportaža i servisnih informacija.',
            ]
        ));

        $categories = collect([
            ['Vijesti', 'vijesti', 'Najnovije vijesti iz Miloševca i okoline.', '#9f1d1d'],
            ['Miloševac', 'milosevac', 'Život, ljudi i događaji u Miloševcu.', '#c77916'],
            ['Sport', 'sport', 'FK Posavina, lokalna takmičenja i sportske vijesti.', '#247a45'],
            ['Slike', 'slike', 'Galerije fotografija iz Miloševca.', '#6d3aa0'],
            ['Projekti', 'projekti', 'Lokalni projekti, intervjui i zanimljivosti.', '#176c94'],
        ])->map(fn ($row, $index) => Category::firstOrCreate(
            ['slug' => $row[1]],
            [
                'name' => $row[0],
                'description' => $row[2],
                'color' => $row[3],
                'sort_order' => $index + 1,
                'meta_title' => $row[0].' - Miloševac',
                'meta_description' => $row[2],
            ]
        ));

        $subcategories = [
            'vijesti' => ['Najnovije', 'Lokalna obavještenja', 'Komunalno', 'Saopštenja'],
            'milosevac' => ['Život', 'Ljudi', 'Kultura', 'KUD Miloševac'],
            'sport' => ['FK Posavina', 'Rezultati', 'Galerije utakmica'],
            'slike' => ['Pejzaži', 'Događaji', 'Iz arhive'],
            'projekti' => ['Da li ste znali', 'Intervjui', 'Top 5', 'Recepti', 'Na današnji dan'],
        ];

        foreach ($subcategories as $parentSlug => $names) {
            $parent = $categories->firstWhere('slug', $parentSlug);
            foreach ($names as $order => $name) {
                Category::firstOrCreate(
                    ['slug' => $parentSlug.'-'.Str::slug($name)],
                    [
                        'parent_id' => $parent->id,
                        'name' => $name,
                        'description' => $parent->description,
                        'color' => $parent->color,
                        'sort_order' => $order + 1,
                        'meta_title' => $name.' - '.$parent->name.' - Miloševac',
                        'meta_description' => $parent->description,
                    ]
                );
            }
        }

        $tags = collect(['struja', 'obavještenje', 'vatrogasci', 'fk-posavina', 'zdravstvo', 'kultura', 'intervjui', 'poljoprivreda'])
            ->mapWithKeys(fn ($tag) => [$tag => Tag::firstOrCreate(['slug' => Str::slug($tag)], ['name' => Str::title(str_replace('-', ' ', $tag))])]);

        Setting::updateOrCreate(['key' => 'site'], ['value' => [
            'name' => 'Miloševac',
            'description' => 'Lokalne vijesti, servisne informacije i magazin za Miloševac.',
        ]]);

        if (! app()->environment('testing')) {
            $this->call(WordpressContentSeeder::class);
        }
    }
}
