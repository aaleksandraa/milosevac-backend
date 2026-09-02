<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_climate_extremes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('climate_profile_id')->constrained('settlement_climate_profiles')->cascadeOnDelete();
            $table->string('metric_code');
            $table->decimal('value', 10, 2);
            $table->string('unit');
            $table->date('occurred_on');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['climate_profile_id', 'metric_code', 'period_start', 'period_end'],
                'settlement_climate_extremes_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_climate_extremes');
    }
};
