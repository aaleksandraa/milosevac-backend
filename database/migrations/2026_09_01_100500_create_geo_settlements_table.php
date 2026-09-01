<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('municipality_id')->constrained('geo_municipalities')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('name_cyrillic')->nullable();
            $table->string('official_code')->nullable();
            $table->string('statistical_code')->nullable();
            $table->unsignedBigInteger('osm_relation_id')->nullable();
            $table->string('wikidata_id')->nullable();
            $table->unsignedBigInteger('geonames_id')->nullable();
            $table->foreignId('outage_place_id')->nullable()->constrained('places')->nullOnDelete();
            $table->decimal('geographic_centroid_lat', 10, 7)->nullable();
            $table->decimal('geographic_centroid_lng', 10, 7)->nullable();
            $table->decimal('label_point_lat', 10, 7)->nullable();
            $table->decimal('label_point_lng', 10, 7)->nullable();
            $table->foreignId('label_point_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->string('label_point_verification_status')->nullable();
            $table->timestamps();

            $table->unique(['municipality_id', 'slug']);
            $table->unique('osm_relation_id');
            $table->unique('wikidata_id');
            $table->unique('geonames_id');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_settlements');
    }
};
