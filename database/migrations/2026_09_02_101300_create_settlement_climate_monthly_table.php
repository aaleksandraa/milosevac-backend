<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_climate_monthly', function (Blueprint $table) {
            $table->id();
            $table->foreignId('climate_profile_id')->constrained('settlement_climate_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');
            $table->decimal('temperature_mean_c', 6, 2)->nullable();
            $table->decimal('temperature_mean_min_c', 6, 2)->nullable();
            $table->decimal('temperature_mean_max_c', 6, 2)->nullable();
            $table->decimal('precipitation_mean_mm', 8, 1)->nullable();
            $table->timestamps();

            $table->unique(['climate_profile_id', 'month'], 'settlement_climate_monthly_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_climate_monthly');
    }
};
