<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_cantons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('geo_entities')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('official_code')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_cantons');
    }
};
