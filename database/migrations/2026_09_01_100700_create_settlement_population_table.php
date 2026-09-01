<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_population', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('geo_settlements')->restrictOnDelete();
            $table->unsignedSmallInteger('census_year');
            $table->unsignedInteger('population');
            $table->unsignedInteger('households')->nullable();
            $table->string('statistical_settlement_code')->nullable();
            $table->foreignId('source_id')->constrained('data_sources')->restrictOnDelete();
            $table->string('boundary_version')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['settlement_id', 'census_year', 'source_id'], 'settlement_population_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_population');
    }
};
