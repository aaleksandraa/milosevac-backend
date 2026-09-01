<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_terrain_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('geo_settlements')->restrictOnDelete();
            $table->foreignId('boundary_id')->constrained('settlement_boundaries')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('data_sources')->restrictOnDelete();
            $table->decimal('elevation_min_m', 8, 1)->nullable();
            $table->decimal('elevation_max_m', 8, 1)->nullable();
            $table->decimal('elevation_mean_m', 8, 1)->nullable();
            $table->decimal('elevation_median_m', 8, 1)->nullable();
            $table->decimal('elevation_range_m', 8, 1)->nullable();
            $table->decimal('slope_mean_deg', 6, 2)->nullable();
            $table->decimal('slope_median_deg', 6, 2)->nullable();
            $table->decimal('slope_max_deg', 6, 2)->nullable();
            $table->decimal('elevation_min_lat', 10, 7)->nullable();
            $table->decimal('elevation_min_lng', 10, 7)->nullable();
            $table->decimal('elevation_max_lat', 10, 7)->nullable();
            $table->decimal('elevation_max_lng', 10, 7)->nullable();
            $table->string('dem_source');
            $table->string('dem_version');
            $table->unsignedSmallInteger('dem_resolution_m');
            $table->unsignedInteger('sample_count')->default(0);
            $table->unsignedInteger('valid_cell_count')->default(0);
            $table->unsignedInteger('nodata_cell_count')->default(0);
            $table->string('quality_flag');
            $table->string('algorithm_version');
            $table->timestamp('calculated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['settlement_id', 'boundary_id', 'dem_version', 'algorithm_version'],
                'settlement_terrain_unique_run',
            );
            $table->index(['settlement_id', 'quality_flag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_terrain_stats');
    }
};
