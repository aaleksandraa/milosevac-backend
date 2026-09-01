<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('geo_countries')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('kind');
            $table->timestamps();

            $table->unique(['country_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_entities');
    }
};
