<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_boundaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('geo_settlements')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('data_sources')->restrictOnDelete();
            $table->longText('geom_geojson');
            $table->string('source_feature_id');
            $table->string('boundary_version')->default('unspecified');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->json('source_properties')->nullable();
            $table->decimal('bbox_min_lng', 10, 7);
            $table->decimal('bbox_min_lat', 10, 7);
            $table->decimal('bbox_max_lng', 10, 7);
            $table->decimal('bbox_max_lat', 10, 7);
            $table->boolean('geometry_valid')->default(false);
            $table->string('verification_status');
            $table->decimal('area_km2', 10, 4)->nullable();
            $table->string('area_algorithm')->nullable();
            $table->timestamp('area_calculated_at')->nullable();
            $table->string('geometry_fingerprint', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['settlement_id', 'source_id', 'boundary_version'], 'settlement_boundaries_settlement_source_version');
            $table->unique(['source_id', 'source_feature_id', 'boundary_version'], 'settlement_boundaries_feature_source_version');
            $table->index(['settlement_id', 'geometry_valid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_boundaries');
    }
};
