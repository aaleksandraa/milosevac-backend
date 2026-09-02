<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_climate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('geo_settlements')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('data_sources')->restrictOnDelete();
            $table->foreignId('boundary_id')->nullable()->constrained('settlement_boundaries')->nullOnDelete();
            $table->date('normal_period_start');
            $table->date('normal_period_end');
            $table->date('historical_period_start')->nullable();
            $table->date('historical_period_end')->nullable();
            $table->string('spatial_method');
            $table->decimal('source_resolution_km', 6, 1);
            $table->unsignedSmallInteger('grid_cell_count')->default(0);
            $table->decimal('temperature_mean_annual_c', 6, 2)->nullable();
            $table->decimal('precipitation_annual_mm', 8, 1)->nullable();
            $table->unsignedTinyInteger('coldest_month')->nullable();
            $table->unsignedTinyInteger('warmest_month')->nullable();
            $table->unsignedTinyInteger('wettest_month')->nullable();
            $table->unsignedTinyInteger('driest_month')->nullable();
            $table->decimal('temperature_range_annual_c', 6, 2)->nullable();
            $table->decimal('temperature_mean_1961_1990_c', 6, 2)->nullable();
            $table->decimal('temperature_mean_change_c', 6, 2)->nullable();
            $table->json('indices')->nullable();
            $table->string('algorithm_version');
            $table->string('quality_flag');
            $table->timestamp('calculated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['settlement_id', 'source_id', 'algorithm_version', 'normal_period_start', 'normal_period_end'],
                'settlement_climate_profile_unique',
            );
            $table->index(['settlement_id', 'quality_flag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_climate_profiles');
    }
};
