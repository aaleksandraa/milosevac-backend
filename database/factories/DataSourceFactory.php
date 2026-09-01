<?php

namespace Database\Factories;

use App\Enums\DataSourceClass;
use App\Models\DataSource;
use App\Support\Atlas\SourceAuthority;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    protected $model = DataSource::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'provider' => fake()->company(),
            'source_class' => DataSourceClass::Official,
            'authority_rank' => SourceAuthority::OFFICIAL_STATE,
            'source_type' => 'census',
            'url' => fake()->url(),
            'license' => null,
            'attribution' => null,
            'dataset_version' => '2013',
            'metadata' => null,
        ];
    }
}
