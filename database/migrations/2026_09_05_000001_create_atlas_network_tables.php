<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['geo_transport_segments', 'geo_hydro_features'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->foreignId('source_id')->constrained('data_sources');
                $t->string('osm_type', 16);
                $t->unsignedBigInteger('osm_id');
                $t->string('feature_type', 40);
                $t->string('name')->nullable();
                $t->unsignedInteger('osm_version')->nullable();
                $t->timestamp('osm_timestamp')->nullable();
                $t->json('source_properties');
                $t->json('geometry');
                $t->string('dataset_version', 64);
                $t->timestamps();
                $t->unique(['source_id', 'osm_type', 'osm_id']);
                $t->index('feature_type');
            });
        }
        foreach (['settlement_transport_profiles', 'settlement_hydro_profiles'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->foreignId('settlement_id')->unique()->constrained('geo_settlements');
                $t->foreignId('boundary_id')->constrained('settlement_boundaries');
                $t->foreignId('source_id')->constrained('data_sources');
                $t->string('dataset_version', 64);
                $t->string('algorithm_version', 20);
                $t->string('dependency_hash', 64);
                $t->string('quality_flag', 40);
                $t->json('summary');
                $t->json('metadata');
                $t->timestamp('calculated_at');
                $t->timestamps();
            });
        }
        Schema::create('settlement_proximities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained('geo_settlements');
            $t->foreignId('source_id')->constrained('data_sources');
            $t->string('metric_code', 64);
            $t->string('origin_type', 40);
            $t->decimal('origin_lat', 10, 7);
            $t->decimal('origin_lng', 10, 7);
            $t->string('target_type', 30);
            $t->string('target_id')->nullable();
            $t->string('target_name')->nullable();
            $t->double('distance_straight_m')->nullable();
            $t->double('distance_route_m')->nullable();
            $t->double('travel_time_s')->nullable();
            $t->string('method', 64);
            $t->string('quality_flag', 40);
            $t->string('dataset_version', 64);
            $t->string('algorithm_version', 20);
            $t->string('dependency_hash', 64);
            $t->json('metadata');
            $t->timestamp('calculated_at');
            $t->timestamps();
            $t->unique(['settlement_id', 'metric_code']);
        });
        Schema::create('settlement_network_relations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained('geo_settlements');
            $t->string('kind', 10);
            $t->string('osm_type', 16);
            $t->unsignedBigInteger('osm_id');
            $t->string('relation', 30);
            $t->double('length_km');
            $t->double('area_km2');
            $t->unique(['settlement_id', 'kind', 'osm_type', 'osm_id'], 'settlement_network_identity');
        });
    }

    public function down(): void
    {
        foreach (['settlement_network_relations', 'settlement_proximities', 'settlement_hydro_profiles', 'settlement_transport_profiles', 'geo_hydro_features', 'geo_transport_segments'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
