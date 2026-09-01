<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_type_id')->constrained('geo_place_types')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('geo_settlements')->nullOnDelete();
            $table->foreignId('municipality_id')->constrained('geo_municipalities')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('data_sources')->restrictOnDelete();

            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('name_cyrillic')->nullable();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->string('osm_type', 16)->nullable();
            $table->unsignedBigInteger('osm_id')->nullable();
            $table->unsignedInteger('osm_version')->nullable();
            $table->timestamp('osm_timestamp')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->string('operator')->nullable();
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('website', 512)->nullable();
            $table->string('opening_hours', 512)->nullable();

            $table->string('verification_status', 32);
            $table->string('classification_status', 32);
            $table->string('assignment_status', 32);
            $table->string('point_algorithm', 64)->nullable();
            $table->json('source_properties')->nullable();
            $table->json('metadata')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('source_deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'osm_type', 'osm_id'], 'geo_places_source_osm_unique');
            $table->index(['municipality_id', 'is_active']);
            $table->index(['settlement_id', 'is_active']);
            $table->index(['place_type_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_places');
    }
};
