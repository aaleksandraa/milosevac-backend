<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_census_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->nullable()->constrained('geo_settlements')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('data_sources')->restrictOnDelete();
            $table->unsignedSmallInteger('census_year');
            $table->string('historical_settlement_name');
            $table->string('historical_settlement_code')->nullable();
            $table->string('historical_municipality_name');
            $table->string('historical_municipality_code')->nullable();
            $table->string('mapping_status');
            $table->string('mapping_method');
            $table->string('comparability');
            $table->string('verification_status')->nullable();
            $table->text('notes')->nullable();
            $table->json('source_properties')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_id', 'census_year', 'historical_municipality_name', 'historical_settlement_name'],
                'scm_source_year_hist_unique',
            );
            $table->index(['settlement_id', 'census_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_census_mappings');
    }
};
