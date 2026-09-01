<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('geo_entities')->restrictOnDelete();
            $table->foreignId('canton_id')->nullable()->constrained('geo_cantons')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('admin_type');
            $table->string('official_code')->nullable();
            $table->string('statistical_code')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'admin_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_municipalities');
    }
};
