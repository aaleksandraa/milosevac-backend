<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_surface_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('geo_settlements')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('data_sources')->restrictOnDelete();
            $table->foreignId('boundary_id')->nullable()->constrained('settlement_boundaries')->nullOnDelete();
            $table->string('product_code');
            $table->unsignedSmallInteger('reference_year');
            $table->string('algorithm_version');
            $table->string('processing_method');
            $table->unsignedSmallInteger('source_resolution_m')->nullable();
            $table->decimal('total_analysis_area_km2', 10, 4)->nullable();
            $table->decimal('valid_area_km2', 10, 4)->nullable();
            $table->decimal('nodata_area_km2', 10, 4)->nullable();
            $table->string('quality_flag');
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['settlement_id', 'source_id', 'product_code', 'algorithm_version'],
                'settlement_surface_profile_unique',
            );
            $table->index(['settlement_id', 'product_code', 'quality_flag'], 'settlement_surface_lookup');
        });

        Schema::create('settlement_land_cover_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('surface_profile_id')->constrained('settlement_surface_profiles')->cascadeOnDelete();
            $table->string('class_code');
            $table->string('source_class_code');
            $table->string('source_class_label');
            $table->string('atlas_group')->nullable();
            $table->decimal('area_km2', 10, 6);
            $table->decimal('share_pct', 8, 4);
            $table->unsignedInteger('pixel_count')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['surface_profile_id', 'class_code'], 'settlement_land_cover_class_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_land_cover_classes');
        Schema::dropIfExists('settlement_surface_profiles');
    }
};
