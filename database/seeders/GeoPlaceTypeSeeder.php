<?php

namespace Database\Seeders;

use App\Enums\GeoPlaceFamily;
use App\Models\GeoPlaceType;
use Illuminate\Database\Seeder;

class GeoPlaceTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('atlas_poi.types', []) as $row) {
            if (! is_array($row) || ! isset($row['code'], $row['family'], $row['name'])) {
                continue;
            }

            $family = $row['family'] instanceof GeoPlaceFamily
                ? $row['family']
                : GeoPlaceFamily::from((string) $row['family']);

            GeoPlaceType::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'family' => $family,
                    'name' => $row['name'],
                    'singular_label' => $row['singular_label'] ?? null,
                    'plural_label' => $row['plural_label'] ?? null,
                    'icon_key' => $row['icon_key'] ?? null,
                    'default_visible' => $row['default_visible'] ?? true,
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'metadata' => $row['metadata'] ?? null,
                ],
            );
        }
    }
}
